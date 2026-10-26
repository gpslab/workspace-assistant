# Security Policy

## Reporting a vulnerability

Report vulnerabilities privately through GitHub private vulnerability reporting: open the repository's
**Security** tab and choose **Report a vulnerability**. Please do not open public issues for
vulnerabilities.

No response time is promised.

## Supported versions

The latest release and `master`. Fixes land in `master` and ship in the next release.

## In scope

The bot's own code:

- access control (`ALLOWED_USER_IDS`);
- handling of untrusted input: Telegram messages and files, GitHub texts read by epics;
- hooks and gates;
- leaking `.env` secrets into logs or chat;
- `bin/ctl.sh` and the installer.

## Out of scope

- What Claude does on the operator's instruction. `--dangerously-skip-permissions` is a documented
  choice, see [Security](README.md#security) in the README.
- Compromise of the operator's own accounts (Telegram, GitHub, the system user).
- Bugs in Claude Code, `gh` or PHP themselves; report those upstream.
