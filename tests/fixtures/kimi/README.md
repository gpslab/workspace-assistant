# Kimi Code fixtures

Real output of Kimi Code CLI **2.7.2** (`kimi`, `metadata.protocol_version` `1.5` in `wire.jsonl`), captured with
`kimi -p <prompt> --output-format stream-json` under a per-run `KIMI_CODE_HOME` (own `config.toml`, `credentials/`, `sessions/` and
`oauth/` symlinked to a logged-in home), `KIMI_CODE_NO_AUTO_UPDATE=1`, model `kimi-code/kimi-for-coding`, on 2026-11-21 and 2026-11-22.
They are the contract the Kimi stream parser, the `wire.jsonl` reader and the gate readers are tested against: neither format is documented
upstream and `wire.jsonl` is private. Local paths, user and host names are replaced with `REDACTED` / `/work`; the `systemPrompt` of
`profile.bind` (Kimi's base prompt) is replaced by its length and SHA-256, and the tool lists by their names (full list: `tools.json`).

The facts these files demonstrate are in `.claude-docs/internals.md`, section "Kimi (verified facts)".

## Layout

- `run/<scenario>.stdout.jsonl` — stdout of `kimi -p … --output-format stream-json`; `run/<scenario>.stderr.txt` — stderr, only when non-empty.
- `wire/<scenario>/<agent>.jsonl` — `agents/<agent>/wire.jsonl` of the session after the run (`main`, `agent-0`, …). The file is append-only and
  cumulative across the runs of a session.
- `hooks/<scenario>.hook-records.txt` — one JSON record per hook call of the probe hook (`event`, `tool_call_id`, `tool_name`, `agent_name`).
  Named without `.log`: the repository `.gitignore` swallows `*.log`.
- `tools.json` — the 26 tools of 2.7.2 with the names of their parameters.

## Scenarios and exit codes

| Scenario | Exit code | Seconds | What it shows |
|---|---|---|---|
| `01-plain` | 0 | 4 | A plain prompt, no tools |
| `02-tool` | 0 | 6 | One `Read` call; hooks record all six events (`SessionStart`, `PreToolUse`, `PostToolUse`, `SubagentStart`, `SubagentStop`, `Stop`) |
| `03-resume-1` | 0 | 6 | First run of a session (no tools) |
| `03-resume-2` | 0 | 5 | `-S <id>`: the same session remembers the first run |
| `04-bad-model` | 1 | 1 | `-m` with an alias that is not in `config.toml` |
| `05-bad-session` | 1 | 1 | `-S` with an id that does not exist |
| `06-occupied-first` | 0 | n/a | A run holding the session (`sleep 45` in a tool) |
| `06-occupied-second` | 4 | 2 | `-S` on that live session: exit 4, `session.occupied` on stdout |
| `07-hook-deny` | 0 | 9 | `PreToolUse` hook on `Bash` exits 2 with a reason on stderr: the call is blocked, the model sees the reason |
| `08-hook-exit1` | 0 | 8 | The same hook exits 1: the call runs |
| `09-hook-exit255` | 0 | 7 | The hook exits 255: the call runs |
| `10-hook-signal` | 0 | 7 | The hook kills itself with SIGKILL: the call runs |
| `11-hook-timeout` | 0 | 8 | The hook sleeps 5 s with `timeout = 1` and would exit 2: the call runs |
| `12-hook-missing` | 0 | 11 | The hook command does not exist: the call runs |
| `13-subagent-foreground` | 0 | 11 | A foreground `critic` sub-agent (the model called it twice in this run) |
| `14-subagent-background` | 0 | 15 | `run_in_background: true` sub-agent: `wire/*/main.jsonl` has `status: running`, stdout says `completed` |
| `15-subagent-bash` | 0 | 11 | A sub-agent whose tools include `Bash`: `PreToolUse` fires for its call; the payload has no agent id |
| `20-killed-mid-tool` | killed | 14 | SIGKILL of the `kimi` process 14 s into a `sleep 60` tool call |
| `20-resume-after-kill` | 0 | 4 | `-S` on the killed session: works, exit 0 |
| `21-static-deny` | 0 | 7 | A `[[permission.rules]]` deny of Kimi's own config plus a `PreToolUse` recording hook: the hook runs first, then the rule denies |
| `22-pending-cron` | timeout | 150 | `CronCreate` for a far-future one-shot job: `-p` does not exit (killed by the harness after 150 s) |
| `23-role-change-1-arch` | 0 | 8 | `--agent-file` with the bot's real `arch` prompt |
| `23-role-change-2-pm-update` | 0 | 8 | `-S` with the instructions-update block carrying the `pm` prompt: the answer follows the new role |
| `23-role-change-3-after` | 0 | 59 | A following run without a block: the role holds |
| `24-home-agents-precedence` | 0 | 12 | `critic` defined in `$HOME/.agents/agents` and in `extra_agent_dirs`: the extra directory wins (`wire/*/agent-0.jsonl` has its prompt) |
| `25-resumed-gated-1` | 0 | 8 | Run 1 of a session with one `Bash` call and recording hooks |
| `25-resumed-gated-2` | 0 | 7 | Run 2 (`-S`): `wire/.../main.jsonl` still contains the run-1 `tool.call`, the hook records are only run 2's |
| `25-resumed-gated-3` | 0 | 7 | Run 3: the same, cumulative |
| `30-mock-auth` | 1 | 3 | Synthetic server: HTTP 403 (`provider.auth_error`) |
| `30-mock-quota` | 1 | 2 | Synthetic server: HTTP 429 with a quota message (`APIProviderQuotaExhaustedError`, not retried) |
| `30-mock-rate` | 1 | 3 | Synthetic server: HTTP 429 rate limit (retried: `turn.step.retrying` on stdout) |
| `30-mock-server` | 1 | 3 | Synthetic server: HTTP 500 (retried) |

`killed` — the harness sent SIGKILL to `kimi`; `timeout` — the harness killed it after the time limit. The `30-mock-*` scenarios use a local
synthetic HTTP server (a dummy API key, `KIMI_LOOP_MAX_ATTEMPTS_PER_STEP=2`) in front of the real client: the error objects are the real
client's, the server bodies are made up (the real Kimi error bodies of rate limit and quota were not obtained). The 403 of the real account
(`provider.auth_error`, "Your current subscription does not have access to Kimi Code right now…") was observed once on 2026-11-21 before the
subscription was paid; `30-mock-auth` reproduces its shape.

Not captured as files: the refresh-token rotation (see the facts section) and MCP tools (no MCP server was available).
