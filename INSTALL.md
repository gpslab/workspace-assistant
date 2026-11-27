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
   and note its absolute path. It should be a knowledge base, not a code project: by default the bot commits
   everything after each successful run that changed files (message `tg: <first line of the request, up to 60 chars>`) and pushes the
   current branch (not with `AUTO_COMMIT=0`), see [the managed repository: commit and push](README.md#the-managed-repository-commit-and-push).

The list of changes between versions is on the
[GitHub Releases](https://github.com/gpslab/workspace-assistant/releases) page; read it before updating.

### Installer (`bin/install.php`)

`bin/install.php` writes `.env`, checks the environment and the network and prints a systemd unit and a cron
line. It installs no packages, does not log in to the agent CLI (`claude`, `opencode` or `kimi`) or `gh`, does not
touch `/etc` or `systemctl` and does not start the bot. The output is in English.

- **Modes.** With `--non-interactive`, or when stdin is not a terminal, nothing is asked: values come from the
  options and the existing `.env`. Otherwise the installer is interactive: it asks for every key (except
  `STATE_DIR`, and except the `OPENCODE_*` keys unless `ENGINE=opencode` and the `KIMI_*` keys unless
  `ENGINE=kimi`) with the current value as the default (an empty answer keeps it) and repeats a question on
  an invalid answer. `CLAUDE_EXTRA_ARGS` is a
  separate question preceded by the security warning. The bot token is typed without echo; the terminal echo
  is restored even on Ctrl+C.

| Option                      | Key                                                                |
|-----------------------------|--------------------------------------------------------------------|
| `--bot-token`               | `BOT_TOKEN` (required)                                             |
| `--bot-token-file=PATH`     | read `BOT_TOKEN` from a file (a trailing line break is trimmed)    |
| `--allowed-user-ids`        | `ALLOWED_USER_IDS` (required; digits separated by commas)          |
| `--repo-path`               | `REPO_PATH` (required)                                             |
| `--forge`, `--forge-host`, `--forge-owners`, `--forge-repo` (`--github-owners` and `--github-repo` are accepted as aliases), `--engine`, `--claude-bin`, `--opencode-bin`, `--opencode-model`, `--opencode-extra-args`, `--kimi-bin`, `--kimi-home`, `--kimi-model`, `--kimi-extra-args`, `--bot-locale`, `--timezone`, `--openronin-logins`, `--claude-extra-args` | the key of the same name (optional) |
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
  stderr and in the summary; see [Security](README.md#security). With `ENGINE=opencode` that warning does not
  apply (there is no `OPENCODE_EXTRA_ARGS` value that makes OpenCode stricter) and is replaced by the
  `--auto` warning described in [OpenCode](#opencode); with `ENGINE=kimi` the same happens with the
  `auto` permission warning described in [Kimi](#kimi).
- The installer refuses to run as root unless `--allow-root` is given; files it creates then belong to root,
  so `chown` them to the bot's user if that is a different one.
- `REPO_PATH` must be an absolute path to a git repository.
- Checks run before anything is written. An error exits non-zero and leaves `.env` untouched; a warning goes
  to stderr and the summary and the write continues. Every external call has a timeout.

  | Check                                                                   | On failure                                      |
  |-------------------------------------------------------------------------|-------------------------------------------------|
  | PHP version from `composer.json`; extensions `curl`, `pdo_sqlite`, `pcntl`, `posix` | error                           |
  | `FORGE` is `github` or `gitlab`                                         | error                                           |
  | `git`, the forge CLI (`gh`, or `glab` for `FORGE=gitlab`) and the agent binary (`CLAUDE_BIN`, `OPENCODE_BIN` with `ENGINE=opencode`, `KIMI_BIN` with `ENGINE=kimi`) run (`--version`) | error |
  | `BOT_LOCALE` is in the catalog; `TIMEZONE` is valid; `REPO_PATH` is a git repository | error                      |
  | Bot API `getMe` accepts the token                                       | error (warning with `--skip-network-checks`)    |
  | `git -C REPO_PATH ls-remote` works without prompts                      | error (warning with `--skip-network-checks`)    |
  | `gh auth status -h <host>` (`glab auth status --hostname <host>` for `FORGE=gitlab`) | warning (only epics need `gh`) |

  `git` runs with `GIT_TERMINAL_PROMPT=0`, `BatchMode=yes` and `StrictHostKeyChecking=yes` (the bot itself
  uses `accept-new`), so on a fresh server an unknown host key fails the check with an `ssh-keyscan` hint:
  verify the fingerprint and add the key to `~/.ssh/known_hosts`.
- The systemd unit from [Running as a service](#running-as-a-service) (`User` is the current user,
  `WorkingDirectory` the installation directory, `ExecStart` the running PHP binary and `bin/bot.php`) and the
  cron line from [Monitoring forge authentication](#monitoring-forge-authentication) are printed. The unit is
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

### Monitoring forge authentication

`bin/check_forge_auth.sh` checks `gh auth status -h <host>` (`FORGE=github`) or
`glab auth status --hostname <host>` (`FORGE=gitlab`) and, if the authorization has expired, sends a message
to every id in `ALLOWED_USER_IDS` through the Bot API. The host is `FORGE_HOST`; for `github` the
`GH_AUTH_HOST` environment variable overrides it (default `github.com`; `gitlab.com` for gitlab). It exits
non-zero on a failed check, a missing CLI or an unknown `FORGE`. It reads `BOT_TOKEN`, `ALLOWED_USER_IDS`,
`FORGE` and `FORGE_HOST` from the `.env` next to it and stays silent when everything is fine. Run it from
cron, for example weekly. `bin/check_gh_auth.sh` only delegates to it, so existing cron entries keep working:

```cron
0 9 * * 1 /opt/workspace-assistant/bin/check_gh_auth.sh >> /opt/workspace-assistant/state/check_gh_auth.log 2>&1
```

### GitLab

For `FORGE=gitlab` (GitLab 16.0+, glab 1.40+) run the installer with `--forge=gitlab`, and for a self-hosted
instance `--forge-host=gitlab.example.com`; it checks `glab` instead of `gh`. Authenticate the bot's user with
`glab auth login --hostname gitlab.example.com` (token scopes `api` and `write_repository`). What works on
GitLab (chat, PM and review modes; no epics) is in the [GitLab](README.md#gitlab) section of the README.

### OpenCode

With `ENGINE=opencode` the jobs run through the [OpenCode](https://opencode.ai) CLI instead of Claude Code.
Install the CLI as the bot's user and authenticate it: the bot never logs in for you, and every run starts in
a private config directory under `STATE_DIR` (the project configuration of the managed repository is ignored).
The verified version is **2.3.0** (`@opencode/cli`): the bot parses the NDJSON of
`opencode run --format json`, which is undocumented upstream, so a newer version works only once it has been
verified against that format.

```bash
php bin/install.php ... --engine=opencode --opencode-model=anthropic/claude-sonnet-5.5
```

What differs from a Claude instance:

- The installer checks `OPENCODE_BIN` (`opencode` by default) instead of `CLAUDE_BIN` and warns about `--auto`
  instead of about `--dangerously-skip-permissions`.
- The model of every run is `OPENCODE_MODEL` (`provider/model`, or `provider/model#variant`); with it empty
  the model of your OpenCode configuration applies, epic runs included. `/model` is read-only and
  `MODEL_EPIC_*`, `CLAUDE_MODELS` and a `--model` in `CLAUDE_EXTRA_ARGS` do not apply; a set `MODEL_EPIC_*`
  is named in one startup log line. Changing `OPENCODE_MODEL` starts a new session, like changing `ENGINE`.
- Every mode and epics work. OpenCode has no command hooks, so every run lists the plugin (`opencode-plugin/`)
  in the `plugins` of the run's own configuration. Every run starts the secret guard first: it denies a shell
  command that carries a secret (`bin/secret-guard-hook.php`, the detector of the masking and of the commit
  gate) and lets every ordinary command through. A gated run (`pm`, `review` and the epic runs, which are the
  same two modes with the gate kind of the run kind) then carries the PM gate and the review commit hook
  through the same plugin; the plugin starts the same PHP hooks Claude Code would, so the gate logic is not
  duplicated. A run with no gate kind (`arch`, `/compact`) has no gate to run and the guard runs alone.
- **Every run is approved automatically.** The bot passes `--auto` on every `opencode run` and OpenCode then
  approves every action its configuration does not explicitly deny. `OPENCODE_EXTRA_ARGS` cannot make that
  stricter, so the installer's warning has no "set a safer value" option: run the bot as a dedicated
  unprivileged user with a narrowly scoped forge token and deny what it must never do in that OpenCode
  configuration.
- Built-in agents are written into the per-run configuration, but their `model:` is **ignored** on OpenCode:
  every agent of a run uses `OPENCODE_MODEL`.
- Your own agents reach the run as well: OpenCode loads no agent directory Claude Code would load, so the
  bot reads `.claude/agents/*.md` and `.agents/agents/*.md` of the managed repository and
  `~/.claude/agents/*.md` of the bot's user and renders them into the per-run configuration. An agent of
  the same `name:` replaces the built-in one (a file there is addressed by its frontmatter `name:`, not by
  its file name), and its `tools:` become that agent's OpenCode permission allowlist — an agent without
  `tools:` is unrestricted, as it is on Claude. `.agents/agents` is the engine-neutral place for an agent
  an instance wants on both engines; with `ENGINE=claude` it does not replace a built-in, because that CLI
  never reads the directory.
- `CLAUDE_TIMEOUT` and `CLAUDE_STALL_TIMEOUT` bound an OpenCode run as well. The stall timer stops counting
  after the run has launched its first sub-agent: OpenCode waits for sub-agents in silence, so silence then
  no longer means "hung" (the Claude runner never sees this, its sub-agent calls are progress lines).

### Kimi

With `ENGINE=kimi` chat runs through the Kimi Code CLI (`kimi`) instead of Claude Code. Install the CLI as
the bot's user, log in there once (the bot never logs in for you) and set `KIMI_BIN` to the **absolute** path
of the binary: the engine installs itself per user into `~/.kimi-code/bin`, which the `PATH` of a service
does not carry. The verified version is **2.7.2**; its `wire.jsonl` carries `metadata.protocol_version` `1.5`,
a private format the bot reads after a run to classify a failure. A newer version works only once it has been
verified against that format.

```bash
php bin/install.php ... --engine=kimi --kimi-bin=/home/you/.kimi-code/bin/kimi
```

What differs from a Claude instance:

- The installer checks `KIMI_BIN` (`kimi` by default) instead of `CLAUDE_BIN` and warns about the automatic
  approval of a Kimi run instead of about `--dangerously-skip-permissions`.
- The model of every run is `KIMI_MODEL`, named as the alias your own Kimi `config.toml` defines; with it
  empty the `default_model` of that file applies. `/model` is read-only, and `CLAUDE_MODELS`, `MODEL_EPIC_*`
  and a `--model` in `CLAUDE_EXTRA_ARGS` do not apply; the keys that are set are named in one startup log
  line. Changing `KIMI_MODEL` starts a new session, like changing `ENGINE`.
- **Chat and `/compact` work; the gated modes (`pm`, `review`) and the epics run while their gate
  preconditions hold.** A Kimi run without its gate looks normal and is not, so a `pm` or `review` message
  and an epic run are refused while one of these three conditions fails, with the failing one named in the
  refusal and nothing queued (`/mode arch` is the mode to chat in until then):
  - **`$HOME/.agents` exists.** Kimi reads agents, skills and `AGENTS.md` from that directory whatever
    `KIMI_HOME` and the run home say, so the bot does not control what the model is told. Remove it, or run
    the bot under a home that has none, if the model must be told only what the bot wrote.
  - **The `protocol_version` of the sessions is not one the bot was verified against** (see the version note
    above): the audit reads a private format, so an unverified one is not trusted with a gated run. The bot
    reads it from the newest session on the source home, the one the installed engine wrote last; a home with
    no session yet has no version to distrust.
  - **The gate shim fails its self-test.** Kimi's hooks fail open, so a shim that cannot start or write would
    let every call through unnoticed. A passing self-test is cached for the process, so a repaired shim still
    needs a restart like any other change of the installation; a failure is retried after a short delay, so a
    single slow call or a transiently unwritable state directory does not block the gated modes until a
    restart.
- Every run gets its own home (`STATE_DIR/kimi-<runId>/`, mode 0700) with the rendered `config.toml`, the
  assembled prompt in `agent.md` and the agents of the instance. The `credentials/`, `sessions/` and `oauth/`
  entries of the source home (`KIMI_HOME`, default `~/.kimi-code`) are linked into it, never copied: the
  server rotates the refresh token, so a copy would be invalidated by the next refresh. The source home is
  therefore a **single-consumer** resource: a second Kimi client that uses the same home at the same time
  shares the credentials and can refresh them concurrently, which was not tested. Run Kimi there and nothing
  else, and remember that the user's own `[[hooks]]` are dropped from a run while their
  `[[permission.rules]]` stay in effect.
- **A user-level `$HOME/.agents` is read by every Kimi run**, whatever `KIMI_HOME` and the run home say:
  agents, skills and `AGENTS.md` from that directory reach the model even though the bot relocates the home.
  The bot logs a warning at startup when the directory exists, and it is the first precondition of a gated
  run: while it is there, the gated modes and the epics are refused.
- **Every run is approved automatically.** `kimi -p` runs under `auto` permission, so every action the
  configuration does not explicitly deny is approved and whatever a Telegram message asks for runs with the
  rights of the bot's system user and its forge token. `KIMI_EXTRA_ARGS` cannot make that stricter; the
  `[[permission.rules]]` of your Kimi configuration keep their effect and are the only thing that can deny a
  call. Run the bot as a dedicated unprivileged user with a narrowly scoped forge token.
- A Kimi hook that is killed, times out or is missing does **not** block the call (only exit code 2 does), so
  the gate of a Kimi run is verified **after the fact** only: the shim records every hook call in the run
  home, and the bot compares those records with the run window of `wire.jsonl` at the end of the run. The
  audit runs after a run that did not end in an error only: a run that ended in an error is not audited and
  leaves no line. A `kimi gate audit skipped: …` line in `state/bot.log` (and no audit) is left by a run that
  was killed by a timeout, was cancelled, was still in progress when the bot stopped, or ended but whose audit
  could not be taken. A mismatch on a chat run is a `kimi gate audit: …` line in `state/bot.log` and nowhere
  else; on a gated run it is a gate violation: a `kimi gate violation: …` line in `state/bot.log`, the same
  text in the chat, and no success status for the run. That is a detection, never a hard stop — a call a
  failing hook let through is reported after the run, not prevented — and it is the residual risk of running
  the gated modes on this engine: what keeps a run from starting without a gate that holds is the refusal
  above, which is why its preconditions are checked before the run and not after it. The report of a gated
  run is deliberately the loud one and not a `bot.log` line only: a run whose gate could not be confirmed
  must not end as a success, and a report the records do not support is a defect of the comparator to fix,
  not a run to retry.
- `CLAUDE_TIMEOUT` and `CLAUDE_STALL_TIMEOUT` bound a Kimi run as well. The stall timer counts the bytes of
  stdout and the growth of the session's `wire.jsonl`: stdout is quiet while a background sub-agent works.
- Memory: like OpenCode, a Kimi run reads no user scope, so `MEMORY_3LAYER_BIN` is what brings the memory of
  the instance into a new session ([Memory](#memory)).

### Memory

An OpenCode or Kimi run reads no user scope, so it gets no memory of the instance the way a Claude Code run
does from the hooks and the `~/.claude/CLAUDE.md` of the deployment. `MEMORY_3LAYER_BIN` closes that gap:
with the **absolute** path of the `memory-3layer` client set (a non-interactive shell has no `~/.local/bin`
on `PATH`, so a bare name does not resolve), the bot

- runs `memory-3layer context`, which prints the **paths** of the memory files — L0 (`IDENTITY.md`), the
  project's `SESSION.md` and `project.md`;
- reads those files itself (they live outside the managed repository, in the data directory of
  memory-3layer) and puts their **content**, never the paths, into the system prompt of an OpenCode run
  and of a new Kimi session as the memory block ([PROMPTS.md](PROMPTS.md#memory)); a resumed Kimi session
  keeps the snapshot it was created with. The run needs no permission to read anything outside the
  repository. A file larger than `MEMORY_3LAYER_CONTEXT_MAX_BYTES` is injected from its newest end;
- pulls the memory before a run — that is, the data is refreshed for the **next** run, never for the one
  already started — and pushes it when a run ends (finishes, is cancelled or is killed), both in the
  background. One sync at a time for the whole instance (`state/memory-3layer.lock`): a sync the bot wants
  to start while its own client still runs is deferred and started by the bot loop as soon as the first one
  finishes, so a run that ends sooner than its pull still gets its push out. A sync a failing client runs,
  or one another bot process holds the lock for, is not retried here — the next run does it again.

A client that is missing, exits non-zero or hangs changes nothing about the run: the run goes on, and the
bot writes one line to its log; the output of the last sync is in `state/memory-3layer.log`. With
`ENGINE=claude` the setting changes nothing at all — that engine takes memory from the user scope, so the
bot neither injects anything nor syncs.

L0 (`IDENTITY.md`) reaches the model provider that `OPENCODE_MODEL` names, exactly as it already does on
Claude; nothing filters the injected memory. The same holds for a Kimi run and the provider of its model.

## Configuration

| Variable | Default | Meaning |
|----------|---------|---------|
| `BOT_TOKEN` | required | Bot token from @BotFather |
| `ALLOWED_USER_IDS` | required | Whitelist of Telegram user ids |
| `REPO_PATH` | required | Absolute path to the workspace git repository |
| `FORGE` | `github` | Forge: `github` or `gitlab`; anything else is a startup error (and an installer error). Epics are not available on `gitlab` yet, see [GitLab](README.md#gitlab) |
| `FORGE_HOST` | `github.com` / `gitlab.com` | Forge host, by `FORGE` |
| `FORGE_REPO` | empty | Workspace repository as `owner/name`, for commit links (alias: `GITHUB_REPO`; the new key wins, a warning is logged when both are set) |
| `FORGE_OWNERS` | empty | Owners the bot works with; the first is the default owner; with `FORGE=gitlab` an owner may be a namespace path `group/subgroup` (alias: `GITHUB_OWNERS`) |
| `OPENRONIN_LOGINS` | empty | Logins allowed to author the `<!-- openronin:bot -->` marker |
| `REPOSITORY_EXECUTORS` | empty | Exclusive repository-to-executor map, comma-separated `owner/repository:openronin` or `owner/repository:coder`. The repositories mapped to `coder` are the standalone intake allowlist; empty disables it |
| `CODER_INTAKE_AUTHOR` | empty | Trusted human GitHub login whose `openronin:do-it` label event authorizes standalone intake; required with coder routing. Separate from Telegram and executor login settings |
| `CODER_INTAKE_POLL_SECONDS` | `30` | Seconds between bounded read-only intake passes (minimum `5`) |
| `CODER_INTAKE_TICK_BUDGET` | `2` | Time budget of one intake pass, seconds |
| `CODER_INTAKE_WORK_BUDGET` | `4` | Maximum repository scans and issue records examined per intake pass (maximum `100`) |
| `CODER_INTAKE_REQUEST_TIMEOUT` | `1` | Timeout of one read-only GitHub request, seconds; cannot exceed the tick budget |
| `ENGINE` | `claude` | Agent engine that runs the jobs: `claude`, `opencode` or `kimi`; anything else is a startup error. See [OpenCode](#opencode) and [Kimi](#kimi) |
| `CLAUDE_BIN` | `claude` | Path or name of the `claude` binary (`ENGINE=claude`) |
| `OPENCODE_BIN` | `opencode` | Path or name of the `opencode` binary (`ENGINE=opencode`) |
| `OPENCODE_MODEL` | empty | Model of every OpenCode run as `provider/model[#variant]`; empty = the model of your OpenCode configuration. Changing it starts a new session |
| `OPENCODE_EXTRA_ARGS` | empty | Extra `opencode` arguments, comma-separated; they are added after the ones the bot passes itself |
| `KIMI_BIN` | `kimi` | Absolute path of the `kimi` binary (`ENGINE=kimi`); the engine installs itself into `~/.kimi-code/bin`, which the `PATH` of a service does not carry. Needs a restart |
| `KIMI_HOME` | empty | Home of the logged-in Kimi CLI; empty = the default home. Every run links the credentials, sessions and OAuth state of this home into its own run home. Needs a restart |
| `KIMI_MODEL` | empty | Model of every Kimi run, as the alias your Kimi `config.toml` defines; empty = the `default_model` of that file. Changing it starts a new session |
| `KIMI_EXTRA_ARGS` | empty | Extra `kimi` arguments, comma-separated; they are added after the ones the bot passes itself |
| `MEMORY_3LAYER_BIN` | empty | Absolute path of the `memory-3layer` client. Set = the bot injects the memory of the instance into the system prompt of an OpenCode run and of a new Kimi session (a resume keeps that snapshot) and syncs it in the background; with `ENGINE=claude` the setting changes nothing. Empty = off (default). See [Memory](#memory). Needs a restart |
| `MEMORY_3LAYER_CONTEXT_MAX_BYTES` | `32768` | Ceiling of the injected part of ONE memory file, bytes; above it only the newest part is injected. 0 = no injection. Needs a restart |
| `CLAUDE_TIMEOUT` | `0` | Overall limit of one Claude call, seconds (0 = off) |
| `CLAUDE_STALL_TIMEOUT` | `900` | Kill Claude after this many silent seconds (0 = off) |
| `CLAUDE_EXTRA_ARGS` | `--dangerously-skip-permissions` | Extra `claude` arguments, comma-separated |
| `CLAUDE_MODELS` | `fable,opus[1m],opus,sonnet,haiku` | Models selectable with `/model`, comma-separated; values are passed to `claude --model` as they are. A `--model` in `CLAUDE_EXTRA_ARGS` pins the model and `/model` becomes read-only. Not applied with `ENGINE=opencode` or `ENGINE=kimi` |
| `MODEL_EPIC_TICK` | empty | Model of the Orchestrator `tick` epic run, passed to `claude --model`; empty = the model for all topics; must be in `CLAUDE_MODELS`. Not applied with `ENGINE=opencode`; on `ENGINE=kimi` the model of an epic run is `KIMI_MODEL` |
| `MODEL_EPIC_FINAL` | empty | Model of the `final` (acceptance) epic run, passed to `claude --model`; empty = the model for all topics; must be in `CLAUDE_MODELS`. Not applied with `ENGINE=opencode`; on `ENGINE=kimi` the model of an epic run is `KIMI_MODEL` |
| `MODEL_EPIC_REVIEW` | empty | Model of the `review` epic run, passed to `claude --model`; empty = the model for all topics; must be in `CLAUDE_MODELS`. Not applied with `ENGINE=opencode`; on `ENGINE=kimi` the model of an epic run is `KIMI_MODEL` |
| `ANTHROPIC_BASE_URL` | empty | Base URL of the Anthropic-compatible API; empty, `api.anthropic.com` and `*.anthropic.com` mean Anthropic, any other host switches on foreign-provider limit handling (see below) |
| `ANTHROPIC_MODEL` | empty | Model recorded in the engine fingerprint of a stored session on a foreign provider, when the run has no pinned `--model`; empty = no model is appended to the fingerprint |
| `ANTHROPIC_AUTH_TOKEN` | empty | Auth token for an external Anthropic-compatible provider; replaces the Claude Code subscription (see below) |
| `SECRET_SCAN_EXCLUDE` | empty | Comma-separated path globs (relative to the repository root, `*` also matches `/`) where the commit gate does not apply **shape patterns**, for example `tests/fixtures/*`. Known values are checked in every path. See [README](README.md#commit-gate) |
| `SECRET_ENV_FILES` | empty | Comma-separated dotenv files (`~` is expanded, an `export ` prefix is allowed) whose **values** are all masked in what the bot sends to Telegram. Listing a file is the statement that its values are secret. A missing file is a log line, not a failure. Values of `.env` and of the environment are masked by key name without this setting, see [README](README.md#secret-masking) |
| `CLAUDE_APPEND_SYSTEM_PROMPT` | empty | Text appended to the system prompt |
| `PROMPTS_DIR` | empty | Persona files, relative to `REPO_PATH`; the texts in `prompts/` apply without it |
| `STATE_DIR` | `./state` | State directory (SQLite, inbox, outbox, epic signals) |
| `FAILED_RUN_EVIDENCE_DAYS` | `14` | Days the masked evidence (event stream and stderr tail) of a failed run is kept in `STATE_DIR/failed-runs`; `0` = no age limit. At most the newest 20 failed runs are kept |
| `POLL_TIMEOUT` | `30` | Long-polling timeout, seconds |
| `GIT_TIMEOUT` | `120` | Timeout of `git pull`/`push`, seconds |
| `AUTO_COMMIT` | `1` | `1`: the bot pulls, commits and pushes in the managed repository. `0`: it does not touch git there (changes stay in the working tree); epic runs and `/compact` work on a dirty tree too, and nothing is committed or pushed after them. Needs a restart |
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

### Standalone coder issue intake

`REPOSITORY_EXECUTORS` is empty by default, so the bot creates no standalone jobs and starts no
coder worker. Mapping a repository to `coder` enables bounded, read-only polling of its open issues
in the Bot loop. Intake requires the current `openronin:do-it` label and a matching latest `labeled`
event by `CODER_INTAKE_AUTHOR`; comments and labels from other actors create no jobs. The worker
remains an explicitly invoked local process. Polling does not push, create pull requests, mutate
labels, comment, or perform any other GitHub write.

Each repository can have only one executor assignment. Routing a repository to `coder` is rejected
while an epic containing it is `running` or `blocked`; pause or complete that epic first. New epic
plans for a coder-routed repository are rejected, and an existing epic stops loudly rather than
applying `openronin:do-it` there. The map does not disable or drain an external Openronin service.

### Epic run models

The three `MODEL_EPIC_*` keys choose the model of an epic run per run kind; each non-empty value must be a
member of `CLAUDE_MODELS` (otherwise the bot fails at startup). With a key empty the run falls back to the
model for all topics (`/model <name> all`), and with all three empty only that model applies. A `--model` in
`CLAUDE_EXTRA_ARGS` overrides them all (one warning is logged at startup). These are Claude Code keys: with
`ENGINE=opencode` the model of every run, epic runs included, is `OPENCODE_MODEL`, and a set key is named in
one startup log line; on `ENGINE=kimi` the model of every run is `KIMI_MODEL`, so the keys are named in that
line and do not apply ([Kimi](#kimi)). Built-in agents of the bot declare
`model: inherit`, so an epic key also moves every built-in agent that run calls, unless
`CLAUDE_CODE_SUBAGENT_MODEL_FORCE` is set. `opus` and `opus[1m]` are different context windows — pick the one
your endpoint actually resolves.

### Anthropic-compatible providers

Every key in `.env` whose name starts with `ANTHROPIC_` or `CLAUDE_CODE_` is passed straight into the
environment of every `claude` process the bot starts; the real process environment wins over the file, and
keys without either prefix are not passed. This is how the bot is pointed at an Anthropic-compatible
provider or given provider-specific options without touching `claude` itself:

```dotenv
ANTHROPIC_BASE_URL=https://openrouter.ai/api/v1
ANTHROPIC_AUTH_TOKEN=sk-or-v1-...
ANTHROPIC_MODEL=anthropic/claude-sonnet-5.5
CLAUDE_CODE_SUBAGENT_MODEL=anthropic/claude-haiku-4-5
```

`ANTHROPIC_AUTH_TOKEN` **replaces the subscription**: with it set, `claude` authenticates with this token
against `ANTHROPIC_BASE_URL` instead of your Claude Code / API account. It is masked like any other
`*TOKEN*` value in what the bot sends to Telegram.

`ANTHROPIC_BASE_URL` also drives the engine fingerprint of stored sessions and the handling of provider
errors. With it empty, or with a host `api.anthropic.com` / `*.anthropic.com`, the bot treats the endpoint
as Anthropic and nothing changes. With any other host (a "foreign provider") a `429` / `rate limit` error
pauses the queue for one hour, while a `quota` / `balance` / `insufficient` error is reported in the chat
without a pause. On a foreign provider `ANTHROPIC_MODEL` is appended to the session engine fingerprint when
the run has no pinned `--model`; built-in agents with `model: inherit` carry the inherited model through the
same way, and a `--model` in `CLAUDE_EXTRA_ARGS` pins it.

### Sub-repositories and time zone

`SUBREPOS_DIR` is a directory of git clones inside `REPO_PATH`. When set, before each run the bot does
`git pull --rebase` in every clone there, snapshots their HEAD and adds links to their new commits to
the reply. `SUBREPOS_DIR` must be listed in the managed repository's `.gitignore`: otherwise
`git add -A` records the clones as gitlinks. The bot only pulls the clones; it does not commit them.
Commit links of sub-repositories are built from their own `origin`: first `FORGE`/`FORGE_HOST`, then
github.com and gitlab.com, otherwise a bare hash.
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

The bot adds the emoji of each command to its `command.*` description in the command menu itself, so
a `command.*` text in a catalog (including one in `LOCALES_DIR`) must not contain an emoji: it would be shown twice (the bot logs a warning at start for such a text).
Command menu changes take effect after a restart.

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

Which locales are maintained and which are machine translations, and how to report a wrong string, is in
[Languages](README.md#languages). The rule for new interface strings is in
[CONTRIBUTING.md](CONTRIBUTING.md#interface-strings).

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
