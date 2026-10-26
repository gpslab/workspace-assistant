---
tags: [memory/repo, operations]
---

# Operations and troubleshooting — Workspace Assistant

How to run the bot, the one-instance rule, authentication, and what to do when it hangs. Paths below
are placeholders: `<install-dir>` is where the bot's code is cloned, `<bot-user>` is the dedicated
system user it runs as, `<service>` is the name of its service unit.

---

## Running the bot

| Parameter | Value                                                                                      |
|-----------|--------------------------------------------------------------------------------------------|
| PHP       | **8.5** in production; the minimum is 8.4                                                  |
| Config    | `<install-dir>/.env` (real environment variables override it)                              |
| Launch    | A service manager (see the unit example in the README) or `bin/ctl.sh` (nohup + pid file)  |
| Restart   | With a service manager use `Restart=always`: it recovers both a crash and a clean exit     |
| Log       | `state/bot.log` (STDERR redirected by the launcher)                                        |
| In chat   | `/restart`: pulls fresh code (`git pull --rebase`) and exits the loop cleanly              |

Run the bot as a dedicated unprivileged user (isolation recipe: [context.md](context.md)).

**Use one launcher per deployment.** `bin/ctl.sh` keeps its own `<STATE_DIR>/bot.pid` and knows nothing about
a PID owned by a service manager. Starting `ctl.sh` while a service-managed instance is alive creates a
second process and a 409 conflict (below). With a service manager use only its commands or `/restart`;
keep `ctl.sh` for local development (`bin/ctl.sh fg`) or for hosts without a service manager.

**The bot never restarts itself through `systemctl`/`kill`.** This is the self-preservation rule in the
system prompt: the bot process hosts the current Claude session, so stopping the bot from inside it kills
the session before the reply is delivered. `/restart` is handled by `Bot::cmdRestart()` outside the Claude
context: it pulls the code and ends the polling loop (`$this->running = false`), the same path as
`SIGTERM`, including the notice about an active run in `Bot::run()`; the service manager starts it again.

---

## One instance per token (Telegram 409)

Telegram allows **one** active `getUpdates` poller per bot token. Two processes give
`Conflict: terminated by other getUpdates request` (409) and lost messages.

- Start and stop only through the deployment's launcher (service manager commands, or `/restart`).
- Before manual intervention check the count: `pgrep -fc 'bin/bot.php'` must be `1`.
- To remove a stray process outside the service manager: `pkill -f 'bin/bot.php'`, then start through the
  service manager (not `ctl.sh start`).

---

## git authentication

- The managed repository is pulled and pushed over SSH (or any credential git already has for the
  remote), as `<bot-user>`.
- `GitManager` adds only `BatchMode`, `ConnectTimeout`, host-key acceptance and connection multiplexing
  to `GIT_SSH_COMMAND`; it does not disable the user's default keys.
- Check: `sudo -u <bot-user> -H git -C <managed-repo-path> ls-remote origin -h`. Prefer it to
  `ssh -T git@github.com`, which can report "Permission denied" while git itself works.
- The environment must supply `HOME` and `PATH` (and `SSH_AUTH_SOCK` when an agent is used).

---

## `gh` authentication and monitoring

`gh` (GitHub CLI) is a dependency of the bot's environment: `claude -p` calls it (issues, PRs) and so
does the PHP code of the `Orchestrator` (`GhClient`, polled every `EPIC_POLL_SECONDS` seconds). `gh` must be authorized as
`<bot-user>`. A token can be invalidated independently of the bot (revoked app access, password change,
organization token rotation, stricter 2FA/SSO). Symptom: `gh auth status` prints `X Failed to log in`.
The bot does not signal this by itself, hence a separate check.

**`bin/check_gh_auth.sh`** runs from cron (weekly), checks `gh auth status` and sends a Telegram message
through the Bot API directly (the running bot is not touched) when authorization is broken. It reads
`BOT_TOKEN` and `ALLOWED_USER_IDS` from the same `.env`. Setup and the cron line: README,
"Monitoring `gh` authentication".

When the notice arrives:

```bash
sudo -u <bot-user> -H gh auth status -h github.com   # confirm
sudo -u <bot-user> -H gh auth login -h github.com    # re-authorize (interactive)
```

No bot restart is needed: `gh` is invoked anew by every `claude -p` run and every orchestrator poll.
While the token is dead the `Orchestrator` does not poll epics; after 5 failures in a row it writes once
into the epic topic. **Exception:** the epic marker author login is cached for the process lifetime
(`gh api user`), so after logging in as a **different** account, restart the bot, otherwise all epic
markers count as foreign. Set `OPENRONIN_LOGINS` to the bot's login; without it the
`<!-- openronin:bot -->` marker is accepted from any author and the bot logs a warning at startup.

---

## Updating

`composer` is not needed at runtime: the PSR-4 autoloader in `bootstrap.php` finds new classes in `src/`
without `dump-autoload`. It is needed only for `composer cs/stan/test`. CI runs the same PHP version as
production, so a green CI covers version-dependent behavior.

1. Push to `master` with green CI.
2. **Through Telegram (usual):** `/restart`. It pulls `origin/master` and the service manager restarts the
   bot. It works only if the bot's working tree is clean; otherwise the pull is skipped with a warning.
3. **By hand** (if `/restart` is unavailable or more than bot code changed):

   ```bash
   sudo -u <bot-user> -H git -C <install-dir> fetch origin
   sudo -u <bot-user> -H git -C <install-dir> reset --hard origin/master
   sudo systemctl restart <service>
   ```

Interface language: `BOT_LOCALE` defaults to `en`. An instance whose chat must stay in another language
sets `BOT_LOCALE` before the restart, otherwise replies, buttons and `/help` switch to English.

---

## Protection against hangs

The bot is single-threaded: any blocking call freezes the whole loop.

| Mechanism                       | Where          | What it does                                                                              |
|---------------------------------|----------------|-------------------------------------------------------------------------------------------|
| git timeout                     | `GitManager`   | non-blocking reads + deadline; a stuck git is killed (SIGTERM→KILL); `GIT_TIMEOUT` (120 s) |
| Non-interactive git/ssh         | `GitManager`   | `GIT_TERMINAL_PROMPT=0`, `ssh -o BatchMode=yes -o ConnectTimeout=10`                       |
| Activity watchdog               | `ClaudeRunner` | no output longer than `CLAUDE_STALL_TIMEOUT` (900 s) → killed as hung                      |
| Overall claude timeout          | `ClaudeRunner` | `CLAUDE_TIMEOUT`, `0` (off) by default; `>0` caps the whole call                           |
| Job released before delivery    | `Bot::pollJob` | `finishJobState()` runs before sending to Telegram, so a delivery failure leaves no stuck job |

**Subagents and the stall timeout.** Subagents run inside the same `claude -p` process and emit almost
nothing to the parent's output for minutes. The stall timeout is the only safety net when
`CLAUDE_TIMEOUT=0`, so keep it generous; if a subagent legitimately needs longer, raise
`CLAUDE_STALL_TIMEOUT`, do not set it to `0`.

---

## Pull before a run

Before every run the bot syncs the managed repository (`git pull --rebase`), then, when `SUBREPOS_DIR` is
set, every clone under it (`Bot::pullSubRepos`), so Claude does not work on stale code.

- **Best-effort for sub-repositories:** a failure in one clone does not cancel the run (the managed
  repository pull gates it); it goes into a warning sent before "Thinking…".
- **Dirty clones are skipped** so a rebase cannot clobber work in progress; the skip is reported.
- **Branch deleted on the remote** (`Bot::recoverGoneBranch`): if the local branch is fully merged into the
  default branch (`merge-base --is-ancestor HEAD origin/<default>`, default taken from the origin's HEAD
  symref), the clone is switched to the default branch and refreshed (non-destructive). If it is not
  merged, it is left alone and reported for manual handling:
  `git -C <clone> checkout <default> && git -C <clone> pull`. The "merged" test relies on merge commits;
  with squash merges it fails closed and the manual path remains.
- **The loop is blocked** while clones are fetched. Each fails fast without network
  (`ConnectTimeout=10`); the per-repository ceiling is `GIT_TIMEOUT`.
- **Latency profile:** the log always gets a `sync-profile: total Xs = managed repository Ys + docs/* Zs
  (N repos); …` line (`grep sync-profile state/bot.log`); the chat gets the breakdown only with `/verbose`.
- **Mitigations in place:** SSH multiplexing (`ControlMaster=auto`, `ControlPersist=60s`, one connection to
  the git host reused by all fetches and the push); a parallel fetch of all clean clones over that one
  connection (`GitManager::fetchPruneParallel`, one shared `GIT_TIMEOUT` deadline); an early
  acknowledgement ("Thinking…" is sent before the network sync, the same `message_id` is reused).

---

## Playbook: the bot "hangs" / answers "Thinking…" and goes silent

Capture state:

```bash
pgrep -af 'bin/bot.php'; echo "count=$(pgrep -fc 'bin/bot.php')"      # how many instances
for p in $(pgrep -f bin/bot.php); do ps --ppid $p -o pid,stat,args; done  # is there a child claude
for p in $(pgrep -f bin/bot.php); do echo "$p wchan=$(cat /proc/$p/wchan)"; done  # what it blocks on
tail -30 <install-dir>/state/bot.log                                   # timestamps: when and what
```

Causes, most likely first:

0. **The bot is not running** (`pgrep -fc 'bin/bot.php'` is 0). The most common false "hang": nobody is
   there to answer. Check this first (service manager status, or `bin/ctl.sh status`). Start it through
   the deployment's launcher.
1. **Two instances → 409.** Log: `Conflict: terminated by other getUpdates`. Keep one.
2. **git stuck** (SSH passphrase/host key, dead network). Symptom: no child `git`/`claude`, the process
   sits in a curl/poll wait. Covered by the git timeouts; check the running code is recent.
3. **Telegram long-poll over a flaky route.** Short requests to the Bot API pass but the long
   `getUpdates` connection occasionally returns `0 bytes` and times out. Log: `curl error: Operation
   timed out after 120002 ms with 0 bytes`. Measures: route the Bot API outside the problematic network
   path, or lower the `getUpdates` timeout (`POLL_TIMEOUT`).
4. **Result delivery failed** (Telegram unreachable at reply time). Mitigated: the job is released before
   delivery ([gotchas.md](gotchas.md)).

Common commands:

```bash
sudo systemctl status <service>
tail -f <install-dir>/state/bot.log
sudo systemctl restart <service>        # usually `/restart` in the chat is enough
```
