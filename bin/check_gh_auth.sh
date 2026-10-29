#!/usr/bin/env bash
# Kept so that existing cron entries keep working: the check moved to bin/check_forge_auth.sh.
exec "$(dirname "${BASH_SOURCE[0]}")/check_forge_auth.sh" "$@"
