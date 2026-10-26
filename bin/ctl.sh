#!/usr/bin/env bash
# Workspace Assistant process control: start / stop / restart / status / fg / logs
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
BOT="$ROOT_DIR/bin/bot.php"
PHP="${PHP_BIN:-php}"
STOP_TIMEOUT=15
LEGACY_PID_FILE="$ROOT_DIR/state/bot.pid"

# STATE_DIR exactly as the bot sees it: the bot's own .env parser (src/Env.php: real environment
# first, quotes, whitespace, the last duplicate wins) and Config::resolvePath() (a relative path is
# taken from the installation directory), so the two cannot drift apart. Nothing is exported: only
# the resolved directory is printed.
resolve_state_dir() {
    local dir
    # shellcheck disable=SC2016
    if ! dir=$("$PHP" -r '
        require $argv[1]."/bootstrap.php";
        $root = $argv[1];
        $value = is_file($root."/.env")
            ? (string) GpsLab\WorkspaceAssistant\Env::load($root."/.env")->get("STATE_DIR", "")
            : (string) getenv("STATE_DIR");
        echo GpsLab\WorkspaceAssistant\Config::resolvePath($value === "" ? $root."/state" : $value, $root);
    ' -- "$ROOT_DIR") || [[ -z "$dir" ]]; then
        echo "Warning: could not read STATE_DIR with $PHP; using $ROOT_DIR/state." >&2
        dir="$ROOT_DIR/state"
    fi
    printf '%s' "$dir"
}

STATE_DIR="$(resolve_state_dir)"
PID_FILE="$STATE_DIR/bot.pid"
LOG_FILE="$STATE_DIR/bot.log"

mkdir -p "$STATE_DIR"

# The PID is alive and is this bot. After a reboot the PID from the file can belong to an unrelated
# process; the command line is matched by the suffix bin/bot.php, not the full path, because the
# installation may be reached through a symlink. Without /proc only kill -0 is available.
is_bot_pid() {
    local pid="$1" arg
    [[ "$pid" =~ ^[0-9]+$ ]] || return 1
    kill -0 "$pid" 2>/dev/null || return 1
    [[ -r "/proc/$pid/cmdline" ]] || return 0
    while IFS= read -r -d '' arg; do
        [[ "$arg" == bin/bot.php || "$arg" == */bin/bot.php ]] && return 0
    done < "/proc/$pid/cmdline"
    return 1
}

# Before STATE_DIR was honoured the PID file lived in ./state next to the code. A bot started by the
# old script is still found there, so no command (start and fg included) starts a second instance:
# two pollers of one token conflict, and the bot has no lock of its own.
ACTIVE_PID_FILE="$PID_FILE"
if [[ "$PID_FILE" != "$LEGACY_PID_FILE" && ! -f "$PID_FILE" && -f "$LEGACY_PID_FILE" ]] \
    && is_bot_pid "$(cat "$LEGACY_PID_FILE" 2>/dev/null)"; then
    ACTIVE_PID_FILE="$LEGACY_PID_FILE"
    echo "Warning: the running bot was started with the PID file $LEGACY_PID_FILE; after it stops the PID file is $PID_FILE." >&2
fi

is_running() {
    local pid
    pid=$(cat "$ACTIVE_PID_FILE" 2>/dev/null) || return 1
    is_bot_pid "$pid"
}

get_pid() {
    cat "$ACTIVE_PID_FILE" 2>/dev/null || true
}

forget_pid() {
    rm -f "$ACTIVE_PID_FILE"
    ACTIVE_PID_FILE="$PID_FILE"
}

cmd_start() {
    if is_running; then
        echo "Already running (PID $(get_pid))."
        return 0
    fi

    nohup "$PHP" "$BOT" >> "$LOG_FILE" 2>&1 &
    local pid=$!
    echo "$pid" > "$PID_FILE"
    ACTIVE_PID_FILE="$PID_FILE"
    echo "Started (PID $pid). Log: $LOG_FILE"
}

cmd_stop() {
    if ! is_running; then
        echo "Not running."
        forget_pid
        return 0
    fi

    local pid
    pid=$(get_pid)
    echo "Stopping (PID $pid)..."
    kill -TERM "$pid"

    local i=0
    while is_running && [[ $i -lt $STOP_TIMEOUT ]]; do
        sleep 1
        i=$((i + 1))
    done

    if is_running; then
        echo "Did not exit within ${STOP_TIMEOUT}s, sending SIGKILL."
        kill -KILL "$pid" 2>/dev/null || true
    else
        echo "Stopped."
    fi
    forget_pid
}

cmd_restart() {
    cmd_stop
    cmd_start
}

cmd_status() {
    if is_running; then
        echo "Running (PID $(get_pid))."
    else
        echo "Not running."
        forget_pid
        return 1
    fi
}

cmd_fg() {
    if is_running; then
        echo "Already running in the background (PID $(get_pid)). Run first: $0 stop" >&2
        return 1
    fi
    exec "$PHP" "$BOT"
}

cmd_logs() {
    if [[ ! -f "$LOG_FILE" ]]; then
        echo "Log file not found: $LOG_FILE" >&2
        return 1
    fi
    tail -f "$LOG_FILE"
}

case "${1:-}" in
    start)   cmd_start   ;;
    stop)    cmd_stop    ;;
    restart) cmd_restart ;;
    status)  cmd_status  ;;
    fg)      cmd_fg      ;;
    logs)    cmd_logs    ;;
    *)
        echo "Usage: $(basename "$0") {start|stop|restart|status|fg|logs}" >&2
        exit 1
        ;;
esac
