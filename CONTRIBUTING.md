# Contributing

Pull requests are welcome. This page lists what a change has to satisfy before it can be merged.
[CLAUDE.md](CLAUDE.md) is the authoritative rule list (it is written for coding agents, but it applies to
everyone); this file explains the same rules from a contributor's side.

## Language

Write code, comments, documentation, issues and pull requests in English.

## PHP version

Write code for the minimum PHP version listed in [Requirements](README.md#requirements). Do not use
functions, classes or syntax newer than that version: PHPStan checks against it and fails the build.

## Before you push

All three commands must pass:

```bash
composer cs:check   # code style (composer cs fixes it)
composer stan       # static analysis
composer test       # unit tests
```

## No runtime dependencies

The bot runs without Composer dependencies, and deployments may ship without `vendor/`. Do not add
packages to `require` in `composer.json`; tooling goes to `require-dev`. A new runtime dependency is a
blocker in review.

## Files to leave alone

Do not edit `.github/workflows/` or `composer.lock` as a side effect of another change. If a change really
needs one of them, make it a separate pull request and explain why.

## How review works

- CI (`ci.yml`) runs on every pull request.
- An automatic agent review (`claude-review.yml`) needs a repository secret, and GitHub does not pass
  secrets to pull requests from forks. A pull request from a fork therefore gets no automatic agent review;
  the maintainer reviews it.
- For branches of this repository, drafts are not reviewed, and new commits do not trigger a rerun. To
  get a re-review, reopen the pull request or mark it ready for review again.

## Restarts and process management

If a change needs the bot to be restarted, or touches process management (daemonizing, `bin/ctl.sh`,
signals, locking), say so in the pull request description.

## Project knowledge

Notes for working on the code are in [.claude-docs/index.md](.claude-docs/index.md). If you find a
non-obvious trap, add it to [.claude-docs/gotchas.md](.claude-docs/gotchas.md) in the same pull request.

## Interface strings

Interface strings live in the catalogs in `resources/locales/`. Add a new key to every catalog: the tests
require the same set of keys in all of them. `en` is the reference; a machine translation is acceptable
for the other languages.

## Vulnerabilities

Do not report vulnerabilities as public issues. See [SECURITY.md](SECURITY.md).
