---
tags: [memory/repo, gotcha]
---

# Gotchas — Workspace Assistant

Only the non-obvious: "looks like a bug but is intentional" and "looks harmless but breaks things".
The obvious is not written down. Operational traps (409, hangs, updates) are in
[operations.md](operations.md).

---

## The bot manages a repository that is NOT its own

`REPO_PATH` points at the **managed repository**. All `git pull/commit/push` (unless `AUTO_COMMIT=0`) and all Claude work
happen there, not in the bot's code. Editing `src/*.php` is not what the bot commits in response to
Telegram messages. Do not mix up the "bot's repository" and the "managed repository"
([glossary.md](glossary.md)).

Independent runs of the bot can push to the same repository one after another. A run starts from
whatever state it finds on disk: **pull before you start editing**, not only before the final push,
otherwise you edit stale code and get `! [rejected] (fetch first)`.

---

## A failed `git status` is "unknown", never "clean"

`GitManager::isClean()`, `hasChanges()`, `changedPathsIn()`, `treeSnapshot()`, `changedSince()` and `changesSummary()` throw
`Exception\GitStatusException` when `git status` exits non-zero (exit 124 from the timeout included; the message is stderr, or
"timeout"). Returning "clean" there would skip a commit silently and let the next run commit this work under its message. Every
caller treats the exception as a third state that blocks the actions of both branches: no commit, no clearing of the decision
or of the dirty-tree owner, no commit/discard buttons, no rollback, no pull. The user is told `git status failed: <error>`. A
new caller of these methods must catch it and do the same; in a `finally` it must only log, so it never replaces an exception
already in flight. Only the `status --short` call of `changesSummary()` counts: a failed `diff --stat HEAD` (an unborn branch)
just drops the stat part.

With `AUTO_COMMIT=0` the per-run snapshot has three states: known, unknown (`treeSnapshot()` threw; the error is kept in
`RunState::$runTreeSnapshotError`) and none (not an `AUTO_COMMIT=0` run). Unknown never reads as "nothing changed": the reply says it
could not tell what the run changed, the PROMPTS_DIR / LOCALES_DIR note says it could not check, and `/compact` stops after
phase 1 with `git status failed: <error>` (also when `changesSummary()` fails in the `compact.dirty` message).

## Two different `proc_open` environments are intentional

- `ClaudeRunner::start` passes `env = null` (or the bot's environment plus run variables): claude
  **inherits** the bot's `HOME`/`PATH` and will not start without them.
- `GitManager` passes an **explicit** `gitEnv()` (the full environment plus forced
  `GIT_TERMINAL_PROMPT=0` and a non-interactive `GIT_SSH_COMMAND`).

Giving claude a trimmed env stops it from starting; leaving git on `env = null` loses the forced
non-interactivity and git can hang on a passphrase or host key.

---

## Markdown → HTML

- **`htmlspecialchars` flags differ on purpose.** Inside `<pre>`/`<code>` the flag is `ENT_NOQUOTES`
  (Telegram wants only `< > &` escaped there; `ENT_QUOTES` would litter code with `&#039;`); everywhere
  else it is `ENT_QUOTES`. Do not unify them.
- **The order of transformations is load-bearing:** hide code/tables/inline code behind placeholders
  (`\x02N\x03`) → escape the rest → markup regexes on the already escaped text → restore placeholders.
  Reordering gives broken HTML or markup firing inside code blocks. Tables go to `<pre>` because Telegram
  has no HTML tables.
- **The length limit is measured on HTML, but the file is Markdown.** `TelegramClient::sendText` compares
  `mb_strlen($html) > 4096` (what the API would receive) and, when over, sends the original Markdown as
  a `.md` file.

---

## Time: `started_at` is UTC, logs are in `TIMEZONE`

`bootstrap.php` sets UTC; `bin/bot.php` then applies `TIMEZONE` from `.env`. The review Stop hook does
not load `Config` and takes `TIMEZONE` via `Timezone::applyFromEnvFile`. But `Database::setPid` writes
`started_at` with **`gmdate('c')`**: logs are for people, the DB field is a machine ISO timestamp. Do
not "fix" `gmdate` to `date`.

`SUBREPOS_DIR` (relative to `REPO_PATH`) is empty by default: pulling, HEAD snapshots and commit links of
sub-repositories are off and the bot does not touch clones there.
Their commit links use each clone's own `origin`: `FORGE`/`FORGE_HOST` first, then github.com and gitlab.com, else a bare hash (`SubRepoSync::commitUrl`).

---

## `/stats`: input tokens are a sum of three fields

`ClaudeResult` adds `input_tokens + cache_read_input_tokens + cache_creation_input_tokens`. "Input
tokens" in `/stats` is the **total** input including cache, not raw input.

---

## `/compact` is a two-phase job; the counter reset is deferred to phase 2

`-p` mode has no slash commands, so `Command\CompactCommand` emulates `/compact` with two consecutive runs
distinguished by `jobContext['kind']`:

1. `RunKind::KIND_COMPACT_SUMMARIZE`: `--resume` of the old session, asking for a summary without file edits;
2. `RunKind::KIND_COMPACT_RESEED`: a new call **without** `--resume`, the summary from phase 1 is the first message.

`Database::resetSession()` is called only inside `CompactFlow::finishReseed()`, **after** the new session id
exists. Resetting earlier would leave the user with no old and no new session if phase 2 failed to
start. For the same reason the generic `recordRun` in `JobRunner::poll()` skips `RunKind::KIND_COMPACT_RESEED`: that phase
writes `resetSession()` + `recordRun()` itself, so the counters reflect only the new session.
The summarize phase always runs in `arch` mode whatever the topic's mode, otherwise compacting in
`review`/`pm` would squeeze the summary to three lines and lose context irreversibly. The reseed phase
runs in the topic's mode (`Database::getMode` at reseed time) with the same `--settings` hooks as a
regular run of that mode: the new session keeps its system prompt for life (`--resume` ignores a changed
`--append-system-prompt`), so an `arch` reseed would silently drop the `pm`/`review` role.

---

## Job state, results and shutdown order

- **`finishJobState()` runs BEFORE the reply is sent to Telegram.** If delivery fails there is no stuck
  `activeJob` and `poll()` does not spin on a closed process. The same applies in the `catch` around `poll()`.
- **`ClaudeRunner::result()` is valid only right after `poll() === Done`** and is taken before
  `finishJobState()`. Required order: take result → release state → delete "Thinking…" → send.
- **`$offset` lives only in memory.** Telegram counts an update as consumed only when its offset is passed
  in the NEXT `getUpdates`. `tick()` advances `$offset` before `dispatch()`, so a process that exits right
  after handling an update (`/restart`, SIGTERM) would be re-sent that same update on the next start and
  loop forever under `Restart=always`. `Bot::run()` therefore makes a best-effort
  `getUpdates($this->offset, 0)` right after leaving the `while` loop, before anything else. Do not remove
  it or move it later; it covers every clean exit path. For `kill -9`/OOM you would have to persist the
  offset in `Database`.
- **PIDs from a previous run are invalid** and are cleared at start (`Database::clearStalePids()`).
- **Single thread:** every blocking operation needs a timeout with non-blocking pipe reads.
- **`discardChanges()` removes untracked files too** (`reset --hard` + `clean -fd`). That is what the
  "Discard" button must do; do not reuse it where a soft rollback is expected.

---

## stream-json in `-p` needs `--verbose`; `$this->stdout` holds only `result`

`ClaudeRunner::start` runs `claude -p --output-format stream-json --verbose`. Without `--verbose` the
mode fails with "requires --verbose". The parser takes `tool_use` blocks from `assistant` events (live
progress) and stores **the line** of the `result` event in `$this->stdout`, because
`ClaudeResult::fromJson` expects one JSON object, not JSONL. An unfinished buffer tail is flushed when
the process ends.

---

## The system prompt: texts in `prompts/*.md.dist`

The rules Claude follows are split in two (see [internals.md](internals.md) for the assembly order):

- **Contract** — the 14 names of `PromptFiles::CONTRACT` (`common`, `common_limits`, `contract_review`,
  `language_line`, `session_update`, ...) in `prompts/*.md.dist`, under git. A local `prompts/<name>.md` can override it, but every run reports each
  override of a contract name (`PromptFiles::CONTRACT`) in the reply. It holds what the bot parses or enforces:
  the identity line, delivery, **self-preservation** (never run `bin/ctl.sh`, stop, restart or kill the
  bot; Claude runs inside the bot's working tree, and a restart kills the session before the reply is
  delivered), the size ceiling and the review rules.
- **Persona** — the common block plus the role block of the mode (`persona_common`, `role_arch`,
  `role_review`, `role_pm`), replaceable by `common.md`/`arch.md`/`review.md`/`pm.md` in `PROMPTS_DIR`
  or by a local `prompts/<name>.md` (not reported: it is the normal way to configure an instance).
- **`.dist` placeholders change only by addition.** Placeholders are named `{name}`; code fills a fixed set
  and a local file must use the same set (otherwise a warning). The bot process loads code only on
  restart, while the Stop hook and the prompt files are read from disk at once, so renaming or removing a
  placeholder in a `.dist` ships together with a bot restart. A `.dist` must exist for every name in the
  closed list (a test checks it); a missing one fails the run with the file name, not the bot loop.
- **The Stop hook never fails open on a block.** If the reason texts cannot be loaded, the hook still
  returns `block` with the hardcoded `ReviewStatusHook::FALLBACK_REASON`.

Traps:

- **Do not move the prompt to `.env`** (that is for environment settings) and do not put the contract
  into persona files. `CLAUDE_APPEND_SYSTEM_PROMPT` is appended AFTER the assembled text in the **same**
  `--append-system-prompt` flag (claude refuses two append flags).
- **The size ceiling is `common_limits`, not part of `common`**, and is included only for `review`/`pm`.
  In `arch` a long analysis is the expected answer and any number in its prompt contradicts that. The
  ceiling is a prompt goal, not a code contract: nothing truncates or rejects a long answer.
- **The "reply packaged into a file" line is mode-specific:** in `role_arch` it says that packaging is not
  an error; in `role_review`/`role_pm` it says the opposite (a mode failure to correct).
- **Shipped personas are neutral and repository-agnostic.** They name no instance-specific sub-agents,
  checkers or rule files; an instance's own procedure belongs in its `PROMPTS_DIR` files. There is no
  separate scope-guard prompt file; the scope check is the built-in `scope-check` sub-agent (see the
  next item). The gate that requires it before an issue is opened is implemented in code
  (`PmGateHook`): it works in the `pm` and `review` modes and in the epic runs `tick`, `final` and
  `review`, fail-closed; the required stages come from the json block of the PM runbook (an instance
  addition may replace `stages`), and by default they include the `scope` stage (agent `scope-check`).
  `role_pm` only describes the role and points to the PM runbook; the stages
  live in one place, `prompts/pm_runbook.md.dist`, and are not repeated in the persona. The scope check
  is the runbook's `scope` stage (agent `scope-check`). Keep personas
  describing the work, not only the reply format.
- **Built-in sub-agents (`critic`, `verifier`, `editor`, `scope-check`, `acceptor`, `security`) are read-only and
  passed on every Claude run except `/compact`.** `BuiltinAgents` writes them to
  `STATE_DIR/agents/<run id>.json` (one file per run, removed with the run files) and passes
  `--agents <file>`, also on `--resume`: the CLI keeps no `--agents` in the session. The CLI lets an
  `--agents` entry shadow a project agent of the same name, so an agent whose frontmatter `name:` matches
  one in `<REPO_PATH>/.claude/agents/*.md` or `~/.claude/agents/*.md` is left out (the file name does not
  matter). `CLAUDE_EXTRA_ARGS` with `--agents` drops all of them and warns on every run. `tools` are fixed
  in code; a local `prompts/agents/<name>.md` replaces the whole file but must keep `name:`, otherwise
  `.dist` is used with a warning. The agents are not in `PromptFiles::NAMES`/`CONTRACT`.
- **`role_review` has two independent axes:** fit to the task's intent, and cross-project scope (consumers
  in other components, recorded decisions, paired tasks, duplicated solutions). The second is mandatory:
  a cold CI reviewer sees one repository and one diff, the architect sees the whole system.
- **Prompt files are not trusted input.** They live in the managed repository, which the bot edits and (with `AUTO_COMMIT=1`)
  pushes. A run that changes them gets a warning on every commit path (`RunResult::sendResult()`, epic runs, the
  manual "commit" button); see the accepted risk in [context.md](context.md). Temporary prompt files
  outside git are wiped by `reset --hard` and cause "file not found".
- **Mode** is the `mode` field of `sessions`, sticky: `resetSession()` keeps it, `/new` and `/compact`
  keep it. A new session starts in `SystemPrompt::DEFAULT_MODE` (`pm`). `Database::ensureRow()` writes
  `mode` **explicitly**, not through the column DEFAULT, because already created databases carry an old
  `DEFAULT 'arch'` and SQLite cannot alter it. `Database::getMode()` falls back to `DEFAULT_MODE` with a
  log line on an unknown or empty value. The mode label in "Thinking… [mode]" is shown only when the mode
  differs from the default.
- **Updated instructions in a resumed session.** `sessions.prompt_hash` holds the sha256 of the system
  prompt actually passed in argv (contract, role, `CLAUDE_APPEND_SYSTEM_PROMPT`, language line) by the last
  successful run. On `--resume` of a chat run (`ClaudeRunner` with `updateOnResume`), a missing or different
  hash prepends the `session_update` block (`prompts/session_update.md.dist`) to the message: it declares
  that it replaces all earlier instructions as a whole and carries the full new prompt. Once is enough, the
  session keeps it. So `/mode`, `/lang` and edits of the prompt files take effect from the next message
  without `/new`. The hash is written by `recordRun` only after a successful run, never after the
  `compact_summarize` phase; the reseed session gets its hash in `CompactFlow::finishReseed`; `resetSession`
  clears it. Epic and compact runs never get the block. The contract recognizes the block only by the
  verbatim first line of `session_update.md.dist`; the markers of the block in the message text of **any**
  run (chat, epic, compact) are replaced with `[update marker removed]` by
  `ClaudeRunner::neutralizeUpdateMarkers()`, so a quoted block in a discussion reaches the model altered. The wording must stay "replaces everything": a
  "replaces where it contradicts" block leaves the old role's non-conflicting rules in force.
- **Size-ceiling violation outside `arch`** (reply went out as a file) is flagged in the **caption of the
  file** (`TelegramClient::sendText($overflowCaption)`, `Delivery::overflowCaption()`), only when the reply
  overflowed by length. A markdown table always goes as a file (a `<pre>` table is unreadable on a
  phone), but that is normal packaging, not a violation.

---

## Sessions are keyed `(chat_id, thread_id)`; `thread_id = 0` is NOT NULL

Each forum topic is a separate Claude session. `thread_id = 0` is the synthetic "no topic": a 1:1 chat
and the **General** topic. Real topics are always `> 0` (`message_thread_id` is a monotonic message id),
so `0` never collides.

- **`0`, not `NULL`:** in SQLite `NULL != NULL` in keys and in `WHERE thread_id = ?`; `INSERT OR IGNORE`
  would silently duplicate rows and `UPDATE … WHERE thread_id = NULL` would never match. Do not make it
  nullable.
- **Topic detection** requires `is_topic_message === true` AND `message_thread_id` (`TelegramMessage::threadIdOf`).
  Replies outside topics may also carry `message_thread_id`.
- **Orphan rows for deleted topics are expected and harmless:** Telegram sends no deletion update and the
  Bot API cannot list topics, but `thread_id` is never reused. Bulk cleanup, if ever needed:
  `DELETE FROM sessions WHERE chat_id=? AND thread_id > 0`; **never by bare `chat_id`** (it removes the
  General session). Transcripts of such sessions are removed separately (`SessionFiles`); a pre-compact
  session id is no longer stored, so those files cannot be found by topic.
- **`thread_id` is passed asymmetrically:** only for new messages (`sendMessage`/`sendDocument` →
  `message_thread_id`). `editMessageText`, `deleteMessage`, `answerCallbackQuery` address by `message_id`
  and intentionally get no `thread_id`.
- **Forum privacy mode:** by default a bot in a group sees only commands, mentions and replies to itself.
  For "just write in the topic" turn privacy off in @BotFather (`/setprivacy` → Disable) and re-add the
  bot to the group. 1:1 chats are unaffected; the whitelist stays the only access control.
- **Commands carry `@botusername` in groups** (`/new@MyBot`). `Command\Router::handle` cuts any `@` tail
  before the command lookup; without it every command in a topic is answered with `command.unknown` while
  plain text works. The bot name is not
  compared (`getUpdates` returns only updates addressed to this token).

---

## `/new` and `/compact` delete the old session transcript

`SessionFiles::delete` runs on `/new` and in phase 2 of `/compact`, **only on success**.

- Delete the pre-compact session only after a successful reseed; on failure the old session is kept for a
  retry (`--resume` would fail without its file). `compact_old_session_id` travels in `jobContext` of both
  phases and `delete()` is called in `CompactFlow::finishReseed` after `recordRun`.
- The file is found by UUID with the glob `<projects>/*/<id>.jsonl`; the cwd→slug encoding is not
  reconstructed. `isUuid()` guards against a glob on a wildcard character.
- The path comes from the bot's environment (`CLAUDE_CONFIG_DIR` or `$HOME/.claude`), the same one claude
  runs with; a mismatch makes `delete()` a harmless no-op.
- Irreversible and best-effort: `delete()` throws nothing, a filesystem failure must not break `/new`.
  Transcripts of sessions never closed with `/new`/`/compact` are not cleaned.

---

## `session_id` is captured at init, not only at the end

`claude -p --resume <old>` forks a **new** `session_id` and puts the request there. If the run is killed
(`/cancel`, timeout) before the end, the DB would keep the old id and the next "continue" would lose the
request. So `ClaudeRunner` catches the id from the first stream event (`system/init`) and
`JobRunner::persistSessionEarly` writes it via `Database::setSessionId` while the state is `Running`.

- **Only `RunKind::KIND_CHAT`.** Compact phases manage `session_id` themselves; epic runs have no session.
- `setSessionId` does not touch `message_count`/tokens: a killed run under-counts one message, which is
  acceptable.
- Resuming a killed session carries the interrupted turn; that is by design.
- Killing before `init` (a fraction of a second after start) still loses the request.

---

## `/cancel` cancels only its OWN topic

There is one job for the whole bot, but `JobRunner::cancelTopic()` (behind `/cancel`) kills it only if the caller's
`(chat_id, thread_id)` owns it, and removes only that pair's queue items. Another topic's job and items
are never touched. With no job and no items of its own while another topic runs, the reply is "work is in
another session". `state/outbox` is cleaned only when the own job was killed or no job exists.

---

## A Telegram failure is not "not delivered": fall back only on API refusal

`Delivery::deliver()` sends a reply to the topic and falls back to the chat root when that fails (the topic
may be closed or deleted). A blanket `catch (\RuntimeException)` is a trap: a timeout on `sendMessage`
with `0 bytes received` means "no answer", not "no message", and the fallback duplicates the reply. Worse,
the topic and the chat root are **different Claude sessions**, so the dialog splits in two.

Decide by exception **type**:

| Exception (`src/Exception/`)                         | What is known              | `deliver()` reaction            |
|------------------------------------------------------|----------------------------|---------------------------------|
| `TelegramApiException` (`ok:false`)                  | the message is not there   | fall back to the chat root      |
| `TelegramTransportException`, `requestSent` = false  | the request never left     | retry in the **same** topic     |
| `TelegramTransportException`, `requestSent` = true   | outcome unknown            | do not resend; reply text → log |
| `TelegramTimeoutException`                           | subclass of transport      | by `requestSent`                |

`requestSent` is `CURLINFO_CONNECT_TIME > 0`. All four classes extend `\RuntimeException` **on purpose**:
`catch (\RuntimeException)` is spread across `Bot` and `TelegramClient`
(`deleteMessage`/`editMessageText`/`answerCallback`) and soft degradation depends on it
(`tests/TelegramExceptionTest.php` pins the hierarchy).

---

## Albums, files and the loop

- **`AlbumBuffer` matures on `tick()`, not on a timer.** `Bot::flushReadyAlbums()` runs on every tick,
  before `pollJob()`, even while another job runs, so an album sent during a run becomes queued
  immediately with exactly one "queued" placeholder. The debounce (`ALBUM_DEBOUNCE_SEC`) counts from the
  **last** frame of the group.
- **`getUpdates` timeout is `0` while there is work** (active job, non-empty album buffer, ready queue
  head), and every zero-timeout case is followed by `usleep(200 ms)` (`pollTimeoutNow() === 0`), otherwise
  the loop becomes a busy loop against the Telegram API.
- **Downloaded files live exactly one run** and are deleted from EVERY way out of a job: normal end and
  timeout in `JobRunner::poll()` (take the paths from `jobContext['inbox_files']` BEFORE `finishJobState()` clears
  it) and manual cancel in `JobRunner::cancelTopic()`. If `launchJob()` fails to start the runner, `QueueScheduler::startJobOfItem()`
  deletes the files itself. `Inbox::sweepInbox()` removes leftovers older than an hour.

---

## Callback handlers: stateless ones before the decision handler, which is last

- **Stateless** (`mode:<mode>:<thread_id>`, `lang:<code>`, `epic:<action>:<thread_id>[:<slug>]`): everything needed is in `callback_data`, the
  update survives a restart. Handled **before** the decision-record check and never reads or writes that record.
- **Stateful** (`commit`/`discard`): checked against the decision record (`Database::getDecision()`: topic
  and `message_id` of the buttons message; one slot for the whole bot).

The decision record is shared by all topics. The `/mode`, `/lang` and `/epic` keyboards live in the feed indefinitely, so if they
went through the commit/discard branch (`AUTO_COMMIT=1`) a tap would overwrite a pending decision from another topic. Put a
new stateless handler **before** `DirtyTreeDecision` in `Bot::$callbackHandlers` (the list is `mode:`, `lang:`, `epic:`,
`DirtyTreeDecision`; `DirtyTreeDecision::supports()` accepts any data, so a handler after it never runs). `CallbackHandlerOrderTest`
pins the order. Access control is a guard in `handleCallback()` before the list, not a handler.

`threadIdOf()` is unreliable for `callback_query.message` of long-lived keyboards: Telegram sends a
truncated message object without topic flags and the result silently becomes `0` (General). That is why
the topic is encoded in `callback_data` (`ModeAndLang::parseModeCallback()`). `chat_id` is taken from the update as usual.

---

## `review` status is enforced by a Stop hook, not by the prompt

The rule "publish the review state with `gh api …/pulls/N/reviews -f commit_id=<SHA> -f event=…`" is in the contract, but a warm session keeps
its habit (verdict in chat, findings as a comment). So in `review` mode `ClaudeRunner` passes `--settings`
with a Stop hook `bin/review-stop-hook.php` (logic in `ReviewStatusHook`): PR work in the turn + no
successful review publication → the turn does not end and the model is told to set the status and repeat the
verdict.

- The hook triggers **without** requiring 🟢/🔴 in the reply (a verdict in prose would otherwise slip
  through). Cost: a factual question about a PR in `review` mode may cost an extra round. A reply with no
  verdict but with a status set during the turn is released.
- The **model** sets the status; the hook only refuses to let go. The bot writes nothing to GitHub itself.
- A verdict without PR work in the turn (a retelling of an old one) is skipped, otherwise it would force
  an approve of an unchecked head.
- A second pass (`stop_hook_active`) without a status is released (no loops) but leaves a marker
  `STATE_DIR/review-status-missing/<session>`; the bot appends a warning to the reply. Decisions are in
  `STATE_DIR/review-hook.log`. The hook is fail-open: a script error releases the turn and is logged.
- With both 🟢 and 🔴 in the reply the earlier one is the verdict; only a status matching the verdict
  counts (the last successful one in the turn). A mismatch blocks again.
- On GitLab (`FORGE=gitlab`) the verdict is a successful `glab mr note <N> -m "…"` whose inline body
  carries exactly one marker `<!-- verdict:approve|changes sha=<lowercase 40-hex> -->`; the Stop hook counts it
  (`-F`/body files are not read; a missing, malformed or repeated marker is not a verdict). The marker is the
  source of truth: `glab mr approve` / `glab mr revoke` are side effects and never count. Detection accepts
  the `gh` and the `glab` forms whatever the forge; `--forge=` only selects the reason text.
- After the hook the final `result` is the message AFTER rework, so the hook's reason asks to repeat the
  whole verdict. Works only in `review` mode.

### The review is pinned to the reviewed commit by a PreToolUse hook

`gh pr review --approve | --request-changes` has no commit argument: GitHub attaches the review to the PR
head at the moment of sending, and a review takes minutes. So `ReviewCommitHook` (PreToolUse, matcher
`Bash`, review mode and the epic review run) denies it, and denies a POST to `pulls/N/reviews` with
`--input`, without `commit_id`, with a `commit_id` that is not a literal 40-hex SHA (`$(…)`, a shell
variable) or that differs from the SHA recorded for that PR. The denial text carries the correct form.

- The recorded SHA: `ColdFindings` `head` in an epic run (also in the run prompt by `RunPrompt::forEpicRun`),
  `headRefOid` of each PR the bot recognised in a manual review message (`RunPrompt::manualReviewFindings`,
  one SHA per PR). Without a recorded SHA (PR not recognised, findings failed to load) only the form is
  checked: a known limitation, the reviewer records `headRefOid` itself as its first step.
- The hook takes no forge argument, so the `glab` checks apply whatever `FORGE` is set to: it also denies
  `glab mr approve` without a literal lowercase 40-hex `-s|--sha` (uppercase is denied: GitLab compares the
  `sha` strictly with the lowercase MR head SHA and answers 409), a `glab api` POST to
  `projects/<id>/merge_requests/<N>/approve` without such a `sha` (field `-f/-F/--field/--raw-field sha=…` or
  `?sha=` in the URL; `--input` is denied, GET and `unapprove` are not), and a `glab mr note` whose
  `verdict:` markers are not exactly one marker of the grammar the Stop hook counts
  (`ReviewStatusHook::GITLAB_MARKER_RE`, the only definition; a note is denied here or counted there).
  A note with a marker must also have exactly one `-m`/`--message` flag (counted in every unquoted form outside the
  body values the Stop hook reads) and no marker in a heredoc outside the `-m` value: glab publishes only the
  last `-m`, while the Stop hook joins every value, so such a note is denied here, not normalised there. A
  multi-line body is `-m "$(cat <<'EOF' … EOF)"`; a bare top-level heredoc is cut out before the hook
  and is never counted. The MR number of the `glab api` approve path may be any expression (`$N`, `$(…)`);
  the `sha` check still applies. An unquoted `$(…)` anywhere in a `glab api` command that contains
  `merge_requests` is always denied (`PmGateHook::commands()` cuts the segment there, so the path, method and
  fields after it are not visible). A regex error (`preg_match` returning `false`) fails the hook, not allows.
  Known limitations: a body flag written in quotes or escaped (`"-m"`, `\-m`) is not recognised, so the flags are
  counted in every unquoted form only; quotes inside the approve path (`5"/approve"`) are not parsed; a
  `merge_requests` word assembled from substitutions (`merge_req$(echo uests)`) is not recognised. The
  `sha` is a literal: `$(…)` and shell variables resolve at publish time. The `<id>` grammar of the `glab api`
  path is `PmGateHook::GLAB_PROJECT_PATH`, shared with the issue-creation gate. Known limitation: the bot records no SHAs on GitLab (there is no
  GitLab API client), so the SHA is checked by form only and is not compared with the MR head.
- Request changes always goes on the SHA; an epic Approve too (the merge gate is protected by
  `coverage_head`); a manual Approve only if the PR head still equals the SHA. A POST rejected by GitHub
  sets no status and is not republished on another commit. Both "no status" cases take the ordinary Stop
  hook path (block, then `missing`); `decide()` has no pass for them.
- The code is read at the SHA (`git diff <merge-base>...<SHA>`, `git show <SHA>:<path>`, tests in a
  temporary `git worktree`), not via `gh pr diff` and not with `git checkout` in a working clone (detached
  HEAD stops `SubRepoSync`).
- The hook is fail-closed (like `PmGateHook`), unlike the Stop hook.

---

## The request queue: what must not be "simplified"

A request while the bot is busy is queued in one FIFO (`queue` table; one claude run at a time in one
working tree). No parallel runs, no `git worktree`, no priorities: the queue serializes.
`QueueScheduler::startQueueHead()` is called by `Bot::tick()` whenever there is no job.

- **A pause on a dirty tree is derived from state, not stored.** Rule: queue non-empty + no job + dirty
  tree + `AUTO_COMMIT=1` (`QueueScheduler::isPausedByDirtyTree()`). No in-memory flag: after `/restart` or a failed `git commit` a flag
  would leave the queue paused with no buttons forever. In the DB there is only the *awaiting decision*
  (`decision`) and the *owner* of the dirt (`dirty_owner`: topic of the last started job). Buttons are
  re-issued on every entry into the pause (`DirtyTreeDecision::ensureDecision`); a manual cleanup over SSH lifts the pause by
  itself. Unknown owner → buttons go to the head's topic marked "changes not from this request" with a
  neutral commit message.
- **The mode is read only from `jobContext['mode']`.** It is fixed at enqueue (`queue.mode`) and used by
  `launchJob` (including the `ReviewStatusHook` choice), `QueueTexts::thinkingText`, `overflowCaption`, `RunResult::sendResult`.
  Never from `sessions` (`Database::getMode`), otherwise `/mode` sent while another run is going would
  restyle an already queued request. The `/mode` gate is "THIS topic has a job", not "the bot is busy".
- **`session_id` is read when the item starts, not at enqueue.** A run of the same topic may have forked
  the session meanwhile; a stored id would `--resume` a stale transcript.
- **`state/outbox` is cleaned before every `launchJob` and on `/cancel`,** otherwise files of a cancelled
  run go out with the next reply, possibly to another topic.
- **Start-up checks are strictly ordered:** placeholder edit → tree clean → sync → `session_id` → "is there
  anything to compact" → download files → launch. The placeholder goes first: `EditStatus::Gone` (topic or
  message deleted) drops the item before any quota is spent. "message is not modified" is `Ok`, not `Gone`.
- **Start-failure policy:** transient failures (pull/fetch, claude start, network download) keep the item
  as head and retry after 60 s (`RETRY_DELAY_SEC`, a timer in `kv`), telling the topic once; final ones
  (`/compact` without a session, file unavailable or too large) drop the item. A download failure of any
  file of a merged item drops it whole. The 10-retry cap applies only to item-specific failures (network
  download); sync and claude-start failures are shared by the tree and never drop the head.
- **"Topic deleted" is checked before EVERY claude start** (`QueueScheduler::startJobOfItem`): edit the placeholder (`Gone`
  → drop), or, without a placeholder (returned by a limit), send a new one to its own topic with no
  fallback to General. `activeJob` is assigned right after `ClaudeRunner::start()`, before any side effect.
- **Update de-duplication:** `queue_messages` with `UNIQUE (chat_id, message_id)` holds all ids of an item
  (first message, merged ones, album frames). The change summary in the buttons message is truncated
  (otherwise it goes as a file without the keyboard); the full list is a separate message.
- **A started item stays in `queue` with `running = 1`** until the run ends (a limit hit re-queues it as
  head with the same id, keeping FIFO order). Removed in the `finally` of `Bot::pollJob` except for a limit
  return and the compact summarize → reseed hand-over (same item). An item with `running = 1` at bot
  start is a job killed by a restart: removed with "repeat the request", **not** relaunched (the prompt
  would be duplicated in the transcript).
- **Merging** only into the last pending item of the same topic with the same mode (and language), not
  closed by `/mode`, `/new`, `/compact` (`queue.closed`), and never into a running job.
- **"Queued: Nth" is written once** and not renumbered; `/queue` shows the current picture.
- **An epic run (`kind = epic`) is isolated from the topic session.** `QueueScheduler::startEpicItem` starts claude with
  `sessionId = null`; `persistSessionEarly` skips it (only `RunKind::KIND_CHAT`), `recordRun` in `JobRunner::poll()` excludes
  `RunKind::KIND_EPIC` explicitly, otherwise the next chat run of the topic would continue the tick's session. The
  reply text does not go to the topic, only the signal (`state/epics/<slug>/signal.json`, path in
  `WORKSPACE_ASSISTANT_EPIC_SIGNAL`) and the outbox. Exception to "a job killed by a restart is dropped":
  an epic item is silently returned to the queue in `QueueScheduler::recoverQueueOnStart` (`queueRelease`).

---

## Human-facing texts come from the locale catalog

Every text a human sees (chat, bot comments on GitHub, commit messages) is `$this->t('group.key',
['name' => $value])` in `src/Bot.php` or `$this->locale->t(...)` elsewhere, from
`resources/locales/<code>.json` (`src/Locale.php`). A class receives `Locale` as an optional constructor
parameter (default `en`); `bin/bot.php` builds one `Locale::fromConfig()`. Rules enforced by
`tests/LocaleCatalogTest.php`:

- **the key is a string literal only** (the test greps `->t('...')`; for a conditional write
  `cond ? $this->t('a') : $this->t('b')`);
- every key used in code exists in `en.json`, and `en.json` has no unused keys;
- every built-in locale is complete (all `en` keys) and its `{name}` placeholders match `en`;
- values are substituted through parameters, not concatenated; substituted values are not substituted again.

Deliberately **outside** the catalog: Claude prompts (`prompts/compact_*`, `prompts/default_file_prompt`, the
"File:/Files:" lines, `SystemPrompt`, `Epic\ColdFindings::promptLines`), `Log::line`, exceptions, and
`Epic\RunLifecycle` failure reasons (written to `failures.log`). Tests pin `botLocale: 'ru'` in `Config`; the default
(`BOT_LOCALE` unset) is `en`.

## Reply language (`/lang`)

- The language is stored per user in `kv` (`locale:user:<id>`), never from `from.language_code`; with no
  choice, `BOT_LOCALE` applies.
- It is fixed at enqueue (`queue.locale`, `NULL` = `BOT_LOCALE`) and read by the run from the item, like
  `mode`. Messages are merged only if both mode and locale match.
- `SystemPrompt::languageLine` is the last line of the system prompt, after the persona and
  `CLAUDE_APPEND_SYSTEM_PROMPT`; it sets the language of only the final Telegram message. Messages with no
  author (epic, epic runs, "queue saved") use `BOT_LOCALE`.
- The `compact_*` prompts are English: part of the contract; the language line sets the reply language
  after the reseed.
- A resumed session does not pick up a changed `--append-system-prompt` (the CLI keeps the prompt the
  session was created with). The bot delivers the change itself: see "Updated instructions in a resumed
  session" below. `/lang` does not reset sessions (it is per user, a reset would drop context in every topic).
- The `/lang` reply always carries `lang.session_warning` (unlike `/mode`, which warns only when the
  topic had a session): the language is per user while sessions are per topic, so there is no single
  session to check.

---

## GitHub moves `APPROVED` to the new head after a master merge

After a merge commit (`update-branch`) GitHub re-attaches an `APPROVED` review to the new head, both in
GraphQL (`review.commit.oid`) and in REST (`commit_id`). `CHANGES_REQUESTED` and `COMMENTED` stay on the
reviewed commit. So `epicReviewHead` follows the head and proves nothing about what was reviewed.

The approval is anchored on the task counter `coverage_head` (the head the bot ran the hot review on and
closed the findings): `TaskState::headAccepted` accepts a head equal to `coverage_head` or "only master
merge" computed from `coverage_head`, and `APPROVED` must sit in one of two places: on `coverage_head`
(the head is `coverage_head` or only a master merge on top of it), or on the current head (the head is
only a master merge on top of `coverage_head`). Do not shortcut `reviewHead === head` into "only master
merge". `epicReviewHead` is not the anchor but it is a required safety check: it catches an approval that
was never published on this head, so do not remove the comparison with `coverage_head`. The merge gate and
`readyForReview` share `headAccepted`.

- **The PM runbook reaches the model as a line in the message, not in the system prompt.** In `pm` mode
  `QueueScheduler` prepends a conditional line (`PmRunbook::line`) to every message, and the epic run prompts carry the
  same line; `--append-system-prompt` is ignored on `--resume`, the message is not. The line holds only
  absolute paths (the runbook, and `PROMPTS_DIR/pm_runbook.md` when it exists), never the addition's text.
  The pipeline config is the first ```` ```json ```` block of the runbook (`PmRunbook::config`): a local
  `prompts/pm_runbook.md` replaces `.dist` whole, the addition replaces `stages` and overrides
  `skip_allowed` by key. An invalid config in either falls back to `.dist` with a warning; an invalid
  `.dist` is a run error. The bot does not run the stages, but the gate snapshots the config when a
  run starts and, before an issue is created, checks the completed calls of the required stages against
  that snapshot. Map a run to its `skip_allowed` key only through `PmRunbook::skipKeyForMode` /
  `skipKeyForEpicRun`: the epic run `review` is the `epic_review` key, not `review`.
