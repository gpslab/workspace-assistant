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
        ├─ "/..." ──► handleCommand → Command\Router (/new /compact /cancel /queue /stats /verbose /mode /model /lang /epic /restart /help)
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
                         ├─ recordRun (session_id, usage snapshot, counter)
                         ├─ error ──► "engine error", offer a decision if dirty (AUTO_COMMIT=1 only)
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
`offset`, dispatches, flushes mature albums, runs the bounded standalone coder intake pass on its
schedule, polls the job, starts the queue head, and runs the `Orchestrator`. A zero timeout is
followed by `usleep(200 ms)`. Intake has an empty repository allowlist by default and starts no
worker; see [coder.md](coder.md).

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
RunResult::sendResult(...) | "engine error"
```

### Commit / discard decision

With `AUTO_COMMIT=1` (the default), after `/cancel`, a timeout or an engine error the tree may stay dirty. `Callback\DirtyTreeDecision::offerDecisionIfDirty()` stores the decision and
sends the inline buttons "Commit / Discard"; the queue is paused while the tree is dirty.

`GitManager::commitAndPush()` is a commit gate: after `add -A` it scans the added lines of the staged diff and of
`git log -p HEAD --not --remotes`; on a finding it resets the index and throws `SecretFoundException`. `RunResult`,
`Epic\RunLifecycle` and `DirtyTreeDecision` catch it and call `SecretBlocker::block()`, which stores the
"blocked by secret" state (`kv` key `secret_block`) and sends the findings with a Discard-only button. While the state
is set a dirty tree does not pause the queue, the next chat run's prompt gets `secret_blocked`, and the callers run
the gate even on a clean tree. A successful commit, a discard or a failed epic run clears it.

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
  the `RunAnswer` input.
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
- Failed-run evidence: a run whose `RunAnswer` is an error writes the last 256 KiB of the event
  stream and the last 16 KiB of stderr, both through `SecretScanner`, into
  `<STATE_DIR>/failed-runs/<run id>.log` (`FailedRunEvidence`; directory 0700, file 0600) before
  the run files are deleted, and logs the reason (exit code and last error, masked). The stream
  tail is read from the end of the `.out` file (`fseek`), so a long failed run never loads the
  whole file into memory. The directory is outside `runs/` and `agents/`, so `purgeRunFiles()`
  leaves it alone. On every save the store prunes evidence older than `FAILED_RUN_EVIDENCE_DAYS`
  (0 = no age limit) and everything beyond the newest 20 files.
- The working directory is the managed repository.

`RunAnswer::fromJson`: not a JSON object → the process died before a result (`isError`, text =
stderr | stdout | "exit code N"); otherwise `totals` (a `UsageTotals`: `modelUsage` summed over models — input, cache creation, cache read,
output — and `total_cost_usd`; `null` without `modelUsage`), `session_id` (empty → `null`), `is_error`,
`result`. `ClaudeRunner` replaces `totals` with those of the last `result` event of the process.

---

## `OpencodeRunner` — asynchronous opencode

The other engine (`ENGINE=opencode`): a second implementation of the same `AgentRunner` contract, so
`JobRunner` and `QueueScheduler` stay engine-neutral. `RunnerFactory` builds it from
`OPENCODE_BIN` / `OPENCODE_MODEL` / `OPENCODE_EXTRA_ARGS`; the `$model` of a job is a Claude Code alias and
is deliberately dropped. `Config::engineFingerprint()` is `opencode:<OPENCODE_MODEL>` or `opencode:default`:
a change of the model (or of the engine) resets the session and adds the usual notice.

- argv: `[OPENCODE_BIN, run, --format, json, --standalone, --auto, (--session <id>), (--model <m>),
  ...OPENCODE_EXTRA_ARGS, --, <prompt>]`. The prompt is the last argument, after the end-of-options marker,
  so a message starting with `-` is never parsed as a flag.
- The system prompt is a file, not argv: the per-run directory `<STATE_DIR>/opencode-<runId>/` (0700, files
  0600) holds `system-prompt.md`, `opencode.json` (its `instructions` list, plus the repository `CLAUDE.md`
  when there is no `AGENTS.md`), `explicit.json` (agent permissions, and the plugin every run lists) and
  `agents/<name>.md` — the built-in sub-agents plus the agents the instance defines itself
  (`InstanceAgents`; OpenCode loads no agent directory on its own). The run
  environment adds `OPENCODE_DISABLE_PROJECT_CONFIG=1`, `OPENCODE_CONFIG_DIR=<dir>` and
  `OPENCODE_CONFIG=<dir>/explicit.json`, so a project `opencode.json` of the managed repository cannot
  override the Bot's config. `XDG_CONFIG_HOME` is left alone on purpose: it would also move the configs of
  `gh` / `glab`. Redirection of the config differs from a resume: OpenCode applies a changed `instructions`
  file itself, so no `session_update` block is sent.
- Run files and lifecycle are the same as for claude: stdout/stderr go to `<STATE_DIR>/runs/<id>.out` and
  `.err`, `poll()` / `kill()` behave the same, and `OpencodeNdjsonParser` turns the NDJSON into progress
  lines, the session id (`sessionID`) and the `RunAnswer` (totals from `step_finish`). `actualModel()` is
  always `null`: OpenCode reports no model. A failed run leaves the same masked evidence in
  `<STATE_DIR>/failed-runs/` through `FailedRunEvidence`.
- The stall timer is suspended after the first sub-agent of a run: OpenCode runs every sub-agent in the
  background and stdout stays silent while it works, so silence stops meaning "hung".
- `Bot::run()` calls `OpencodeRunner::purgeRunDirs()` next to `ClaudeRunner::purgeRunFiles()`; a run
  directory whose bot PID is still alive is kept.
- Gates and the guard go through a plugin: OpenCode has no command hooks, so `explicit.json` of every run
  lists `opencode-plugin/` in `plugins` (`OpencodeRunner::pluginDir()`; the renderer does not tell the
  modes apart) and the environment of a gated run carries the gate contract
  (`WORKSPACE_ASSISTANT_GATE_STATE_DIR`, `_KIND`, `_RUN_ID`, `_PHP`, `PmGateHook::SKIP_ENV`,
  `ReviewCommitHook::ENABLED_ENV` and `_SHAS`), which `JobRunner` sets for every gated run. The plugin's
  `evaluate` hook starts `bin/secret-guard-hook.php` on every run, with the command alone — the secret
  guard of `src/SecretGuard.php`, which denies a command that carries a secret and lets every ordinary one
  through. The gate kind `PmGateHook::KIND_ENV` then decides what follows: with it the plugin starts the
  gate hooks (`bin/pm-gate-hook.php`, `bin/review-commit-hook.php`) with the gate values, without it the
  run has no gate to run and the guard runs alone. The guard goes first, so its deny is final and the PM
  gate records no skip for a command it denied. Both modes stay single-source in PHP (`SecretScanner` is
  the detector of the guard, as of the masking and the commit gate), and both are fail-closed: a hook that
  fails denies the call. A run that carries a gate variable (state directory, run id, review flag) but no
  gate kind is a broken wiring and is denied, not guarded.
- The review Stop hook has no OpenCode equivalent: no Stop hooks at all. `JobRunner::repeatReviewRun()`
  makes the same decision after the run (see [gotchas.md](gotchas.md)).
- Epics run here too: `Bot` wires the `Orchestrator`, `ChatSurface::cmdEpic()` and `startEpicItem()` have no
  OpenCode branch, and `Run::modeFor()` gives an epic run the `pm` / `review` mode with the gate kind of its
  run kind (`tick`, `final`, `epic_review`), so the shim of the plugin bullet above gates it like any other
  run. `ModelChoice::isPinned()` is always true, so an epic run takes `OPENCODE_MODEL` and `MODEL_EPIC_*` is
  never applied — `Config` names a set key in one startup warning.
- The memory of the instance is injected here, because this engine reads no user scope (see [Memory](#memory)).

---

## `KimiRunner` — asynchronous kimi

The third engine (`ENGINE=kimi`): the same `AgentRunner` contract again, so `JobRunner` and `QueueScheduler`
stay engine-neutral. `RunnerFactory` builds it from `KIMI_BIN` / `KIMI_MODEL` / `KIMI_HOME` /
`KIMI_EXTRA_ARGS`; the `$model` of a job is a Claude Code alias and is deliberately dropped, as on OpenCode.
`Config::engineFingerprint()` is `kimi:<KIMI_MODEL>` or `kimi:default`, so a change of the model (or of the
engine) resets the session with the usual notice; a set `CLAUDE_MODELS` or `MODEL_EPIC_*` is named in one
startup log line, since neither applies here.

- argv: `[KIMI_BIN, -p, <prompt>, --output-format, stream-json, (-S <id> | --agent-file <run home>/agent.md),
  (-m <model>), ...KIMI_EXTRA_ARGS]`. `--agent-file` is never combined with `-S`: Kimi rejects the pair and a
  resumed session keeps the prompt it was created with, so a changed prompt reaches it as the
  instructions-update block of the message — which is why `updateOnResume` is wired here and not on OpenCode.
- Run home: `<STATE_DIR>/kimi-<runId>/` (0700) passed as `KIMI_CODE_HOME`, holding the rendered `config.toml`
  (`KimiConfigRenderer`: the user's `[[hooks]]` and `extra_agent_dirs` / `extra_skill_dirs` are dropped, the
  `[[permission.rules]]` and the model tables are kept, the bot's own hook tables and its agents directory
  are added, and the cron and question tools are disabled), `agent.md` (the assembled prompt, the file
  `--agent-file` names), `agents/` (`KimiAgentsRenderer`) and `hook-records.jsonl` (the gate shim's record of
  every hook call). The `credentials/`, `sessions/` and `oauth/` entries are **symlinks** into the source home
  (`KIMI_HOME`, default `~/.kimi-code`), and `region` / `device_id` are copied: the credentials themselves are
  never copied, because the server rotates the refresh token and a copy would be invalidated by the next
  refresh. `KIMI_CODE_NO_AUTO_UPDATE=1` keeps the engine from replacing itself inside a run. The home is not
  removed when the process ends — `JobRunner` calls `purgeRunHome()` after its post-run checks, because the
  gate records live there — and `Bot::run()` sweeps the homes of dead PIDs next to
  `OpencodeRunner::purgeRunDirs()`.
- stdout carries no usage, no model and no error: `KimiStreamParser` turns it into progress lines, the
  session id (`session.resume_hint`, printed only on success) and the `RunAnswer`. After the run the runner
  reads the window of the session's `wire.jsonl` starting at the size taken at launch and classifies a
  failure with `KimiErrorClassifier`, and `KimiWire` gives the run its `usage.record` totals. The stall
  heartbeat counts stdout bytes **or** the growth of that wire file: stdout is quiet while a background
  sub-agent works.
- Gates: the shim is rendered into the run home, and Kimi lets a call pass when a hook is killed, times out
  or is missing (only exit code 2 blocks), so a gated run is verified **after the fact**. `JobRunner` runs
  the shim's self-test before a gated run and afterwards compares `hook-records.jsonl` with the run window of
  `wire.jsonl` (`KimiRunAudit`). The audit runs after a run that did not end in an error only: a run that
  ended in an error is not audited and leaves no line. A `kimi gate audit skipped: …` line in bot.log and no
  audit come from a run that was killed by a timeout, was cancelled, was still in progress when the bot
  stopped, or ended but whose audit could not be taken. A mismatch on a chat run is a
  `kimi gate audit: …` line in bot.log and goes no further; on a gated run it is a `kimi gate violation: …`
  line in bot.log, the same text in the chat and no success status for the run. An audit taken after the run,
  not a hook that blocks a call, is the only protection a gated run has — which is why `KimiGateGuard` refuses
  the gated modes (`pm`, `review`) and the epics unless the gate preconditions hold.
- Review status: Kimi's `Stop` hook cannot continue a `-p` turn, so `JobRunner::repeatReviewRun()` takes the
  decision after the run, exactly as on OpenCode.
- `$HOME/.agents` (agents, skills and `AGENTS.md`) is read by every run, whatever `KIMI_CODE_HOME` says.
  `KimiGatedPreconditions::homeAgentsPresent()` is the one check behind it; `Config::startupWarnings()` logs
  the reason once at bot start, so an operator learns about it, and it is the first precondition a gated run
  is refused by.
- `KimiGateGuard` is the refusal predicate of the gated modes and the epics on Kimi, and the one production
  caller of `KimiGatedPreconditions::check()`: it reads `$HOME/.agents` and the `metadata.protocol_version`
  of the newest session on the source home on every call (both change while the bot runs), and takes the
  self-test of the gate shim (`KimiGateSelfTest`, which `JobRunner` shares) — a pass is cached for the
  process, a failure is retried after a short backoff — and returns the refusal text of each failing
  condition — in the chat through `KimiGateGuard::refusal()` and in bot.log through `refusalLog()`. The queue
  (`QueueScheduler`), the `/epic` surface (`Epic\ChatSurface`) and `/compact` (`CompactFlow`) are its callers;
  `Bot` builds one instance for the whole process. An engine that is not Kimi is never refused by it. With the
  preconditions holding, the orchestrator of a Kimi instance is connected like on the other engines; an epic
  run that reaches the queue while a running epic's gate does not hold blocks the epic once with the refusal,
  so the orchestrator does not keep re-enqueuing a run that is dropped silently.
- The memory of the instance is injected here, because this engine reads no user scope (see [Memory](#memory)).

---

## Memory

`MEMORY_3LAYER_BIN` — the absolute path of the `memory-3layer` client — is opt-in and off by default
(`~/.local/bin` is not on the `PATH` of a non-interactive shell, hence the absolute path).
`Config::memoryBin()` hands it out on an engine that reads no user scope only, so with `ENGINE=claude` the
key changes nothing at all. Two pieces, both best-effort: a client that is missing, fails or hangs leaves
one line in the log and never delays or fails a run.

- **Injection** — `Memory\MemoryContext`, built by `RunnerFactory::memory()` and passed to `OpencodeRunner`
  and `KimiRunner` (the two engines that read no user scope; `Config::readsNoUserScope()` is what both the
  factory and `memoryBin()` answer to). `memory-3layer context` prints the PATHS of L0, the project's
  SESSION.md and project.md; the bot reads those files itself and appends their CONTENT, rendered from
  `prompts/memory_context.md.dist`, right after the mode prompt and before `CLAUDE_APPEND_SYSTEM_PROMPT`, so
  an agent needs no read access outside the managed repository. OpenCode gets it through the runner's
  `system-prompt.md` (re-read on every run); Kimi through the `agent.md` of its run home, a snapshot taken
  only when a NEW session is created — a resumed session keeps the agent file it was created with, and a
  fresh snapshot arrives after a `/new`, a compaction reseed or an engine fingerprint change. Each call is bounded by
  `MemoryContext::DEFAULT_TIMEOUT_SEC` (the client is SIGKILLed on expiry) and each file is capped at
  `MEMORY_3LAYER_CONTEXT_MAX_BYTES` bytes taken from its NEWEST end, the heading then carrying
  `(cut: the newest part only)`; `0` turns the injection off. A client that names no readable file gives no
  block at all and no warning — a project without memory yet is normal.
- **Sync** — `Memory\MemorySync`, one instance in `Bot`, handed to `JobRunner`. `launchJob()` starts `pull`
  once the run is alive: the prompt of that run is already built, so the refreshed data belongs to the NEXT
  run. `JobRunner::finishJobState()` starts `push` — the one call site every way a run ends reaches (finished,
  killed by its timeout, `/cancel` or the `poll()` exception path) — and `Bot::tick()` calls
  `MemorySync::poll()` on every pass to reap the child outside the job. The client runs through `proc_open`
  with argv `[<bin>, pull|push]` and its output redirected to `<STATE_DIR>/memory-3layer.log`, so no pipe can
  fill up and block it. One sync at a time for the whole instance: a non-blocking `flock` of
  `<STATE_DIR>/memory-3layer.lock`, held while a sync runs and released when it is reaped (the system
  releases it even if the bot dies). A second sync wanted by this instance while its own client still runs
  is deferred, not dropped — `poll()` starts it once the client is reaped, so a run that ends sooner than its
  pull still gets its push out — while a sync another bot process holds the lock for is skipped with a log
  line, and a client still alive after `MemorySync::DEFAULT_TIMEOUT_SEC` is killed.

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
5. Optional `CLAUDE_APPEND_SYSTEM_PROMPT`, then the chat-language line (`languageLine`), always last. On
   OpenCode `OpencodeRunner` adds the memory of the instance (part 7 of [PROMPTS.md](../PROMPTS.md#assembly))
   between the mode prompt and that text; with `ENGINE=claude` it is never added.

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
`session_id`, `pid`, `started_at`, `message_count`, Claude's last usage snapshot (`input_tokens`, `cache_creation_tokens`, `cache_read_tokens`, `output_tokens`, `cost_usd`), `verbose`, `mode`, `model` (the topic's chosen model, NULL = not set), `last_model` (the `model` of the last chat or `/compact` run's `system/init` event), `prompt_hash`, `engine_fingerprint` (NULL = default engine)), `queue` (with `model`, the model resolved at enqueue) and
`queue_messages` (the request queue), `kv` (decision, owner of a dirty tree, timers, per-user language, `model.default`, the model for all topics),
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
| `REPOSITORY_EXECUTORS`  | exclusive `owner/repository:openronin|coder` map; coder entries are the standalone intake allowlist | empty (intake off) |
| `CODER_INTAKE_*`        | trusted human approval login and bounded read-only intake schedule, tick/work budgets and request timeout ([coder.md](coder.md)) | off with empty routing |
| `ENGINE`                | agent engine that runs the jobs: `claude`, `opencode` or `kimi` (any other value fails startup); on `opencode` the factory builds an `OpencodeRunner`, on `kimi` a `KimiRunner`, and on both `/model` is read-only | `claude` |
| `CLAUDE_BIN`            | path to the claude binary                                      | `claude`                         |
| `OPENCODE_BIN`          | path to the opencode binary (`ENGINE=opencode`; the installer checks this one instead of `CLAUDE_BIN`) | `opencode` |
| `OPENCODE_MODEL`        | model of every run, `provider/model`; empty = the OpenCode default; changing it starts a new session | empty      |
| `OPENCODE_EXTRA_ARGS`   | extra opencode arguments, comma-separated, after the bot's own flags | empty                        |
| `KIMI_BIN`              | path to the kimi binary (`ENGINE=kimi`; the installer checks this one instead of `CLAUDE_BIN`); must be absolute, since the engine installs into `~/.kimi-code/bin`, which a service `PATH` lacks | `kimi` |
| `KIMI_HOME`             | home of the logged-in Kimi CLI; its `credentials/`, `sessions/` and `oauth/` are linked into every run home, never copied; empty = the engine's default home | empty |
| `KIMI_MODEL`            | model alias of every run, as the Kimi `config.toml` defines it; empty = the `default_model` of that file; changing it starts a new session | empty |
| `KIMI_EXTRA_ARGS`       | extra kimi arguments, comma-separated, after the bot's own flags | empty                        |
| `MEMORY_3LAYER_BIN`     | absolute path of the `memory-3layer` client; off unless set, handed to `ENGINE=opencode` and `ENGINE=kimi` only, and ignored on `ENGINE=claude` (that engine reads the user scope) — see [Memory](#memory) | empty |
| `MEMORY_3LAYER_CONTEXT_MAX_BYTES` | ceiling of the injected part of one memory file, newest bytes first; 0 = no injection | `32768` |
| `CLAUDE_TIMEOUT`        | overall call timeout, seconds (`0` = off)                      | `0`                              |
| `CLAUDE_STALL_TIMEOUT`  | no output for N seconds → hung, kill (`0` = off)               | `900`                            |
| `CLAUDE_EXTRA_ARGS`     | extra claude arguments, comma-separated                        | `--dangerously-skip-permissions` |
| `CLAUDE_APPEND_SYSTEM_PROMPT` | text appended to the system prompt (not split on commas) | empty                            |
| `PROMPTS_DIR`           | persona files, relative to `REPO_PATH`                         | empty (texts from `prompts/`)    |
| `STATE_DIR`             | SQLite, outbox, inbox, epic signals, log, claude `runs/` and `agents/`, failed-run `failed-runs/`, the per-run `opencode-<runId>/` config directories, the memory `memory-3layer.lock` / `.log` | `./state` |
| `FAILED_RUN_EVIDENCE_DAYS` | days the masked evidence of a failed run is kept (0 = no age limit; at most the newest 20 failed runs are kept) | `14` |
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

## OpenCode (verified facts)

Verified on OpenCode **2.3.0** (`@opencode/cli`); the fixtures are in `tests/fixtures/opencode/`. The engine
is wired (`OpencodeRunner`, selected by `RunnerFactory` when `ENGINE=opencode`); these facts are the contract
its parser and its environment rely on.

- **`opencode run` flags:** `--format json`, `--session <id>` (creates the session when it does not exist),
  `--model provider/model[#variant]`, `--auto`, `--agent`, `--title`, `--archived`. `--standalone` is a global flag
  (a private server per run instead of the user's background service).
- **NDJSON events:** `step_start`, `tool_use`, `text`, `step_finish`, `background_wait`, `error`; every event carries
  `sessionID`. `tool_use` is emitted only when the tool has finished (`part.tool`, `part.state.status` =
  `completed`/`error`, `part.state.input`). `step_finish` carries `part.tokens` (`input`, `output`, `reasoning`,
  `cache.read`, `cache.write`), `part.cost` (USD) and `part.reason` (`tool-calls`, `stop`).
- **Errors:** an `error` event with `error.type` — `provider.auth` (HTTP 401) or `provider.quota` (HTTP 402) —
  and exit code 1 (observed when capturing the fixtures; NDJSON does not carry the exit code, so it is recorded in the
  fixtures README, not in a file). Classify by `error.type`, not by the message text. No rate-limit sample was obtained.
- **Background sub-agents:** a `subagent` tool call reports `completed` immediately — that is the launch, not the
  result. `opencode run` waits for the sub-agent and only then exits. While it waits, stdout can stay silent for the
  whole duration of the sub-agent (78 s in the fixture). `background_wait` is **not** emitted for every wait: in
  `run/sequential-subagents.ndjson` it appears for the first sub-agent only, and the ~11 s wait for the second one
  has no event. A stall timer must therefore not rely on
  `background_wait` alone: after the first `subagent` call of a run, treat silence as expected.
- **Instructions on resume:** with `--session`, a changed `instructions` file is applied by OpenCode itself; the Bot
  must not re-send its system prompt in the message.
- **Config precedence:** the project `opencode.json` of the working directory outranks the explicit file given in
  `OPENCODE_CONFIG` (last match wins): a project `allow` overrode an explicit `deny`. With
  `OPENCODE_DISABLE_PROJECT_CONFIG=1` the explicit `deny` holds and the model sees `Permission denied: shell`.
  `OPENCODE_CONFIG_DIR` replaces `~/.config/opencode`. `.claude` directories are read only for skills and are
  disabled by `OPENCODE_DISABLE_CLAUDE_CODE=1`; `~/.agents` is read and has no switch.
- **Plugins:** a plugin is a directory (`package.json` + `index.js`, ES module, no build step) listed in `plugins` of the
  explicit config; it loads under `OPENCODE_DISABLE_PROJECT_CONFIG=1`. `import { Plugin } from "@opencode/plugin"` is
  resolved by OpenCode. `ctx.permission.hook("evaluate", e => …)` receives `action` (`shell`), `resources` (the
  command), `sessionID`, `agent`; setting `e.effect = "deny"` with `e.message` blocks the call and the model sees the
  message. A hook that throws makes the tool call fail with the thrown message (fail-closed). The `evaluate` hook does
  not run for a configured `deny` (`run/config-deny-hook-not-called.plugin.log`: `execute.before` is logged for both
  commands, `evaluate` only for the allowed one).
- **Session history** (`ctx.session.context`): messages of `type` `user`, `assistant`, `idle`, `synthetic`. A sub-agent
  call is an assistant `content[]` item `{type: "tool", name: "subagent", state: {input: {agent}, metadata:
  {sessionID: <child>}}}`. Its report is a later `synthetic` message with `metadata: {source: "subagent", childID:
  <child>, agent, state: "completed"}`. Match a call to its report by child session id. The order of events is the **array order** (or
  `time.created`), not message `id` order: in `history/stage-sequential.json` a report's `id` sorts before the messages
  that precede it, because the id is allocated at launch. Do not sort by `id`. A report is not a user message.
- **Shell success** (session history): a `shell` tool call reports `state.status: "completed"` for any command that ran,
  also one that exited non-zero; `status` is `error` only when the call was denied or a plugin threw. Success is the exit
  code, `state.metadata.exit` — the history flattens the tool state, so the NDJSON of `run/plain.ndjson` has it one level
  deeper as `state.metadata.metadata.exit`. An issue-creating `shell` counts as a successful creation only with `exit: 0`.
- **Agents:** markdown files in `$OPENCODE_CONFIG_DIR/agents/<name>.md` with `mode: subagent` and `description` are
  callable through the `subagent` tool.

## Kimi (verified facts)

Verified on Kimi Code CLI **2.7.2** (`kimi`), `metadata.protocol_version` `1.5` of `wire.jsonl`; the fixtures are in
`tests/fixtures/kimi/` (README: scenarios, exit codes). The engine is wired (`KimiRunner`, selected by
`RunnerFactory` when `ENGINE=kimi`); these facts are the contract its renderer, parser, runner and gate shim
rely on. `wire.jsonl` is private and undocumented: any of this can change with a Kimi update.

- **Run:** `kimi -p <prompt> --output-format stream-json [-S <id>] [--agent-file <file>] [-m <alias>]`. `-p` runs under `auto`
  permission. `KIMI_CODE_HOME` relocates config, credentials, sessions, logs; `KIMI_CODE_NO_AUTO_UPDATE=1` stops the self-update.
  A home with its own `config.toml` and **symlinks** to the real `credentials/`, `sessions/` and `oauth/` works (a per-run home
  only logs `projects.json is missing after migration`; `-S` by id still resumes). `$HOME/.agents/{agents,skills}` and
  `$HOME/.agents/AGENTS.md` are read **regardless of `KIMI_CODE_HOME`**.
- **stdout (stream-json):** first `{"role":"meta","type":"system.version"}`; `assistant` messages (with `tool_calls` or `content`),
  `tool` messages (`tool_call_id`, `content`), `meta` `turn.step.retrying` while a provider error is retried; last
  `{"role":"meta","type":"session.resume_hint","session_id":"session_<uuid>"}` — **only on success**. No usage, no model, no thinking.
  A hook failure is printed once per session as an `assistant` message ("PreToolUse hook … failed to execute …") and the
  `tool_calls` message is then printed again: a parser must not take that notice for the final answer. **stdout is not a faithful
  history:** for a `run_in_background` sub-agent the `tool` message says `status: completed … [summary]` and is printed
  after the final answer, while `wire.jsonl` has `status: running` (`run/14-subagent-background`).
- **Exit codes:** 0 success; 1 for every failure; 4 when the session is in use (`-S` on a live session): stdout gets
  `{"type":"error","code":"session.occupied", …, "details":{"holder":{…}}}`, stderr is empty. A failed provider turn prints only
  `error: failed to run prompt: Prompt turn ended with reason: failed` on stderr. Config-level failures are explicit:
  `Model "<alias>" is not configured in config.toml.`, `Session "<id>" not found.` The session lock is by pid liveness: a
  `kill -9` of the run leaves the session resumable (`20-resume-after-kill`).
- **Errors are in `wire.jsonl`:** `turn.ended` with `reason: "failed"` and `error{code, message, name, retryable,
  details.statusCode}`: 403 → `provider.auth_error` (`retryable: false`); 429 rate limit → `provider.rate_limit`
  (`APIProviderRateLimitError`, `retryable: true`, retried); 429 with a quota message → `provider.api_error`
  (`APIProviderQuotaExhaustedError`, `retryable: false`, **not** retried: classify quota by `error.name`, not by the code);
  500 → `provider.api_error` (`retryable: true`). The `30-mock-*` fixtures come from a synthetic server (made-up bodies, real client).
- **`wire.jsonl`** (`$KIMI_CODE_HOME/sessions/wd_<dir>_<hash>/session_<uuid>/agents/<agent>/wire.jsonl`; `state.json` next to the
  agents dir): records with `type` `metadata`, `profile.bind` (model alias, full system prompt), `turn.prompt`, `usage.record`
  (`usage{inputOther, output, inputCacheRead, inputCacheCreation}`, `usageScope: "turn"`), `turn.ended`, `prompt.completed`,
  and `context.append_loop_event` whose `event.type` is `tool.call` (`toolCallId`, `name`, `args`), `tool.result`
  (`toolCallId`, `result{output, isError}`), `step.end` (`usage`, `finishReason`). Sub-agents write their **own**
  `agents/agent-N/wire.jsonl`; the main file has only the `Agent` call and its result. The file is **append-only and cumulative
  across the runs of a session** (`25-resumed-gated-*`: 1, 2, 3 `tool.call`s while the hook records are per run), so anything that
  compares it with per-run data must anchor on the file size at launch.
- **Session id:** the session directory and `state.json` exist from the start and every hook payload carries `session_id`, although
  stdout prints it last. `-S <id>` resumes (the model remembers earlier runs).
- **Prompt:** no flag appends to the system prompt. `--agent-file <md>` with `${base_prompt}` in the body keeps Kimi's base prompt,
  project `AGENTS.md` and adds the file's text (`profile.bind.systemPrompt`). `--agent-file` is **rejected together with `-S`**
  (exit 1: "Cannot combine --agent/--agent-file with --session/--continue"): the resumed session keeps its first prompt as a snapshot
  (deleting the file afterwards changes nothing). A changed prompt on resume works as a user message: the bot's
  instructions-update block carrying the `pm` prompt, sent on an `arch` session, changed the answered role and held on the next run
  (`23-role-change-*`).
- **Agents and skills:** project `.agents/agents/*.md` (`name`, `description`, `tools`, body) and `.agents/skills/<name>/SKILL.md`
  are read; `.claude/` is not. `extra_agent_dirs` of `config.toml` **wins over `$HOME/.agents/agents`** for the same name
  (`24-home-agents-precedence`). A sub-agent is called through the `Agent` tool (`subagent_type`); synchronous by default: the
  `tool.result` carries `status: completed` and the answer.
- **Hooks (`[[hooks]]` of `config.toml`, no project-level file; they run in `-p`):** `SessionStart`, `PreToolUse` (`tool_name`, `tool_input`, `tool_call_id`), `PostToolUse`, `SubagentStart` / `SubagentStop`
  (`agent_name`; no `tool_call_id`), `Stop` (cannot continue a `-p` turn). **Only exit 2 blocks**, and the model sees stderr
  (`07-hook-deny`). Exit 0, **1, 255**, death by **SIGKILL**, a **timeout** and a **missing binary** all let the call run
  (`08`–`12`). `PreToolUse` also fires for tools used **inside sub-agents** (`15-subagent-bash`); the payload has no agent id, only
  `tool_call_id`. `PreToolUse` fires **before** Kimi's own static `[[permission.rules]]` deny (`21-static-deny`: the hook record exists,
  the tool result is "denied by permission rule"). A foreground `Agent` result and a background `task.completed` notification are
  on disk by the next `PreToolUse` (checked by a hook that reads `wire.jsonl` live).
- **Background and cron:** in `-p`, pending background tasks keep the run alive (`print_background_mode` defaults to `steer`:
  the completion arrives as a `turn.prompt` notification `task.completed` with the task id and starts a new turn). A pending
  `CronCreate` job kept `-p` running until the harness killed it after 150 s (`22-pending-cron`): a runner needs its own timeout.
  The config key `[tools] disabled = [...]` switches tools off globally and is a candidate for `Cron*` and `AskUserQuestion`.
- **Tools:** 26, one list in `tools.json`. Only `Bash` takes a shell string (`command`); `CronCreate` takes a `prompt` for the model,
  which later calls `Bash`. `Task*` and `Cron*` are tool families (`TaskList`, `TaskOutput`, `TaskStop`; `CronCreate`, `CronDelete`,
  `CronList`).
- **OAuth:** the access token lives 900 s; **the server rotates the refresh token** (the SHA-256 prefix of `refresh_token` changed
  together with `expires_at` after a refresh through the symlinked `credentials/`). So credentials must be shared, never copied. The
  refresh lock is a file in `oauth/` of the Kimi home; it works through a symlinked `oauth/` (the shared file was touched at the
  refresh). Concurrent refreshes from two processes were not tested.
- **Not obtained:** a real (non-synthetic) rate-limit or quota body; MCP tools (no MCP server was available; MCP is configured in
  `mcp.json`, not in `config.toml`); whether Kimi ever cleans `sessions/` (nothing found in the config reference).

## Tests and CI

`composer test` (phpunit), `composer stan`, `composer cs:check`. CI (`.github/workflows/ci.yml`) runs
`composer validate --strict` and a QA matrix of those three on PHP 8.4 and 8.5 for pushes to `master` and PRs; the 8.5 job runs
`composer test:coverage` (pcov), writes the coverage summary to the job summary and uploads
`coverage.xml` as the `coverage` artifact.
The flow tests (`QueueFlowTest`, `Epic\FlowTest`) drive `Bot` with fake `claude`, `opencode` and `kimi` shell scripts.
