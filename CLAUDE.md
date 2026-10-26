# CLAUDE.md — Workspace Assistant

A Telegram bot: it takes messages, runs them through `claude -p` in the context of a git repository
(the **managed repository**) and commits/pushes the changes automatically. Usage, configuration and
commands are in [README.md](README.md); the epic reference is in [EPICS.md](EPICS.md).

## Agent docs (.claude-docs/)

Start with [index.md](.claude-docs/index.md), the routing table.

| File                                                       | When to read                                                                  |
|------------------------------------------------------------|-------------------------------------------------------------------------------|
| [.claude-docs/index.md](.claude-docs/index.md)             | Not sure where to look: routing table and code map                            |
| [.claude-docs/internals.md](.claude-docs/internals.md)     | How the bot works: components, message flow, state, timeouts, configuration   |
| [.claude-docs/operations.md](.claude-docs/operations.md)   | Running the bot: one-instance rule (409), git auth, `gh`, "the bot hangs"     |
| [.claude-docs/gotchas.md](.claude-docs/gotchas.md)         | Editing code and afraid to break something that looks odd but is intentional  |
| [.claude-docs/glossary.md](.claude-docs/glossary.md)       | Unfamiliar term, or unsure about entity names (Bot, Orchestrator, epic)       |
| [.claude-docs/context.md](.claude-docs/context.md)         | Who calls the bot, the threat model, deliberate design decisions              |

## Commands

```bash
composer test       # phpunit
composer stan       # phpstan
composer cs:check   # php-cs-fixer (check); composer cs fixes
php bin/bot.php     # run the bot (daemonizing: bin/ctl.sh or a service manager)
```

## PHP version

**PHP 8.5 everywhere**: `composer.json` requires `"php": ">=8.5"` and CI
(`.github/workflows/ci.yml`) runs 8.5. Run `composer test`/`stan`/`cs:check` on 8.5. If the
supported version changes, `composer.json`, CI and this section move together.

## Boundaries

### MUST
- Target PHP **>=8.5**.
- Before committing: green `composer cs:check`, `composer stan`, `composer test`.
- **Code, comments and documentation are written in English.** The project is published as the
  open-source package `gpslab/workspace-assistant` (MIT). New and changed text is English right away.
- Call the repository the bot manages the **managed repository** (not "workspace", "target repo");
  the project is **Workspace Assistant**.
- Use the glossary terms: `epic` (a series of tasks), `Orchestrator` (drives one epic), `Bot` (the main loop).
- `git pull` before starting any edits: this repository receives direct pushes from independent runs,
  so a stale checkout means editing outdated code and a rejected push (see
  [gotchas.md](.claude-docs/gotchas.md)).
- Docs describe only current rules: no history, incidents or dates, no host, path or user names of any
  particular deployment.

### MUST NOT
- Build a shell command line from user input; pass argument arrays to `proc_open`.
- Weaken the `ALLOWED_USER_IDS` check or add a blocking external call without a timeout.
- Edit `.github/workflows/` or `composer.lock` as a side effect of another task.

## PR review

Every PR gets an automatic AI review via `.github/workflows/claude-review.yml` (repository secret
`CLAUDE_CODE_OAUTH_TOKEN`). Drafts are not reviewed: the run starts on `ready_for_review`, on `opened`
for a non-draft and on `reopened`; to re-review, reopen or mark ready instead of pushing a new commit.
