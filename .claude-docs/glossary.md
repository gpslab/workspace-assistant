---
tags: [memory/repo, glossary]
---

# Glossary — Workspace Assistant

Terms and names that are easy to misread. The obvious is not duplicated.

| Term                          | What it actually is                                                                                                                                         |
|-------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Managed repository**        | The repository the bot pulls, commits and pushes: `REPO_PATH`. **Not** the bot's own repository. Claude runs with it as the working directory.              |
| **Bot's repository**          | The code of Workspace Assistant itself (`gpslab/workspace-assistant`). The bot never commits it in response to messages.                                    |
| **`Bot`**                     | The class in `src/Bot.php` and the **main loop**: polling, queue, commands, git decisions. It is not an orchestrator.                                       |
| **epic**                      | A series of GitHub tasks with an execution order, approved by its author (`/epic`, plan in `EPICS_DIR`). The only word for a series of tasks. See [../EPICS.md](../EPICS.md). |
| **`Orchestrator`**            | `src/Epic/Orchestrator.php`: drives **one epic**: polls GitHub, queues ticks, does the deterministic steps. Nothing else is called an orchestrator.              |
| **tick**                      | An epic run queued by the `Orchestrator` when judgement is needed: an isolated `claude -p` run with a signal file.                                          |
| **Session**                   | A **Claude Code** session (`session_id` from the JSON output), not a Telegram session. Stored per `(chat_id, thread_id)`, continued with `--resume`; `/new` resets it. |
| **`thread_id`**               | Forum topic id; `0` means "no topic" (1:1 chat or General), never NULL. Each topic is a separate session.                                                  |
| **Mode**                      | `arch`, `review` or `pm`: which role block the system prompt carries. Sticky per `(chat_id, thread_id)`, fixed per queue item at enqueue time. Default `pm`.  |
| **Contract / persona**        | The two halves of a system prompt. Both ship in `prompts/*.md.dist`; a local `prompts/<name>.md` overrides one, and a contract override is always reported in the reply. The persona (common + role block) may also come from `PROMPTS_DIR` files.     |
| **`activeJob`**               | The single current `ClaudeRunner` in `Bot`. One run at a time; others wait in the queue, they are not rejected.                                             |
| **`jobContext`**              | Data of the active job: `chat_id`, `thread_id`, `thinking_id`, prompt, **`mode`** (the only source of the mode), `kind`, `queue_id`.                        |
| **Queue**                     | The bot's single FIFO in SQLite: a topic request with its mode fixed, or a `/new`/`/compact` barrier. Unique key `(chat_id, message_id)`.                   |
| **Decision**                  | SQLite record of the commit/discard buttons message. Queue pause on a dirty tree is derived from state, there is no in-memory flag.                         |
| **"Thinking…"**               | Placeholder message sent when a run starts and removed when it ends; its `message_id` is `thinking_id`.                                                     |
| **`RunnerState`**             | `Running` / `Done` / `Timeout` of the claude process. `Timeout` covers both the overall and the stall timeout.                                              |
| **Stall timeout**             | `CLAUDE_STALL_TIMEOUT`: no output for N seconds means hung. Separate from `CLAUDE_TIMEOUT`, the ceiling for the whole call.                                 |
| **verbose**                   | Not a log level: a per-chat flag that appends token usage to replies and sends the sync profile.                                                            |
| **`CLAUDE_EXTRA_ARGS`**       | Defaults to `--dangerously-skip-permissions`. See [context.md](context.md) for the threat-model consequences.                                               |
| **`Env` / `Config`**          | Hand-written (no vlucas/phpdotenv). Real environment variables override `.env`.                                                                              |
| **`Log`**                     | Writes to **STDERR**; `state/bot.log` exists only because the launcher redirects it.                                                                         |
| **`state/`**                  | Runtime directory (gitignored): `state.sqlite`, `bot.pid`, `bot.log`, `outbox/`, `inbox/`, `epics/`, `runs/`, `agents/`, `pm-gate/` (config snapshot `<run id>.json`, `.skip`, `.applied`), `pm-gate.log`. Override with `STATE_DIR`.                     |
| **Prompt files**              | Files in `PROMPTS_DIR` (`common.md`, `arch.md`, `review.md`, `pm.md`, `epic.md`) inside the managed repository; they shape future runs.                    |
| **Sub-repositories**          | Optional clones under `SUBREPOS_DIR` inside the managed repository; the bot pulls them before a run. Off when the variable is empty.                        |
