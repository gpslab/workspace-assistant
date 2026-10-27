# Prompts

The bot's prompts are files in `prompts/`: 19 texts (`prompts/*.md.dist`) and 5 built-in agents
(`prompts/agents/*.md.dist`). This page says which file is used in which run, how the prompt of a run is
assembled from the files, the settings in `.env` and the texts built in code, and how to override each
file. The settings are in [INSTALL.md](INSTALL.md), the built-in agents in
[TASKS.md](TASKS.md#built-in-agents).

## Catalog

There are three kinds of file:

- *contract*: the rules the bot relies on. A local `prompts/<name>.md` replaces the `.dist`, and the
  checked ones (see Overrides) warn about it in the reply.
- *persona*: the character of a mode. The `PROMPTS_DIR` layer (`common.md`, `<mode>.md`) sits on top of
  `prompts/<name>.md` and the `.dist`. No warning.
- *setting*: a local `prompts/<name>.md` replaces the `.dist` silently. There is no `PROMPTS_DIR` layer.

| File                                | Kind     | Used in                                                             | Placeholders                    |
|-------------------------------------|----------|---------------------------------------------------------------------|---------------------------------|
| `common`                            | contract | System prompt of every run (`SystemPrompt`)                         |                                 |
| `common_limits`                     | contract | System prompt of every mode except `arch` (`SystemPrompt`)          |                                 |
| `contract_review`                   | contract | System prompt of the `review` mode (`SystemPrompt`)                 |                                 |
| `language_line`                     | contract | End of the system prompt of every run (`SystemPrompt`)              | `{language}`                    |
| `session_update`                    | contract | Resumed session whose system prompt changed (`ClaudeRunner`)        | `{prompt}`                      |
| `review_status_reason`              | contract | Stop hook of the `review` mode (`ReviewStatusHook`)                 | `{verdict}`, `{state}`, `{problem}` |
| `review_status_reason_no_verdict`   | contract | Stop hook of the `review` mode, no verdict in the reply             |                                 |
| `review_status_reason_steps`        | contract | Stop hook of the `review` mode, after the reason                    |                                 |
| `review_status_reason_ids`          | contract | Stop hook of the `review` mode, ids of cold findings are missing    | `{ids}`                         |
| `compact_summarize_prompt`          | contract | `/compact`, phase 1 (`Bot`)                                         |                                 |
| `compact_reseed_prefix`             | contract | `/compact`, phase 2, before the summary (`Bot`)                     |                                 |
| `compact_reseed_suffix`             | contract | `/compact`, phase 2, after the summary (`Bot`)                      |                                 |
| `epic_runbook`                      | contract | Epic runs (`Epic\Run`): instructions Claude reads itself            |                                 |
| `pm_runbook`                        | contract | `pm` mode and epic runs (`PmRunbook`): the pipeline config          |                                 |
| `persona_common`                    | persona  | Common persona block of every run (`SystemPrompt`)                  |                                 |
| `role_arch`                         | persona  | Role block of the `arch` mode (`SystemPrompt`)                      |                                 |
| `role_review`                       | persona  | Role block of the `review` mode (`SystemPrompt`)                    |                                 |
| `role_pm`                           | persona  | Role block of the `pm` mode (`SystemPrompt`)                        |                                 |
| `default_file_prompt`               | setting  | A file sent without text (`Inbox`)                                  |                                 |
| `agents/acceptor`                   | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/critic`                     | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/editor`                     | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/scope-check`                | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |
| `agents/verifier`                   | setting  | Built-in agent (`BuiltinAgents`)                                    |                                 |

The agent files are passed with `--agents`; an error in an agent file is still reported in the reply. An
agent also yields to an agent with the same `name:` in `.claude/agents/`, see
[Built-in agents](TASKS.md#built-in-agents).

## Assembly

### System prompt of every Claude run

`SystemPrompt::build` assembles parts 1–5; `ClaudeRunner` appends 6 and 7 and passes the result with one
`--append-system-prompt`. The parts, in order:

1. `common`;
2. `common_limits` (all modes except `arch`);
3. `contract_review` (`review` mode only);
4. the common persona block;
5. the role block of the mode;
6. `CLAUDE_APPEND_SYSTEM_PROMPT` from `.env`;
7. `language_line`, always last, so it wins over a persona.

### Chat message

In the `pm` mode the message is prefixed by the PM runbook line. It is built in code by `PmRunbook::line`:
the path of `pm_runbook` plus `PROMPTS_DIR/pm_runbook.md` if it exists. No file overrides the line itself.
A resumed session whose system prompt changed gets `session_update` (the whole new prompt is `{prompt}`) in
front of the message.

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
the PM runbook line, and the paths and data of the run.

### `/compact`

Phase 1 sends `compact_summarize_prompt` into the current session. Phase 2 starts a new session with
`compact_reseed_prefix`, the summary and `compact_reseed_suffix`. Built-in agents are not passed in either
phase.

### Stop hook of the `review` mode

`ReviewStatusHook` answers with `review_status_reason` (`{verdict}`, `{state}`, `{problem}`) or
`review_status_reason_no_verdict`, followed by `review_status_reason_steps`; and with
`review_status_reason_ids` (`{ids}`) when the review body lacks ids of cold findings.

### Built-in agents

`prompts/agents/*.md.dist` are passed with `--agents` to every run except `/compact`.

## Overrides

- **Local file.** `prompts/<name>.md` (gitignored, like `.env` next to `.env.dist`) replaces
  `prompts/<name>.md.dist` as a whole. A missing `.dist` is a broken installation.
- **Contract names.** The 14 names of `PromptFiles::CONTRACT` can be overridden. After an update, diff your
  overrides against the `.dist`.
- **Checks and warnings.** Only the files of the system prompt (`common`, `common_limits`,
  `contract_review`, `language_line`, the persona files), `session_update`, `pm_runbook` and the agent files are checked, and
  a run that uses them adds a warning to the reply for: an empty or unreadable local file (the `.dist` is
  used instead), a contract file overridden locally, a placeholder that differs from the `.dist` and a
  placeholder the code does not fill. The other files (`review_status_reason*`,
  `compact_*`, `default_file_prompt`, `epic_runbook`) are used as written, with no warning: an override that
  drops or misspells a placeholder fails silently, so keep the placeholders of the `.dist` exactly.
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
  auto-commit publishes the edit without review. When a run changes files under `PROMPTS_DIR`, the reply
  carries a warning listing them.
- **Agents.** See [Built-in agents](TASKS.md#built-in-agents).
