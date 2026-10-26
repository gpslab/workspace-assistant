#!/usr/bin/env bash
# Checks GitHub CLI (gh) authorization and notifies in Telegram if it has
# expired. gh is a dependency of the environment Workspace Assistant runs in: Claude
# inside `claude -p` uses gh for GitHub operations (issues, PRs, etc.),
# the bot's PHP code itself does not call gh. Meant to be run from cron.
#
# Uses BOT_TOKEN and ALLOWED_USER_IDS from the .env of this same project — the same
# bot the owner already uses, just sending bypassing the long-poll
# loop (bin/bot.php is neither touched nor restarted).
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$ROOT_DIR/.env"
GH_HOST="${GH_AUTH_HOST:-github.com}"

# Minimal .env parser (load_env_file in bin/env.sh): the first occurrence of a key wins and
# already exported variables are not overwritten.
# shellcheck source=env.sh
source "$SCRIPT_DIR/env.sh"
load_env_file "$ENV_FILE"

if [[ -z "${BOT_TOKEN:-}" || -z "${ALLOWED_USER_IDS:-}" ]]; then
    echo "check_gh_auth: BOT_TOKEN/ALLOWED_USER_IDS are not set (no $ENV_FILE?) — nobody to notify" >&2
    exit 1
fi

if ! command -v gh >/dev/null 2>&1; then
    STATUS_OUTPUT="gh is not installed in PATH"
    STATUS_CODE=1
else
    STATUS_OUTPUT="$(gh auth status -h "$GH_HOST" 2>&1)"
    STATUS_CODE=$?
fi

if [[ $STATUS_CODE -eq 0 ]]; then
    exit 0
fi

MESSAGE="⚠️ gh auth (${GH_HOST}) is not working on the server — re-auth is needed:
gh auth login -h ${GH_HOST}

${STATUS_OUTPUT}"

IFS=',' read -ra USER_IDS <<< "$ALLOWED_USER_IDS"
for raw_id in "${USER_IDS[@]}"; do
    chat_id="$(echo "$raw_id" | xargs)"
    [[ -z "$chat_id" ]] && continue
    curl -fsS -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
        --data-urlencode "chat_id=${chat_id}" \
        --data-urlencode "text=${MESSAGE}" \
        > /dev/null || echo "check_gh_auth: failed to send notification to chat_id=${chat_id}" >&2
done

exit 1
