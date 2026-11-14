# OpenCode fixtures

Real output of OpenCode **2.3.0** (`@opencode/cli`), captured with
`opencode run --standalone --format json --auto` and a small OpenRouter model. They are the contract the
OpenCode NDJSON parser and the OpenCode session-history reader are tested against: the formats are not
documented upstream. Local paths, account ids and key hashes are replaced with `REDACTED`.

The facts these files demonstrate are in `.claude-docs/internals.md`, section "OpenCode (verified facts)".

## `run/` — `opencode run --format json` stdout

| File                                        | Scenario                                                                                      | Exit code |
|---------------------------------------------|-----------------------------------------------------------------------------------------------|-----------|
| `plain.ndjson`                              | One shell call, then a text answer                                                            | 0         |
| `background-subagent.ndjson`                | A sub-agent that runs ~75 s: `background_wait`, then ~78 s without any line, then the answer  | 0         |
| `resume-1-instructions-alpha.ndjson`        | First run of a session with an instructions file                                              | 0         |
| `resume-2-instructions-beta.ndjson`         | Same session via `--session`, instructions file changed between the runs                      | 0         |
| `permission-denied-explicit-config.ndjson`  | `deny` rule in the explicit config file with `OPENCODE_DISABLE_PROJECT_CONFIG=1`              | 0         |
| `project-config-overrides-explicit.ndjson`  | Same deny, but without `OPENCODE_DISABLE_PROJECT_CONFIG`: the project `allow` wins            | 0         |
| `plugin-deny.ndjson`                        | A plugin `permission` `evaluate` hook sets `effect: "deny"` with a message                    | 0         |
| `plugin-throws.ndjson`                      | The same hook throws: the tool call fails                                                     | 0         |
| `single-subagent.ndjson`                    | One sub-agent; stdout of the `history/stage-single.json` run                                  | 0         |
| `parallel-subagents.ndjson`                 | Two sub-agents in one message; stdout of the `history/stage-parallel.json` run                | 0         |
| `sequential-subagents.ndjson`               | Two sub-agents in sequence; `background_wait` only for the first wait                         | 0         |
| `config-deny-hook-not-called.ndjson`        | Configured `deny` + logging plugin; log in `config-deny-hook-not-called.plugin.log`           | 0         |
| `error-provider-auth.ndjson`                | Invalid provider key (`provider.auth`, 401)                                                   | 1         |
| `error-provider-quota.ndjson`               | Not enough credits (`provider.quota`, 402)                                                    | 1         |

A provider rate-limit (429) error could not be obtained; there is no fixture for it.

## `history/` — session history as a plugin sees it

`ctx.session.context({ sessionID })`, dumped by a plugin from the `permission` `evaluate` hook of the shell command
`echo GATE`. Each file is `{ sessionID, command, messages }`.

| File                    | Scenario                                                                              |
|-------------------------|---------------------------------------------------------------------------------------|
| `stage-single.json`     | One sub-agent call, its report, then the command                                      |
| `stage-parallel.json`   | Two sub-agent calls in one message, before either report; both reports; the command   |
| `stage-sequential.json` | Call, report, second call after the first report, second report, the command          |
