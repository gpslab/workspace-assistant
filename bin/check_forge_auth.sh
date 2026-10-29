#!/usr/bin/env bash
# Checks the forge CLI authorization (`gh auth status -h <host>` for FORGE=github,
# `glab auth status --hostname <host>` for FORGE=gitlab) and notifies in Telegram if it has
# expired. The CLI is a dependency of the environment Workspace Assistant runs in: Claude
# inside `claude -p` uses it for forge operations (issues, PRs/MRs, etc.),
# the bot's PHP code itself does not call it. Meant to be run from cron
# (bin/check_gh_auth.sh delegates here, so existing cron entries keep working).
# Host: FORGE_HOST, or GH_AUTH_HOST for github (existing override), else the forge's default.
#
# Uses BOT_TOKEN and ALLOWED_USER_IDS from the .env of this same project — the same
# bot the owner already uses, just sending bypassing the long-poll
# loop (bin/bot.php is neither touched nor restarted).
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$ROOT_DIR/.env"

# Minimal .env parser (load_env_file in bin/env.sh): the first occurrence of a key wins and
# already exported variables are not overwritten.
# shellcheck source=env.sh
source "$SCRIPT_DIR/env.sh"
load_env_file "$ENV_FILE"

if [[ -z "${BOT_TOKEN:-}" || -z "${ALLOWED_USER_IDS:-}" ]]; then
    echo "check_forge_auth: BOT_TOKEN/ALLOWED_USER_IDS are not set (no $ENV_FILE?) — nobody to notify" >&2
    exit 1
fi

case "${FORGE:-github}" in
    github)
        CLI=gh
        HOST="${GH_AUTH_HOST:-${FORGE_HOST:-github.com}}"
        LOGIN="gh auth login -h ${HOST}"
        STATUS_CMD=(gh auth status -h "$HOST")
        ;;
    gitlab)
        CLI=glab
        HOST="${FORGE_HOST:-gitlab.com}"
        LOGIN="glab auth login --hostname ${HOST}"
        STATUS_CMD=(glab auth status --hostname "$HOST")
        ;;
    *)
        echo "check_forge_auth: unknown FORGE=${FORGE} (expected github or gitlab)" >&2
        exit 1
        ;;
esac

if ! command -v "$CLI" >/dev/null 2>&1; then
    STATUS_OUTPUT="${CLI} is not installed in PATH"
    STATUS_CODE=1
else
    STATUS_OUTPUT="$("${STATUS_CMD[@]}" 2>&1)"
    STATUS_CODE=$?
fi

if [[ $STATUS_CODE -eq 0 ]]; then
    exit 0
fi

MESSAGE="⚠️ ${CLI} auth (${HOST}) is not working on the server — re-auth is needed:
${LOGIN}

${STATUS_OUTPUT}"

IFS=',' read -ra USER_IDS <<< "$ALLOWED_USER_IDS"
for raw_id in "${USER_IDS[@]}"; do
    chat_id="$(echo "$raw_id" | xargs)"
    [[ -z "$chat_id" ]] && continue
    curl -fsS -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
        --data-urlencode "chat_id=${chat_id}" \
        --data-urlencode "text=${MESSAGE}" \
        > /dev/null || echo "check_forge_auth: failed to send notification to chat_id=${chat_id}" >&2
done

exit 1
