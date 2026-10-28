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
| User-facing description, bot commands                                    | [../README.md](../README.md)     |
| Installation, configuration (`.env` keys), running as a service          | [../INSTALL.md](../INSTALL.md)   |
| Working out a task: PM runbook, built-in agents, pipeline config, gate   | [../TASKS.md](../TASKS.md)       |

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
| `src/Bot.php`                                    | Main loop: polling, the front controller, wiring and the routing of a `RunOutcome`; implements `LoopControl`; one-line delegates to `QueueScheduler` and `JobRunner`  |
| `src/JobRunner.php` / `RunOutcome.php`           | The Claude process lifecycle (`launchJob`, `poll`, `cancelTopic`, progress, early session save); owns `RunState`, implements `JobLauncher` and `RunControl`; `poll()` returns a `RunOutcome` that `Bot` routes |
| `src/Queue/QueueScheduler.php`                   | The request queue: enqueueing, `startQueueHead`, retries, pauses (Claude limit, dirty tree), `recoverQueueOnStart`; implements `QueueControl`, starts runs through `JobLauncher` |
| `src/RunState.php`                               | Holder of the run state (`activeJob`, `jobContext`, `repoSnapshot`, the tree snapshot and its error): one instance, shared by `Bot` and the classes built from it |
| `src/RunResult.php`                              | The reply to a finished run: `sendResult`, auto-commit message and link, prompt-files warning; implements `DecisionHost` |
| `src/CompactFlow.php` / `JobLauncher.php` / `RunKind.php` | `/compact` phases after their runs finish (`finishSummarize`, `finishReseed`); `JobLauncher` is the port that starts phase 2 (`JobRunner` implements it); `RunKind` holds the run kinds (`KIND_*`) |
| `src/Command/Router.php`                         | Parses `/cmd@bot argument`, resolves aliases, answers `command.unknown`; registration order is the menu order                    |
| `src/Command/Menu.php`                           | Builds and registers the per-locale and default command menus from the router (`setMyCommands`)                                  |
| `src/Command/*Command.php`                       | One class per moved command (`Help`, `Verbose`, `Mode`, `Lang`, `Epic`, `New`, `Compact`, `Cancel`, `Queue`, `Stats`, `Restart`) |
| `src/Command/QueueControl.php` / `RunControl.php` / `LoopControl.php` / `LocaleResolver.php` | Ports: the queue (`QueueScheduler`), the current run and `cancelTopic` (`CancelResult`) (`JobRunner`), the stop request (`Bot`), locale lookup (`Command\Locales`) |
| `src/Callback/Handler.php`, `*Handler.php`       | Inline keyboard callbacks in an ordered list: `ModeHandler`, `LangHandler`, `EpicHandler` (stateless), then `DirtyTreeDecision` |
| `src/Callback/DirtyTreeDecision.php` / `DecisionHost.php` | Commit/discard decision on a dirty tree (offer, buttons, AUTO_COMMIT=0 refusal, unknown tree state); `DecisionHost` is the port `RunResult` implements for the prompt-files warning and `commitUrl` |
| `src/Queue/QueueTexts.php`                       | `topicLabel`, `describeItem` and `thinkingText`: texts about queue items and topics, shared by `Bot`, `QueueScheduler` and the commands |
| `src/SubRepoSync.php`                            | Nested repositories: pull before a run, HEAD snapshot, commit links, sync profile                                                |
| `src/Delivery.php`                               | Sending replies (topic fallback), the outbox, summary truncation                                                                 |
| `src/Inbox.php`                                  | Incoming files: download into the inbox, file/queue prompts, inbox cleanup                                                       |
| `src/Epic/ChatSurface.php`                       | The `/epic` command, its menu and inline keyboard, and the `epic:` callbacks                                                     |
| `src/Keyboards.php` / `Keyboard.php`             | Pure builders of every inline keyboard (language, mode, epic menu/candidates/confirmation, decision, removal) and their result: markup plus a "button skipped by the 64-byte `callback_data` limit" flag; callers send it and keep their own reaction to a skip |
| `src/ModeAndLang.php`                            | The `/mode` and `/lang` commands, their keyboards and the `mode:`/`lang:` callbacks                                              |
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
| `src/Epic/RunPrompt.php` / `RunLifecycle.php` / `EpicNotifier.php` | Epic run prompt; finish and failure of an epic run; block/notify an epic topic |
| `src/Epic/Gates.php` / `MergeGate.php`           | Merge, post-merge actions, CHANGELOG release, review/tail ceilings                                                               |
| `src/Epic/ColdFindings.php`                      | Cold reviewer findings: file, coverage check, prompt lines                                                                       |
| `src/Epic/Card.php` / `src/EditStatus.php`       | Epic status card (one silent message, edited in place); `editMessageText` result                                                 |
| `prompts/*.md.dist`                              | All prompt texts; `epic_runbook.md.dist`: instructions for an epic run; reference: [PROMPTS.md](../PROMPTS.md)                   |

> When you find a new non-obvious trap, add it to [gotchas.md](gotchas.md); operational findings go to
> [operations.md](operations.md).
