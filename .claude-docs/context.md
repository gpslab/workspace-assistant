---
tags: [memory/repo, context]
---

# Context — Workspace Assistant

Who calls the service, the threat model and the deliberate design decisions. Running the bot is in
[operations.md](operations.md).

---

## Who calls it and why

- **A small trusted group, usually one person.** A user writes to the bot in Telegram; the message goes
  to `claude -p` in the context of the **managed repository** (`REPO_PATH`), the result is committed and
  pushed (unless `AUTO_COMMIT=0`), and the reply carries a commit link. The point is to edit a knowledge base or drive
  development from a phone.
- It is a **tool around a repository**, not a component of the repository it manages. On Telegram
  requests the bot works on `REPO_PATH`, never on its own code.
- Access is only for Telegram user ids in `ALLOWED_USER_IDS`; anyone else is refused.

---

## Threat model

The trust boundary is the **whitelist of Telegram user ids**. Everything else assumes that a user who
passed it is trusted. Treat the bot as a remote shell for everyone on the whitelist.

What is protected:

- **The whitelist** is the only barrier on input. It is checked for messages and for callback buttons
  (commit/discard, mode).
- **No shell injection.** Every claude, git and `gh` call goes through `proc_open` with an **argument
  array**; user text is a separate argv element, never part of a command line.
- **Secrets stay out of git.** `.env` (bot token) and `state/` are in `.gitignore`.
- **Non-interactive git/ssh** (`GIT_TERMINAL_PROMPT=0`, `BatchMode=yes`): protects against hangs and
  against git falling into a hidden prompt.

Deliberately wide surface (accepted risk, not a hole):

- **`--dangerously-skip-permissions`** is the default of `CLAUDE_EXTRA_ARGS`. Without it `claude -p`
  cannot edit files, because nobody can answer a permission prompt. Consequence: Claude can run
  **any command** and change **any file** with the rights of the bot's system user, and use that
  user's `gh` token and git credentials. Content Claude reads (files, issues, PR text) can steer it
  too (prompt injection). Compromising **one** whitelisted Telegram account therefore means code
  execution as the bot's user. This is acceptable only when the whitelist is short and trusted and the
  process is isolated.
- **Isolation recipe** (full text in the README, "Security"):
  1. a **dedicated system user without sudo** that owns only the bot, the managed repository and the
     state directory, with no SSH keys or credentials of other services in its home;
  2. a **narrow `gh` token**: fine-grained, limited to the needed repositories and to contents, pull
     requests and issues; nothing about administration, secrets or workflows; a deploy key or an
     equally scoped credential for git;
  3. containment: a VM or container, or service-manager hardening, and restricted outbound network;
  4. where possible a stricter `CLAUDE_EXTRA_ARGS` (for example `--permission-mode,acceptEdits`).
- **Accepted risk: a run can change the prompt files of the managed repository.** Persona files
  (`PROMPTS_DIR`) and locale files (`LOCALES_DIR`) live in the managed
  repository, which the bot edits and, with `AUTO_COMMIT=1`, pushes by itself. An injected or mistaken run can rewrite them and
  so steer later runs. The change has history (git) but **no review**. Mitigation: the bot appends a
  warning to the reply of every commit path (`sendResult()`, epic runs, the manual "commit" button) when
  a run changed files under `PROMPTS_DIR` or `LOCALES_DIR`. Read that warning and review the commit.
  The **contract** texts are `prompts/*.md.dist` under git; a local `prompts/<name>.md` (gitignored, so
  invisible to those checks) can override them, which is why every run reports each local override of a
  contract name in the reply.
- **No rate limit and no sandbox inside the bot.** Isolation is the deployer's job (recipe above).

> For review: security here means "a correct whitelist + argument-array passing". A feature that builds
> a command **string** from input, or weakens the user id check, is a threat-model regression.

---

## Deliberate decisions

| Decision                                                  | Why / consequence                                                                                       |
|-----------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| **No runtime dependencies**, own PSR-4 autoloader         | Composer is for dev tooling only. New classes in `src/` are picked up without `dump-autoload`.          |
| **Own `.env` parser** (not vlucas/phpdotenv)              | Fewer dependencies; `Env`/`Config` are covered by tests.                                                |
| **`-p` mode has no native `/compact`**                    | Implemented in the bot as summary + a new session ([gotchas.md](gotchas.md)).                           |
| **Single process, single run at a time**                  | The bot is one thread; runs are sequential and queued. Do not design for parallel runs.                 |
| **State in SQLite, key `(chat_id, thread_id)`**           | No concurrency; one row per topic session.                                                              |
| **Every external call has a timeout**                     | One blocking call freezes the whole bot ([internals.md](internals.md)).                                 |
| **System prompt: texts in `prompts/*.md.dist`**           | Shipped under git; a local override is allowed and a contract override is always reported.               |
| **Human-facing texts come from a locale catalog**         | `en` is the reference and the fallback ([gotchas.md](gotchas.md)).                                      |
| **Polling, no webhooks (Telegram, GitHub)**               | Long polling is already immediate and webhooks cannot replace the timer-driven poll ([INSTALL.md](../INSTALL.md#why-polling-not-webhooks)). |

## Out of scope

Multi-user concurrency, a web interface, session backups, parallel runs, and a sandbox inside the bot.
Extend only on an explicit request.
