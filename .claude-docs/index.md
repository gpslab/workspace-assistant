---
tags: [memory/repo, index]
---

# .claude-docs — routing table (Workspace Assistant)

Which file to read for which task. Start here when unsure.

| Task / question                                                          | Read                             |
|--------------------------------------------------------------------------|----------------------------------|
| How the bot works: components, message flow, state, configuration        | [internals.md](internals.md)     |
| The bot hangs / "Thinking…" is silent / 409 / starting and updating it   | [operations.md](operations.md)   |
| `gh` is not authorized / the `check_gh_auth.sh` cron check               | [operations.md](operations.md)   |
| Editing code and unsure why something is done "oddly"                    | [gotchas.md](gotchas.md)         |
| Unfamiliar term, or unsure about entity names (Bot, Orchestrator, epic)  | [glossary.md](glossary.md)       |
| Who calls the bot, the threat model, deliberate design decisions         | [context.md](context.md)         |
| Epics: `/epic`, plan, journal, orchestrator, merge/release gates         | [../EPICS.md](../EPICS.md)       |
| PHP version, composer commands, MUST / MUST NOT                          | [../CLAUDE.md](../CLAUDE.md)     |
| User-facing description, bot commands, installation                      | [../README.md](../README.md)     |

## Code map

| File                                             | Role                                                                                                                             |
|--------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------|
| `bin/bot.php`                                    | Entry point: builds Config/Database/Telegram/Git, then `Bot::run`                                                                |
| `bin/ctl.sh`                                     | start/stop/restart/status/fg/logs (nohup, pid file)                                                                              |
| `bin/check_gh_auth.sh`                           | Cron: checks `gh auth status`, notifies in Telegram on failure                                                                   |
| `bin/env.sh`                                     | Bash `.env` parser (`load_env_file`) used by `check_gh_auth.sh`                                                                  |
| `bin/install.php`, `src/Installer.php`           | Writes `.env` (options or prompts), checks env/network, prints unit and cron                                                     |
| `src/InstallerServices.php`                      | Injectable process runner, HTTP, extension and TTY probes of the installer                                                       |
| `bin/review-stop-hook.php`                       | Claude Stop hook of the `review` mode (`ReviewStatusHook`)                                                                       |
| `bin/pm-gate-hook.php`                           | PreToolUse hook gating issue creation in `pm`/`review` and the epic runs (`tick`, `final`, `review`), fail-closed (`PmGateHook`) |
| `bootstrap.php`                                  | PSR-4 autoloader + `date_default_timezone_set('UTC')`                                                                            |
| `src/Bot.php`                                    | Main loop: polling, queue (`startQueueHead`), commands, git decisions                                                            |
| `src/ClaudeErrorText.php`                        | Claude error text for the chat; usage-limit parsing and reset moment (pause of the queue)                                        |
| `src/ClaudeRunner.php` / `ClaudeResult.php`      | Asynchronous `claude -p` via `proc_open`, timeouts; parsing of its output                                                        |
| `src/SystemPrompt.php` / `PromptFiles.php`       | Per-mode system prompts built from `prompts/*.md.dist`; loader and overrides                                                     |
| `src/PmRunbook.php`                              | PM runbook: pipeline config (json block) with layers, runbook line, run kind → skip key                                          |
| `src/BuiltinAgents.php`                          | Built-in sub-agents from `prompts/agents/*.md.dist`, passed via `--agents`                                                       |
| `src/GitManager.php`                             | git via `proc_open` (argument arrays), timeouts, parallel fetch                                                                  |
| `src/TelegramClient.php` / `src/Exception/*`     | Bot API over curl, md→HTML, long reply → `.md` file; failure types                                                               |
| `src/IncomingFile.php` / `AlbumBuffer.php`       | Incoming files of any type (pure functions); album buffer with debounce                                                          |
| `src/MarkdownConverter.php`                      | Markdown → Telegram HTML subset                                                                                                  |
| `src/Database.php`                               | SQLite: sessions `(chat_id, thread_id)`, queue, key-value state                                                                  |
| `src/SessionFiles.php`                           | Deletes Claude session transcripts on `/new` and `/compact`                                                                      |
| `src/Config.php` / `Env.php`                     | Configuration from `.env`, no vlucas/phpdotenv                                                                                   |
| `src/Locale.php` / `resources/locales/*.json`    | Text catalog: `en` is the reference and fallback, `{name}` placeholders                                                          |
| `src/Log.php` / `Timezone.php`                   | STDERR log with timestamp; time zone selection                                                                                   |
| `src/Epic/Plan.php` / `Signal.php` / `Run.php`   | Epic plan (JSON in markdown), run signal, run prompt/modes/paths                                                                 |
| `src/Epic/Orchestrator.php` / `src/GhClient.php` | Epic orchestrator: GitHub fingerprint, ticks, stall; `gh` wrapper                                                                |
| `src/Epic/Gates.php` / `MergeGate.php`           | Merge, post-merge actions, CHANGELOG release, review/tail ceilings                                                               |
| `src/Epic/ColdFindings.php`                      | Cold reviewer findings: file, coverage check, prompt lines                                                                       |
| `src/Epic/Card.php` / `src/EditStatus.php`       | Epic status card (one silent message, edited in place); `editMessageText` result                                                 |
| `prompts/*.md.dist`                              | All prompt texts; `epic_runbook.md.dist`: instructions for an epic run                                                           |

> When you find a new non-obvious trap, add it to [gotchas.md](gotchas.md); operational findings go to
> [operations.md](operations.md).
