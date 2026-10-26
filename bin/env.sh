#!/usr/bin/env bash
# Minimal .env parser shared by the bash scripts: KEY=VALUE, no quotes/interpolation (see
# src/Env.php for the canonical PHP implementation — this is a separate copy so the scripts
# need no PHP runtime). Only lines starting with [A-Z_]+= are read; the first occurrence of a
# key wins and already exported variables are not overwritten (the same principle as in Env.php).
# Usage: source bin/env.sh; load_env_file /path/to/.env

load_env_file() {
    local file="$1" key value
    [[ -f "$file" ]] || return 0
    while IFS='=' read -r key value; do
        if [[ -z "${!key:-}" ]]; then
            export "$key=$value"
        fi
    done < <(grep -E '^[A-Z_]+=' "$file")
}
