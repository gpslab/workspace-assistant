---
tags: [memory/repo, internals]
---

# Internals — Workspace Assistant

How the bot is built and how a message travels through it. Traps are in [gotchas.md](gotchas.md),
epics are in [../EPICS.md](../EPICS.md).

---

## Stack and invariants

| Parameter      | Value                                                                        |
|----------------|------------------------------------------------------------------------------|
| Language       | PHP `>=8.4`; CI runs 8.4 and 8.5, production runs 8.5                        |
| Extensions     | `ext-curl`, `ext-pdo`, `ext-pdo_sqlite`, `pcntl` (signals)                   |
| Dependencies   | runtime: **none**; dev: php-cs-fixer, phpstan, phpunit                       |
| Autoloading    | own PSR-4 in `bootstrap.php` (`GpsLab\WorkspaceAssistant\`), not `vendor/`   |
| Time zone      | `bootstrap.php` sets UTC; `TIMEZONE` (default UTC) is applied by `bin/bot.php` and, for the review Stop hook, by `Timezone::applyFromEnvFile` |
| Style          | `@Symfony` without yoda; `declare(strict_types=1)` required                  |
| Static analysis| phpstan level 8 on `src`, `bin`, `tests`                                     |
| Tests          | phpunit; real git for `GitManager`, fake `claude` scripts for the flow tests |

Components are wired top-down: `Bot` knows everyone, the lower classes are standalone, the dependency
graph is acyclic. `bin/bot.php` builds the base services (`Database`, `TelegramClient`, `GitManager`,
`SessionFiles`, `GhClient`) and passes them to `Bot`; `Bot`'s constructor builds the other collaborators and
injects them through constructors.

---

## Message flow

```
Telegram getUpdates (long poll)
        │
        ▼
   Bot::dispatch ──► whitelist (ALLOWED_USER_IDS)? ──no──► access denied
        │ yes
        ├─ "/..." ──► handleCommand → Command\Router (/new /compact /cancel /queue /stats /verbose /mode /lang /epic /restart /help)
        │
        └─ text or files ──► enqueue (FIFO in SQLite) ──► QueueScheduler::startQueueHead (each tick, when no job is active)
                                │
                                ├─ dirty tree? ──► pause the queue, offer commit / discard (AUTO_COMMIT=1 only)
                                ├─ git pull --rebase (+ sub-repositories) ──fail──► retry in 60 s (AUTO_COMMIT=1; with 0 only sub-repositories)
                                ├─ download incoming files
                                └─ ClaudeRunner::start (claude -p --resume <session>)
                                         │
            ┌── the loop keeps polling Telegram (so /cancel works) ──┐
            ▼                                                        │
   JobRunner::poll (every tick) ──► RunnerState                         │
        ├─ Running ──► keep waiting ─────────────────────────────────┘
        ├─ Timeout ──► kill, "process killed", offer a decision if the tree is dirty (AUTO_COMMIT=1 only)
        └─ Done    ──► result()
                         ├─ recordRun (session_id, tokens, counter)
                         ├─ error ──► "Claude error", offer a decision if dirty (AUTO_COMMIT=1 only)
                         └─ ok ──► `RunResult::sendResult`: commit + push (AUTO_COMMIT=1), commit link, optional token usage, reply
```

The key idea: **Claude runs asynchronously** and the loop keeps polling Telegram with a zero timeout,
otherwise `/cancel` could not work while Claude thinks.

---

## `Bot` — the main loop

`run()`:

1. `db->clearStalePids()` — PIDs of a previous run are meaningless.
2. `tg->setMyCommands(...)` for the default locale and every built-in locale.
3. `pcntl_async_signals(true)` + a `SIGTERM` handler sets `running = false` (graceful shutdown: the
   iteration finishes, then `RunState::$activeJob?->kill()`).
4. `while ($running) { tick(); }` inside `try/catch`: one failure does not kill the bot (log + `sleep(1)`).
5. After the loop, a best-effort `getUpdates($offset, 0)` acknowledges the offset ([gotchas.md](gotchas.md)).

`tick()`: the long-poll timeout is `0` when there is something to do without waiting (active job,
immature album, queue head ready to start), otherwise `POLL_TIMEOUT`; it fetches updates, advances
`offset`, dispatches, flushes mature albums, polls the job, starts the queue head, runs the
`Orchestrator`. A zero timeout is followed by `usleep(200 ms)`.

`dispatch()`: `callback_query` → `handleCallback` (access guard, then the first `Callback\Handler` that supports the data); the user id is checked against the whitelist; `/...`
→ command, anything else → the queue.

### `QueueScheduler` — the request queue

`Queue\QueueScheduler` owns the queue (`enqueueMessage`, `enqueueFiles`, `enqueueEpicRun`, `startQueueHead`,
retries, `recoverQueueOnStart`) and the pauses (Claude limit, dirty tree); it implements `QueueControl`. It starts
a run through the `JobLauncher` port (`JobRunner`) and gets the epic run prompt from
`Epic\RunPrompt`. `Bot` keeps one-line delegates and asks it for the poll timer (`queueBlockedUntil`, `isTreePaused`).

### Releasing a job (critical order)

In `JobRunner::poll()` on `Done` (it returns a `RunOutcome`, which `Bot::pollJob` routes to `RunResult::sendResult`, `Epic\RunLifecycle` or `CompactFlow`, and then removes the queue item unless the run was put back at the head or is still alive):

```
result = runState->activeJob->result();   // valid ONLY here, before the reset
finishJobState();               // release the job BEFORE delivering to Telegram
deleteMessage("Thinking…");
recordRun(...);                 // if there is a session_id
RunResult::sendResult(...) | "Claude error"
```

### Commit / discard decision

With `AUTO_COMMIT=1` (the default), after `/cancel`, a timeout or a Claude error the tree may stay dirty. `Callback\DirtyTreeDecision::offerDecisionIfDirty()` stores the decision and
sends the inline buttons "Commit / Discard"; the queue is paused while the tree is dirty.

- **commit** → `git add -A && commit && push`, commit link;
- **discard** → `git reset --hard && git clean -fd` (also removes untracked files).

After a successful run (`Done`, no error) the commit is **automatic** in `RunResult::sendResult`; no buttons.

With `AUTO_COMMIT=0` none of this happens: there is no decision, no buttons, no pause of the queue and
no commit in `RunResult::sendResult`; changes stay in the working tree.

---

## `ClaudeRunner` — asynchronous claude

```php
$argv = [$claudeBin, '-p', $prompt, '--output-format', 'stream-json', '--verbose'];
// + --resume <session> when a session exists (on a chat resume with a new prompt hash, -p starts with the
//   prompts/session_update block; -p of every run goes through neutralizeUpdateMarkers()), + CLAUDE_EXTRA_ARGS,
// + --append-system-prompt <contract + persona [+ CLAUDE_APPEND_SYSTEM_PROMPT] + language line>,
// + --settings <json>: in review mode the review Stop hook,
//   `bin/review-stop-hook.php <STATE_DIR> [--forge=<FORGE>]` (built by ReviewStatusHook::settingsJson(),
//   embedded by PmGateHook::settingsJson() in review mode; only the Stop hook takes `--forge`, it selects
//   the reason text: github, or gitlab); in pm/review modes and in the epic runs
//   (tick, final, review) a PreToolUse hook on Bash, `bin/pm-gate-hook.php <STATE_DIR> <kind> <run id>`,
//   where <kind> is the run's `skip_allowed` key: pm, review, tick, final, epic_review
//   (PmRunbook::skipKeyForMode / skipKeyForEpicRun); review mode gets both hooks;
//   in review mode and the epic review run a second PreToolUse hook on Bash,
//   `bin/review-commit-hook.php <STATE_DIR>` (ReviewCommitHook): the review goes out only as
//   `gh api …/pulls/N/reviews -f commit_id=<SHA> …`, the SHA from the file named by
//   WORKSPACE_ASSISTANT_REVIEW_SHAS (`STATE_DIR/review-shas/<run id>.json`, `owner/repo#N` => SHA);
//   on GitLab (whatever FORGE is) the SHA is a literal lowercase 40-hex: in the verdict marker
//   `<!-- verdict:approve|changes sha=<SHA> -->` (one grammar, ReviewStatusHook::GITLAB_MARKER_RE, shared
//   by both hooks), in `glab mr approve -s|--sha <SHA>` and in the `sha` of a `glab api` POST to
//   `projects/<id>/merge_requests/<N>/approve` (the MR number may be any expression, the `sha` check still
//   applies; an unquoted `$(…)` in a `glab api` command containing `merge_requests` is always denied, and a regex
//   error fails the hook; not recognised: quoted or escaped body flags, quotes inside the approve path,
//   a `merge_requests` word built from substitutions); a `glab mr note` with a marker has exactly one `-m` flag
//   (glab publishes the last one; the Stop hook joins all) and its body is `-m "…"` or
//   `-m "$(cat <<'EOF' … EOF)"`, never a heredoc outside the `-m` value
// + --agents <STATE_DIR/agents/<id>.json> (built-in sub-agents; absent in both /compact phases
//   and when CLAUDE_EXTRA_ARGS already contains --agents)
proc_open($argv, $descriptors, $pipes, $repoPath, $env);
```

- Descriptors: stdin is a pipe, closed at once after start. stdout and stderr are run files
  `<STATE_DIR>/runs/<id>.out` and `.err` (files 0600, the directory is created with 0700). Without
  `stateDir` (the bot always passes `STATE_DIR`) the runner uses its own temporary directory
  `claude-run-*` in the system tmp, removed together with the files. Files, not pipes: a pipe holds
  64 KiB, and when the main loop is busy the claude CLI loses what did not fit at exit; a file never blocks.
  Output is JSON Lines: `assistant` events give live progress (`tool_use`), the `result` event becomes
  the `ClaudeResult` input.
- `poll()` (every tick): `drain()` reads both files from the remembered offset (`readNew()`) and
  refreshes `lastProgressAt` on any new byte; overall timeout `CLAUDE_TIMEOUT` (off when `0`); stall
  timeout `CLAUDE_STALL_TIMEOUT`; still running → `Running`; exited → parse → `Done`. After a natural
  exit with an empty stdout, `poll()` keeps returning `Running` and re-reading the files until
  `EOF_WAIT_SEC` (5 s from the first `poll()` that saw the exit) passes: a descendant may still append
  the result.
- `kill()`: `SIGTERM`, wait 300 ms, `SIGKILL`, then close the process and delete the run files
  (`closeProc()` → `removeRunFiles()`). The files are deleted after `Done` too. The agents file
  (`<STATE_DIR>/agents/<id>.json`, 0600, the same run id) is deleted together with the run files.
- Bot start: `Bot::run()` calls `ClaudeRunner::purgeRunFiles()`, which deletes the leftover `runs/`
  files of previous runs and the leftover `agents/` files. The file name carries the PID of the bot instance that created the run (not
  the claude PID); a file whose PID is alive is kept.
- The working directory is the managed repository.

`ClaudeResult::fromJson`: not a JSON object → the process died before a result (`isError`, text =
stderr | stdout | "exit code N"); otherwise `input_tokens` (input + cache read + cache creation),
`output_tokens`, `session_id` (empty → `null`), `is_error`, `result`.

---

## System prompt

`SystemPrompt::build($mode, $promptsDir)` returns the text passed by `ClaudeRunner` through one
`--append-system-prompt`, in this order:

1. **Contract** (`prompts/common.md.dist`; a local `prompts/common.md` overrides it and is reported in the
   reply): the identity line, delivery rules ("the user sees only your
   final text"), the self-preservation rule (never stop or restart the bot process).
2. For `review` and `pm`: the response-size goal (`prompts/common_limits.md.dist`, not in `arch`).
3. For `review`: the PR review contract (`prompts/contract_review.md.dist`: end in Approve / Request changes, one line
   per cold-finding id, chat verdict of at most three lines starting with 🟢 or 🔴).
4. **Persona**: the common block (`PROMPTS_DIR/common.md` → `prompts/persona_common.md` → `.dist`), then
   the role block of the mode (`PROMPTS_DIR/<mode>.md` → `prompts/role_<mode>.md` → `.dist`).
5. Optional `CLAUDE_APPEND_SYSTEM_PROMPT`, then the chat-language line (`languageLine`), always last.

All texts are loaded by `PromptFiles` (`prompts/<name>.md`, else `prompts/<name>.md.dist`) on every run;
`PROMPTS_DIR` files (relative to `REPO_PATH`) sit on top for the persona. A missing or empty persona file
falls back to the next layer, and the warning names the file actually used (always in `bot.log`, in the
chat with the reply). A local file for a contract name (`PromptFiles::CONTRACT`) adds a warning on every
run. A missing `.dist` is a broken installation: the run fails with an error naming the file, the bot loop
goes on. `epic.md` in `PROMPTS_DIR` is an addition to the epic runbook, inserted into the epic run message by
`Epic\Run::prompt()`, not a persona. The user reference is [PROMPTS.md](../PROMPTS.md).

---

## `TelegramClient` — Bot API over curl

- `call(method, params)`: a `CURLFile` means multipart, arrays (`reply_markup`) become JSON; `ok` is
  checked; a boolean `result` is normalized to `[]`. Timeouts are per call: sending 30 s, `getUpdates`
  `POLL_TIMEOUT + 20`, `sendDocument` 120 s, connect 10 s.
- `sendText`: converts Markdown → HTML; a reply with a Markdown table is sent as a `.md` file regardless
  of length (`tg.table_caption`), otherwise if `mb_strlen(html) > 4096` the original Markdown goes as a
  `.md` file (`$overflowCaption` or `tg.full_caption`); both branches return `0` and drop `replyMarkup`.
- Failures are typed (`src/Exception/`): API refusal, transport error, timeout.
- `getUpdates` requests only `message` and `callback_query`.

## `MarkdownConverter`

Strict order: (1) code blocks → `<pre><code>` behind placeholders; (2) tables (≥2 `|` rows) → `<pre>`;
(3) inline code; (4) `htmlspecialchars` on the rest; (5) markup on the escaped text (`**b**`, `*i*`,
`~~s~~`, links, `#` headings); (6) placeholders restored. The regex parser is approximate: `*`/`_` in
plain prose can yield a false `<i>`; nesting and exotic markdown are unsupported by design.

## `GitManager`

`git(args, timeout)` is the core: `proc_open(['git', ...$args], …, $repoPath, gitEnv())`, closed stdin,
non-blocking reads with a deadline; on timeout SIGTERM → 300 ms → SIGKILL, `exit=124`.
`gitEnv()` inherits the whole environment and forces non-interactivity (`GIT_TERMINAL_PROMPT=0`,
`GIT_SSH_COMMAND` with `BatchMode=yes`, host-key acceptance, `ConnectTimeout=10`, multiplexing).

| Method           | What it does                                                       | Timeout            |
|------------------|--------------------------------------------------------------------|--------------------|
| `isClean`        | `status --porcelain` is empty?                                     | local (30 s)       |
| `pullRebase`     | `pull --rebase`; on failure `rebase --abort`, returns false        | network (`GIT_TIMEOUT`) |
| `changesSummary` | `status --short` + `diff --stat HEAD`                              | local              |
| `commitAndPush`  | `add -A` → `commit -m` → `push`; returns the HEAD hash (not called with `AUTO_COMMIT=0`) | commit local, push network |
| `discardChanges` | `reset --hard` + `clean -fd` (removes untracked files too)         | local              |

## `Database` — SQLite

PDO with `ERRMODE_EXCEPTION`, prepared statements only. Tables: `sessions` (key `(chat_id, thread_id)`;
`session_id`, `pid`, `started_at`, `message_count`, token sums, `verbose`, `mode`, `prompt_hash`), `queue` and
`queue_messages` (the request queue), `kv` (decision, owner of a dirty tree, timers, per-user language),
epic tables. `migrate()` is idempotent and rebuilds old schemas. No concurrency: one process, sequential jobs.

## `Config` / `Env`

`Env::load` parses `KEY=VALUE` lines (`#` comments, quotes stripped). `Env::get`: a real environment
variable (`getenv`) **overrides** the file. `require` fails on an empty value; `list` splits on commas
and drops empties. `Config::load` validates early (token is not the placeholder, whitelist non-empty,
`REPO_PATH/.git` exists, `STATE_DIR` created) and returns an immutable object.

## `Log`

`fwrite(STDERR, "[Y-m-d H:i:sP] [workspace-assistant] …")`; the offset makes the line unambiguous
whatever `date.timezone` is.

---

## Configuration (`.env`)

Authoritative list with comments: `.env.dist`. Main variables:

| Variable                | Purpose                                                        | Default                          |
|-------------------------|----------------------------------------------------------------|----------------------------------|
| `BOT_TOKEN`             | token from @BotFather                                          | required                         |
| `ALLOWED_USER_IDS`      | whitelist of Telegram user ids, comma-separated                | required, non-empty              |
| `REPO_PATH`             | absolute path of the managed repository                        | required, must be a git repo     |
| `FORGE_REPO`            | `owner/name` for commit links (alias `GITHUB_REPO`)             | empty                            |
| `FORGE`                 | `github` or `gitlab` (other values fail startup); on `gitlab` the orchestrator is not wired and `/epic` answers "not available" | `github` |
| `FORGE_HOST`            | Forge host | `github.com` / `gitlab.com` |
| `FORGE_OWNERS`          | Owners (alias `GITHUB_OWNERS`; the new key wins, a warning is logged when both are set); the first is the default for `repo#N`; namespace paths allowed with `FORGE=gitlab`; epics and PR links in `review` need it | empty |
| `OPENRONIN_LOGINS`      | logins allowed to author the `<!-- openronin:bot -->` marker   | empty (any author + warning)     |
| `CLAUDE_BIN`            | path to the claude binary                                      | `claude`                         |
| `CLAUDE_TIMEOUT`        | overall call timeout, seconds (`0` = off)                      | `0`                              |
| `CLAUDE_STALL_TIMEOUT`  | no output for N seconds → hung, kill (`0` = off)               | `900`                            |
| `CLAUDE_EXTRA_ARGS`     | extra claude arguments, comma-separated                        | `--dangerously-skip-permissions` |
| `CLAUDE_APPEND_SYSTEM_PROMPT` | text appended to the system prompt (not split on commas) | empty                            |
| `PROMPTS_DIR`           | persona files, relative to `REPO_PATH`                         | empty (texts from `prompts/`)    |
| `STATE_DIR`             | SQLite, outbox, inbox, epic signals, log, claude `runs/`       | `./state`                        |
| `POLL_TIMEOUT`          | idle long-poll timeout, seconds                                | `30`                             |
| `GIT_TIMEOUT`           | network git timeout, seconds                                   | `120`                            |
| `AUTO_COMMIT`           | `1` pull/commit/push around runs; `0` the bot does not touch git in the managed repository | `1`                              |
| `SUBREPOS_DIR`          | directory of clones inside the managed repository              | empty (off)                      |
| `TIMEZONE`              | PHP time zone for logs and dates                               | `UTC`                            |
| `BOT_LOCALE` / `LOCALES_DIR` | interface language / extra locale files (relative to `REPO_PATH`) | `en` / empty             |
| `EPICS_DIR`, `EPIC_*`   | epic plans directory and orchestrator thresholds ([../EPICS.md](../EPICS.md)) | `.workspace-assistant/epics` |

Real environment variables (`export`) override `.env`.

---

## Concurrency and timeouts

The bot is **single-threaded**, one job at a time. Every external call has a timeout and non-blocking
reads:

| Layer                | Mechanism                                  | Parameter                      |
|----------------------|--------------------------------------------|--------------------------------|
| Telegram `getUpdates`| `POLL_TIMEOUT` (0 with work pending) + 20 s| `POLL_TIMEOUT` (30)            |
| claude, overall      | deadline from `startedAt`                  | `CLAUDE_TIMEOUT` (0 = off)     |
| claude, stall        | from `lastProgressAt`                      | `CLAUDE_STALL_TIMEOUT` (900)   |
| git, local           | 30 s deadline                              | `LOCAL_TIMEOUT`                |
| git, network         | deadline                                   | `GIT_TIMEOUT` (120)            |

A new external call **must** be wrapped in a timeout, otherwise the class of hangs the hardening
removed comes back.

---

## Epics in one paragraph

An epic is an author-approved set of GitHub tasks. The `Orchestrator` polls GitHub every `EPIC_POLL_SECONDS` seconds after the previous pass ends,
queues an isolated `claude -p` run (`kind = epic`, no `--resume`, a signal file in
`WORKSPACE_ASSISTANT_EPIC_SIGNAL`) only when judgement is needed, and performs the deterministic steps
(labels, merges, releases) itself under `Epic\Gates`/`Epic\MergeGate`. Judgement calls follow
`prompts/epic_runbook.md.dist`. Everything else is in [../EPICS.md](../EPICS.md).

## Tests and CI

`composer test` (phpunit), `composer stan`, `composer cs:check`. CI (`.github/workflows/ci.yml`) runs
`composer validate --strict` and a QA matrix of those three on PHP 8.4 and 8.5 for pushes to `master` and PRs; the 8.5 job runs
`composer test:coverage` (pcov), writes the coverage summary to the job summary and uploads
`coverage.xml` as the `coverage` artifact.
The flow tests (`QueueFlowTest`, `Epic\FlowTest`) drive `Bot` with fake `claude` shell scripts.
