# Installation and configuration

How to install, configure and run [Workspace Assistant](README.md). For what the bot does and how to
use it, see the [README](README.md).

## Installation

```bash
git clone https://github.com/gpslab/workspace-assistant.git
cd workspace-assistant
php bin/install.php --bot-token-file=/path/to/token-file --allowed-user-ids=1001 --repo-path=/path/to/repo
php bin/bot.php
```

1. Create a bot with [@BotFather](https://t.me/BotFather) and keep the token.
2. Find your Telegram user id with [@userinfobot](https://t.me/userinfobot).
3. Clone the managed repository, make sure `git pull` and `git push` work there without prompts,
   and note its absolute path. It should be a knowledge base, not a code project: the bot commits
   everything after each successful run with the message `tg: <start of the request>` and pushes the
   current branch, see [the managed repository: commit and push](README.md#the-managed-repository-commit-and-push).

The list of changes between versions is on the
[GitHub Releases](https://github.com/gpslab/workspace-assistant/releases) page; read it before updating.

### Installer (`bin/install.php`)

`bin/install.php` writes `.env`, checks the environment and the network and prints a systemd unit and a cron
line. It installs no packages, does not log in to `claude` or `gh`, does not touch `/etc` or `systemctl`
and does not start the bot. The output is in English.

- **Modes.** With `--non-interactive`, or when stdin is not a terminal, nothing is asked: values come from the
  options and the existing `.env`. Otherwise the installer is interactive: it asks for every key (except
  `STATE_DIR`) with the current value as the default (an empty answer keeps it) and repeats a question on an
  invalid answer. `CLAUDE_EXTRA_ARGS` is a separate question preceded by the security warning. The bot token
  is typed without echo; the terminal echo is restored even on Ctrl+C.

| Option                      | Key                                                                |
|-----------------------------|--------------------------------------------------------------------|
| `--bot-token`               | `BOT_TOKEN` (required)                                             |
| `--bot-token-file=PATH`     | read `BOT_TOKEN` from a file (a trailing line break is trimmed)    |
| `--allowed-user-ids`        | `ALLOWED_USER_IDS` (required; digits separated by commas)          |
| `--repo-path`               | `REPO_PATH` (required)                                             |
| `--github-owners`, `--github-repo`, `--claude-bin`, `--bot-locale`, `--timezone`, `--openronin-logins`, `--claude-extra-args` | the key of the same name (optional) |
| `--non-interactive`         | never ask questions (implied when stdin is not a terminal)         |
| `--skip-network-checks`     | report a failed `getMe` / `git ls-remote` as a warning, not an error |
| `--unit-file=PATH`          | also write the systemd unit to `PATH` (the directory must exist)   |
| `--allow-root`              | allow running as root (refused by default)                         |

- `BOT_TOKEN` sources: `--bot-token-file`, `--bot-token` or the `BOT_TOKEN` environment variable.
  Passing more than one source is an error (a stale exported `BOT_TOKEN` would override `.env`). The file or the environment keeps the token out of `ps` and the shell history.
- Values not passed are taken from the existing `.env`; without one, `.env.dist` is the base. The example
  values of `.env.dist` (`PASTE_YOUR_BOT_TOKEN_HERE`, `123456789`, `/path/to/your/repo`) count as unset.
- Values are written unquoted. A value with a line break, `#`, leading/trailing whitespace, wrapping quotes or
  a trailing `=` is rejected, because `src/Env.php` and the bash parser (`bin/env.sh`) would read it differently.
  The last occurrence of a key is replaced and earlier duplicates are removed; a commented `# KEY=value`
  is uncommented in place; unknown keys and comments are kept; new keys are appended.
- All errors are listed at once and `.env` is left untouched. The file is written to `.env.tmp-<pid>`, checked
  by building the bot configuration from it (this also creates the state, `inbox` and `outbox` directories),
  the previous `.env` is copied to `.env.bak` (one backup, overwritten on every run) and the new file is
  renamed over `.env`. Files get mode 0600.
- With `--dangerously-skip-permissions` in `CLAUDE_EXTRA_ARGS` (the default) the installer always warns on
  stderr and in the summary; see [Security](README.md#security).
- The installer refuses to run as root unless `--allow-root` is given; files it creates then belong to root,
  so `chown` them to the bot's user if that is a different one.
- `REPO_PATH` must be an absolute path to a git repository.
- Checks run before anything is written. An error exits non-zero and leaves `.env` untouched; a warning goes
  to stderr and the summary and the write continues. Every external call has a timeout.

  | Check                                                                   | On failure                                      |
  |-------------------------------------------------------------------------|-------------------------------------------------|
  | PHP version from `composer.json`; extensions `curl`, `pdo_sqlite`, `pcntl`, `posix` | error                           |
  | `git`, `gh` and the `CLAUDE_BIN` binary run (`--version`)               | error                                           |
  | `BOT_LOCALE` is in the catalog; `TIMEZONE` is valid; `REPO_PATH` is a git repository | error                      |
  | Bot API `getMe` accepts the token                                       | error (warning with `--skip-network-checks`)    |
  | `git -C REPO_PATH ls-remote` works without prompts                      | error (warning with `--skip-network-checks`)    |
  | `gh auth status`                                                        | warning (only epics need `gh`)                  |

  `git` runs with `GIT_TERMINAL_PROMPT=0`, `BatchMode=yes` and `StrictHostKeyChecking=yes` (the bot itself
  uses `accept-new`), so on a fresh server an unknown host key fails the check with an `ssh-keyscan` hint:
  verify the fingerprint and add the key to `~/.ssh/known_hosts`.
- The systemd unit from [Running as a service](#running-as-a-service) (`User` is the current user,
  `WorkingDirectory` the installation directory, `ExecStart` the running PHP binary and `bin/bot.php`) and the
  cron line from [Monitoring `gh` authentication](#monitoring-gh-authentication) are printed. The unit is
  written to a file only with `--unit-file=PATH`; installing it is up to you.
- When `.env` already existed, restart a running bot (`bin/ctl.sh restart` or your service manager) to apply the changes.

Configuration lives in `.env` (`KEY=VALUE`, edit it by hand or re-run the installer); lists are comma-separated. Real environment variables
override the file. Every setting is documented in [`.env.dist`](.env.dist).

### Running as a service

`bin/ctl.sh` manages a background process (`start`, `stop`, `restart`, `status`, `fg`, `logs`); the PID
file and the log go to `STATE_DIR` (from the environment or `.env`, default `./state`; a relative path is
taken from the installation directory), the same directory the bot uses. Use `PHP_BIN=/path/to/php bin/ctl.sh start` to choose the PHP binary.

Alternatively, a systemd unit (use the dedicated user from the [isolation recipe](README.md#isolation-recipe)):

```ini
[Unit]
Description=Workspace Assistant bot
After=network.target

[Service]
WorkingDirectory=/opt/workspace-assistant
ExecStart=/usr/bin/php /opt/workspace-assistant/bin/bot.php
Restart=always
User=workspace-assistant

[Install]
WantedBy=multi-user.target
```

The bot handles `SIGTERM`: it finishes the current loop iteration, kills the running Claude child
process and exits. Only **one instance per bot token** may run, otherwise Telegram answers with 409.

### Monitoring `gh` authentication

`bin/check_gh_auth.sh` checks `gh auth status` and, if the authorization has expired, sends a message
to every id in `ALLOWED_USER_IDS` through the Bot API. It reads `BOT_TOKEN` and `ALLOWED_USER_IDS`
from the `.env` next to it and stays silent when everything is fine. Run it from cron, for example weekly:

```cron
0 9 * * 1 /opt/workspace-assistant/bin/check_gh_auth.sh >> /opt/workspace-assistant/state/check_gh_auth.log 2>&1
```

## Configuration

| Variable | Default | Meaning |
|----------|---------|---------|
| `BOT_TOKEN` | required | Bot token from @BotFather |
| `ALLOWED_USER_IDS` | required | Whitelist of Telegram user ids |
| `REPO_PATH` | required | Absolute path to the workspace git repository |
| `GITHUB_REPO` | empty | Workspace repository as `owner/name`, for commit links |
| `GITHUB_OWNERS` | empty | GitHub owners the bot works with; the first is the default owner |
| `OPENRONIN_LOGINS` | empty | Logins allowed to author the `<!-- openronin:bot -->` marker |
| `CLAUDE_BIN` | `claude` | Path or name of the `claude` binary |
| `CLAUDE_TIMEOUT` | `0` | Overall limit of one Claude call, seconds (0 = off) |
| `CLAUDE_STALL_TIMEOUT` | `900` | Kill Claude after this many silent seconds (0 = off) |
| `CLAUDE_EXTRA_ARGS` | `--dangerously-skip-permissions` | Extra `claude` arguments, comma-separated |
| `CLAUDE_APPEND_SYSTEM_PROMPT` | empty | Text appended to the system prompt |
| `PROMPTS_DIR` | empty | Persona files, relative to `REPO_PATH`; the texts in `prompts/` apply without it |
| `STATE_DIR` | `./state` | State directory (SQLite, inbox, outbox, epic signals) |
| `POLL_TIMEOUT` | `30` | Long-polling timeout, seconds |
| `GIT_TIMEOUT` | `120` | Timeout of `git pull`/`push`, seconds |
| `SUBREPOS_DIR` | empty | Directory of git clones inside `REPO_PATH` that are pulled before a run |
| `TIMEZONE` | `UTC` | PHP timezone identifier for logs and dates |
| `BOT_LOCALE` | `en` | Interface locale |
| `LOCALES_DIR` | empty | Own locale files, relative to `REPO_PATH` |
| `EPICS_DIR` | `.workspace-assistant/epics` | Epic plans and journals, relative to `REPO_PATH` |
| `EPIC_POLL_SECONDS` | `30` | Seconds between epic poll passes, counted from the end of the previous pass (minimum `10`) |
| `EPIC_TICK_MINUTES` | `30` | Interval of the timer tick |
| `EPIC_STALL_TICKS` | `6` | Timer ticks in a row without progress before the epic is blocked |
| `EPIC_QUIET_MINUTES` | `10` | PR quiet period before the hot review; a safety net, since the review is queued earlier once openronin has the last word on a green PR |
| `EPIC_ANSWER_TIMEOUT_MINUTES` | `120` | Timeout of `openronin:awaiting-answer` |
| `EPIC_MASTER_RED_HOURS` | `6` | Hours a confirmed red default branch is tolerated |

### Sub-repositories and time zone

`SUBREPOS_DIR` is a directory of git clones inside `REPO_PATH`. When set, before each run the bot does
`git pull --rebase` in every clone there, snapshots their HEAD and adds links to their new commits to
the reply. `SUBREPOS_DIR` must be listed in the managed repository's `.gitignore`: otherwise
`git add -A` records the clones as gitlinks. The bot only pulls the clones; it does not commit them.
`TIMEZONE` is used for `date()` and log lines; an unknown value fails the startup.

### Interface language

Bot texts, command descriptions, the epic status card and notices, and the comments and commit messages
the bot composes come from locale files `resources/locales/<code>.json` (built in: `de`, `en`, `es`, `fr`, `hi`, `ja`, `kk`, `ko`, `pt`, `ru`, `uk`, `uz`, `zh`).
`BOT_LOCALE` selects the locale; a code without a file falls back to `en` with a warning in the log.
Prompts sent to Claude, log lines and exceptions are not localized.

A locale file is a flat `"key": "text"` object plus three service fields:

- `_language`: the English name of the language;
- `_flag`: the flag emoji on the `/lang` button;
- `_native`: the self-name of the language on the `/lang` button.

Placeholders are named: `{name}`. The service fields are not interface texts, so they are not
checked against `en.json` for keys and placeholders.

`_flag` and `_native` come only from the catalog of the locale itself and never fall back to `en`.
An instance file in `LOCALES_DIR` overrides the built-in file of the same locale key by key:

- a field missing in the instance file keeps the built-in value of the same locale, if the locale has a
  built-in file;
- an empty string means "no flag" for `_flag`, and for `_native` it falls back to `_language`, then to
  the locale code.

To add a language, add a file with all interface keys of `en.json` and the same placeholders, and set
the three service fields for the new language, not copied from `en.json`: copied fields show the
language on `/lang` as "🇬🇧 English", with no warning. This applies both to an operator's locale in
`LOCALES_DIR` and to a built-in locale added through a PR. `LOCALES_DIR` is an optional directory of
your own locales: its files add to and override the built-in ones key by key.

Support status: `en` (the reference and the fallback) and `ru` are maintained by the project. The other
built-in locales are machine translations, provided as is. If you meet a wrong string, open an issue
or send a PR that changes one `resources/locales/<code>.json` and nothing else. The rule for new
interface strings is in [CONTRIBUTING.md](CONTRIBUTING.md#interface-strings).

### Mode personas

The prompt files, how the prompt of a run is assembled and how to override a file (`prompts/<name>.md`,
the `PROMPTS_DIR` persona layer, the additions `epic.md` and `pm_runbook.md`) are described in
[PROMPTS.md](PROMPTS.md).

## Why polling, not webhooks

The bot polls both Telegram and GitHub; there is no inbound port.

**Telegram**

- Long polling is already immediate: Telegram answers the open `getUpdates` request as soon as an
  update arrives. `POLL_TIMEOUT` is how long to wait when nothing comes, not a delivery delay.
- Telegram keeps unconfirmed updates (up to 24 hours) and the offset confirms them, so the bot needs
  no inbound queue of its own. A webhook needs an always-responsive HTTP receiver next to the
  single-threaded loop and a hand-over from it to the loop — re-implementing what `getUpdates`
  already provides.
- `getUpdates` and a webhook are mutually exclusive: switching is all or nothing.
- Long polling needs no inbound port, public HTTPS endpoint or reverse proxy; the bot runs anywhere
  with outbound network access.

**GitHub**

- Claude runs are asynchronous, but the loop is blocked synchronously by the GitHub poll pass (tens
  of seconds) and network git operations (usually seconds, up to `GIT_TIMEOUT`). GitHub waits 10 s
  for a delivery answer and does not redeliver failed deliveries automatically, so a listener inside
  the loop would lose deliveries. A separate listener process is an inbound network surface running
  as the bot's user, plus a reverse proxy and a hook on every repository.
- A webhook could not replace polling: timer transitions (quiet period before the hot review, timer
  tick, answer timeout, red default branch) happen without any GitHub event, so the poll stays the
  source of truth and its interval stays short anyway.
- The gain would be tens of seconds per event; a short `EPIC_POLL_SECONDS` while an epic is running
  gives the same order of latency without a new surface.
- Latency is of the order of `EPIC_POLL_SECONDS` plus the pass duration; with several epics a pass
  can be cut by its 20 s budget and the next epic waits for the following pass.
- Cost, per pass: GraphQL — one query per epic task (1 point each) and one default-branch CI query
  per repository; REST — one Actions-runs call per task that has a PR, plus one or two compare calls per
  approved open PR (five more per master-merge commit since the approval). GraphQL points and REST requests have separate hourly limits (5000 each for a user
  token), both shared with `gh` calls made inside runs. Example: a 15-task epic at 30 s ≈ 2000
  GraphQL points and ≈ 1800 REST requests per hour, plus ~240 per approved PR still open; fewer in
  practice, since the interval counts from the end of a pass. While a pass runs the bot does not read
  Telegram or the active run's output (nothing is lost, it is delayed). Raise `EPIC_POLL_SECONDS`
  for large epics or a busy account.
