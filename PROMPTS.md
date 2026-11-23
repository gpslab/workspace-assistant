# Prompts

The bot's prompts are files in `prompts/`: 24 texts (`prompts/*.md.dist`) and 6 built-in agents
(`prompts/agents/*.md.dist`). This page says which file is used in which run, how the prompt of a run is
assembled from the files, the settings in `.env` and the texts built in code, and how to override each
file. The settings are in [INSTALL.md](INSTALL.md), the built-in agents in
[TASKS.md](TASKS.md#built-in-agents).

## Catalog

There are three kinds of file:

- *contract*: the rules the bot relies on. A local `prompts/<name>.md` replaces the `.dist`, and
  every contract file warns about it in the reply of every run (see Overrides).
- *persona*: the character of a mode. The `PROMPTS_DIR` layer (`common.md`, `<mode>.md`) sits on top of
  `prompts/<name>.md` and the `.dist`. No warning.
- *setting*: a local `prompts/<name>.md` replaces the `.dist` silently. There is no `PROMPTS_DIR` layer.

| File                                | Kind     | Used in                                                             | Placeholders                    |
|-------------------------------------|----------|---------------------------------------------------------------------|---------------------------------|
| `common`                            | contract | System prompt of every run (`SystemPrompt`)                         | `{engine_session}`, `{engine_subagent}` |
| `common_limits`                     | contract | System prompt of every mode except `arch` (`SystemPrompt`)          |                                 |
| `contract_review`                   | contract | System prompt of the `review` mode (`SystemPrompt`)                 | `{engine_review_check}`         |
| `language_line`                     | contract | End of the system prompt of every run (`SystemPrompt`)              | `{language}`                    |
| `session_update`                    | contract | Resumed session whose system prompt changed (`ClaudeRunner` only)   | `{prompt}`                      |
| `secret_blocked`                    | contract | Chat run started while the commit is blocked by a secret (`QueueScheduler`) | `{findings}`                    |
| `review_status_reason`              | contract | Stop hook of the `review` mode (`ReviewStatusHook`)                 | `{verdict}`, `{state}`, `{problem}` |
| `review_status_reason_no_verdict`   | contract | Stop hook of the `review` mode, no verdict in the reply             |                                 |
| `review_status_reason_steps`        | contract | Stop hook of the `review` mode, after the reason                    |                                 |
| `review_status_reason_ids`          | contract | Stop hook of the `review` mode, ids of cold findings are missing    | `{ids}`                         |
| `review_status_reason_steps_gitlab` | contract | Like `review_status_reason_steps`, when the hook runs with `--forge=gitlab` |                          |
| `review_status_reason_ids_gitlab`   | contract | Like `review_status_reason_ids`, when the hook runs with `--forge=gitlab` | `{ids}`                    |
| `compact_summarize_prompt`          | contract | `/compact`, phase 1 (`QueueScheduler`)                              |                                 |
| `compact_reseed_prefix`             | contract | `/compact`, phase 2, before the summary (`CompactFlow`)             |                                 |
| `compact_reseed_suffix`             | contract | `/compact`, phase 2, after the summary (`CompactFlow`)              |                                 |
| `epic_runbook`                      | contract | Epic runs (`Epic\Run`): instructions Claude reads itself            |                                 |
| `pm_runbook`                        | contract | `pm` mode and epic runs (`PmRunbook`): the pipeline config          |                                 |
| `persona_common`                    | persona  | Common persona block of every run (`SystemPrompt`)                  |                                 |
| `role_arch`                         | persona  | Role block of the `arch` mode (`SystemPrompt`)                      |                                 |
| `role_review`                       | persona  | Role block of the `review` mode (`SystemPrompt`)                    |                                 |
| `role_pm`                           | persona  | Role block of the `pm` mode (`SystemPrompt`)                        |                                 |
| `default_file_prompt`               | setting  | A file sent without text (`Inbox`)                                  |                                 |
| `forge_gitlab`                      | setting  | End of the system prompt of every mode when `FORGE=gitlab` (`SystemPrompt`) |                         |
| `memory_context`                    | setting  | System prompt of an OpenCode or Kimi run with `MEMORY_3LAYER_BIN` set (`Memory\MemoryContext`) | `{memory_context}`     |
| `agents/acceptor`                   | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/critic`                     | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/editor`                     | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/scope-check`                | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/security`                   | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/verifier`                   | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |

The agent files are passed with `--agents`; an error in an agent file is still reported in the reply. An
agent also yields to an agent the engine loads with the same `name:` (`.claude/agents/` and
`~/.claude/agents/` on Claude; on OpenCode additionally the engine-neutral `.agents/agents/`), see
[Built-in agents](TASKS.md#built-in-agents). On OpenCode the same files are rendered as
`agents/<name>.md` in the per-run config directory, and so are the instance's own agents
(`InstanceAgents`), whose `tools:` become the OpenCode permission allowlist of that agent — an agent
without `tools:` is unrestricted, as it is on Claude. `model:` inside them is ignored, because that engine
has one model for everything, `OPENCODE_MODEL`.

## Assembly

### System prompt of every run

`SystemPrompt::build` assembles parts 1–6; the runner appends 7–9 and hands the result to the CLI. The
parts, in order:

1. `common`;
2. `common_limits` (all modes except `arch`);
3. `contract_review` (`review` mode only);
4. the common persona block;
5. the role block of the mode;
6. `forge_gitlab` (only with `FORGE=gitlab`; it maps the `gh` commands of the prompts to `glab`, and with
   `FORGE=github` nothing is added);
7. `memory_context` — the memory of the instance (`MEMORY_3LAYER_BIN`), on the engines that read no user
   scope (OpenCode, Kimi) and only when the setting is set (see [Memory](#memory)); nothing is added
   otherwise;
8. `CLAUDE_APPEND_SYSTEM_PROMPT` from `.env`;
9. `language_line`, always last, so it wins over a persona.

`ClaudeRunner` passes the whole text with one `--append-system-prompt`. `OpencodeRunner` writes it to
`system-prompt.md` in its per-run config directory (`state/opencode-<runId>/`) and lists that file in the
`instructions` of the rendered `opencode.json`; it also adds the managed repository's `CLAUDE.md` to the
same list, but only when there is no `AGENTS.md` (OpenCode reads `AGENTS.md` itself and never `CLAUDE.md`).
`KimiRunner` renders the same parts into the run home's `agent.md`, the file the run passes to
`--agent-file`, right after the `${base_prompt}` token Kimi substitutes. The parts and the order are the
same on every engine. OpenCode takes no command hooks, so the run directory
also lists the plugin (`opencode-plugin/`) in the `plugins` of `explicit.json`: a gated run (`pm`, `review`
and the epic runs) gets the same PHP hooks there as on Claude Code, so the gates hold on both engines. Every
run starts the secret guard: before the gate in a gated run, alone in a run with no gate kind (`arch`,
`/compact`).

#### Engine tokens

`SystemPrompt::build` replaces three tokens in ONE substitution step over the assembled text
(`SystemPrompt::engineTokens`), with the wording of the engine the instance runs (`ENGINE`):

- `{engine_session}` — how the session is identified to the model. `ENGINE=claude` (the default):
  `a Claude Code session`; `ENGINE=opencode`: `an OpenCode session`; `ENGINE=kimi`:
  `a Kimi Code session`.
- `{engine_review_check}` — what enforces that the ids of the cold findings are in the published review
  body. `ENGINE=claude`: `the Stop hook does not read a body from a file (\`-F body=@file\`, \`--input\`) and
  blocks the turn until every id is in the body`; on `ENGINE=opencode` and `ENGINE=kimi`: `the bot reads
  the published review after the run and does not see a body from a file (\`-F body=@file\`, \`--input\`):
  it repeats the turn once when an id is missing from the body`. The wording follows the mechanism:
  Kimi's Stop hook cannot continue a `-p` turn, so the Bot takes that decision after the run there,
  exactly as on OpenCode.
- `{engine_subagent}` — how a sub-agent is called. Empty on `ENGINE=claude` and `ENGINE=opencode`, so
  their prompt is unchanged; on `ENGINE=kimi`: `A sub-agent is called with the \`Agent\` tool, naming the
  agent in \`subagent_type\`; wait for its result before the next step.`

These texts are why the tokens exist: `common` and `contract_review` are `load`-ed, not rendered
(`PromptFiles::render`), so without that step a placeholder in them would reach the model unfilled. The
substitution runs over the whole assembled text, so a persona file may use the tokens as well. On Claude
Code the values are that text exactly as it was before the tokens, byte for byte; on OpenCode and Kimi
the wording names the mechanics of those engines, which have no Stop hook that can continue a turn (and
no `run_in_background` wording in the shared text).

#### Memory

OpenCode and Kimi read no user scope: a run of either gets no memory from the hooks or the
`~/.claude/CLAUDE.md` of the deployment, the way a Claude Code run does. With `MEMORY_3LAYER_BIN` set the
bot fills that gap. It runs
`memory-3layer context`, which prints the PATHS of the instance's memory files (L0, the project's
SESSION.md and project.md); the bot reads those files itself and injects their CONTENT as part 7, so the run
needs no permission to read outside the managed repository. Only content is injected, never the paths.

Each file becomes a block of its own: a `### <file name>` heading and the text. A file longer than
`MEMORY_3LAYER_CONTEXT_MAX_BYTES` is injected from its newest end, and its heading carries
`(cut: the newest part only)` — a long-lived project has a SESSION.md of hundreds of KB, and the model must
not read a cut as the whole file. The headings and that marker are built in code next to the rendered
`{memory_context}`. Both the call and the reading are best-effort: a client that is missing, fails or hangs
gives no block at all and one line in the log of the bot, never a failed or delayed run.

With `ENGINE=claude` the part is never added, whatever the setting says: the user scope delivers the memory
of the instance there. The block is not part of the prompt hash of a Kimi session either: it is data that
changes between runs, so hashing it would make every resume look like a changed prompt. On Kimi the memory
is a snapshot taken when the session is created — it goes into the agent file of a new session and stays
there — and a fresh snapshot arrives only after a `/new`, a compaction reseed or an engine fingerprint
change; a resumed session keeps the snapshot it was created with. OpenCode re-reads its `instructions` on
every run, so there the block is always part of the rendered prompt. The background pull before a run and
push after it are in [internals.md](.claude-docs/internals.md#memory); they do not touch the prompt of a
run.

### Chat message

In the `pm` mode the message is prefixed by the PM runbook line. It is built in code by `PmRunbook::line`:
the path of `pm_runbook` plus `PROMPTS_DIR/pm_runbook.md` if it exists. No file overrides the line itself.
A resumed session whose system prompt changed gets `session_update` (the whole new prompt is `{prompt}`) in
front of the message. On OpenCode it is never used: the runner renders the instructions anew on every run
and OpenCode applies the change to a resumed session itself. On Kimi it is used: `--agent-file` is rejected
together with `-S`, so a resumed session keeps the prompt it was created with and the update block is the
only way a changed instructions file reaches it (it arrives as a user message, and the role it names holds
on the following runs).
While the bot's commit is blocked by a secret (see [README](README.md#commit-gate)), the next chat run gets
`secret_blocked` in front of the message: the findings as `- file:line (type)` lines, without values (`{findings}`,
built in code), and the instruction to remove the secret and rewrite an unpushed commit that holds it.

### Files without text

`Inbox` uses `default_file_prompt`:

- for a single file it replaces the missing caption;
- for a request merged from several messages none of which has text, it is put once in front of the whole
  request;
- if any part has text, it is not used.

### Epic runs

`tick` and `final` run in the `pm` mode, `review` in the `review` mode, always in a new session. The system
prompt is the one above. The message is built in code by `Epic\Run::prompt()`, no file overrides it: a
pointer to `epic_runbook` (Claude reads the file itself), the text of `PROMPTS_DIR/epic.md` if present,
the PM runbook line, and the paths and data of the run. Both engines that run epics use this same assembly: on
OpenCode the run is gated by the shim of its run kind (`tick`, `final` or `epic_review`) and its model is
`OPENCODE_MODEL`, since `MODEL_EPIC_*` is a Claude Code key. A Kimi instance runs no epic at all: the
gated modes and the epics are refused while this engine has nowhere to run their gates, so this assembly
is never used there.

With `AUTO_COMMIT=0` the journal line reads "write it to disk right after each external action; do not
commit or push it" instead of "commit and push it right after each external action", and one more line
overrides, for this run, the runbook rules that commit: "Journal — immediately", the commit of decision
documents and the commit of the report. `epic_runbook` itself is the same in both modes.

### `/compact`

Phase 1 sends `compact_summarize_prompt` into the current session. Phase 2 starts a new session with
`compact_reseed_prefix`, the summary and `compact_reseed_suffix`. Built-in agents are not passed in either
phase.

### Stop hook of the `review` mode

`ReviewStatusHook` answers with `review_status_reason` (`{verdict}`, `{state}`, `{problem}`) or
`review_status_reason_no_verdict`, followed by `review_status_reason_steps`; and with
`review_status_reason_ids` (`{ids}`) when the review body lacks ids of cold findings. The hook script gets
the forge from the `--forge=<FORGE>` argument after the state directory (it does not load the config; a missing
or unknown value means github): with `--forge=gitlab` the `_gitlab` variants of `review_status_reason_steps` and
`review_status_reason_ids` are used instead, and the hardcoded fallback names `glab mr note`.

Whoever asks for the status — the Claude Code Stop hook during the turn, or the Bot after a review run on an
engine that has no Stop hooks — asks with that same text; in the second case the text is the prompt of the one
repeated run the Bot starts in the same session.

### Built-in agents

`prompts/agents/*.md.dist` are passed with `--agents` to every run except `/compact`. On OpenCode the same
files go into the per-run config directory (`agents/<name>.md`), not into the argv.

### Security review on demand

The `security` agent is read-only and needs no mode or command of its own: ask for it in the message
to the bot in any mode that can call agents.

- **Check the security of a PR or task.** "Call the `security` agent on PR #N of <repository>": it reads the diff
  of that PR (or of the merged PRs of a task) and returns findings with `severity` and evidence.
- **Audit the project before a release.** "Call the `security` agent on the whole repository": it reads the
  repository, runs `composer audit --locked` and `npm audit` and returns findings.

In the final check of an epic the runbook calls it on the merged PR diffs of the epic. The final-check prompt
states how many tails rounds are used (`Tails rounds: X of N`), so the run knows when a confirmed finding can
no longer become an extra task.

## Overrides

- **Local file.** `prompts/<name>.md` (gitignored, like `.env` next to `.env.dist`) replaces
  `prompts/<name>.md.dist` as a whole. A missing `.dist` is a broken installation.
- **Contract names.** The 17 names of `PromptFiles::CONTRACT` can be overridden. After an update, diff your
  overrides against the `.dist`.
- **Checks and warnings.** Two separate things happen:
  - *Override warning.* A local override of any of the 17 contract names is reported in the reply of every
    run, whatever the mode or kind of run. `default_file_prompt` is a setting and gets no warning.
  - *Content checks.* Only the files of the system prompt (`common`, `common_limits`, `contract_review`,
    `language_line`, the persona files), `session_update`, `pm_runbook` and `memory_context` are checked,
    and a run that
    uses them adds a warning for: an empty or unreadable local file (the `.dist` is used instead), a
    placeholder that differs from the `.dist` and a placeholder the code does not fill. For
    `review_status_reason*`, `compact_*`, `secret_blocked` and `epic_runbook` a placeholder mistake passes silently (only the
    override warning appears), and `default_file_prompt` gets no checks, so keep the placeholders of the
    `.dist` exactly.
  - *Engine tokens in a local override.* `common` carries `{engine_session}` and `{engine_subagent}`,
    `contract_review` carries `{engine_review_check}` (see [Engine tokens](#engine-tokens)). A local
    `prompts/common.md` or `prompts/contract_review.md` written before them has no such placeholder, and such an instance gets
    the "placeholders differ from `.dist`" warning once in the reply of every run, until the local file
    carries the same tokens. The step replaces the tokens of the assembled text, so a local file without
    them is used as it is.
  - *Agent files.* Agent bodies are loaded, not rendered, so there are no placeholder warnings; only an
    empty or unreadable local file and a missing `name:` are reported.
- **`PROMPTS_DIR` persona layer.** The directory is set in `.env`, relative to `REPO_PATH`, for example
  `context/prompts`. First found wins:
  - common block: `PROMPTS_DIR/common.md` → `prompts/persona_common.md` → `prompts/persona_common.md.dist`;
  - role block: `PROMPTS_DIR/<mode>.md` → `prompts/role_<mode>.md` → `prompts/role_<mode>.md.dist`, where
    `<mode>` is `arch`, `review` or `pm`.
- **Additions.** `PROMPTS_DIR/epic.md` is added to the message of an epic run, `PROMPTS_DIR/pm_runbook.md`
  to the PM runbook (see [TASKS.md](TASKS.md)). Neither replaces `epic_runbook` or `pm_runbook`.
- **Re-reading.** Every file is read on every run: an edit applies from the next run, without a restart.
  The placeholders of a `.dist` change only by addition.
- **Edits of `PROMPTS_DIR`.** The directory lives in the managed repository, so a run can edit it and the
  auto-commit (`AUTO_COMMIT=1`) publishes the edit without review. When a run changes files under `PROMPTS_DIR`, the reply
  carries a warning listing them.
- **Agents.** See [Built-in agents](TASKS.md#built-in-agents).
