#!/usr/bin/env bash
# Workspace Assistant process control: start / stop / restart / status / fg / logs
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
BOT="$ROOT_DIR/bin/bot.php"
STATE_DIR="$ROOT_DIR/state"
PID_FILE="$STATE_DIR/bot.pid"
LOG_FILE="$STATE_DIR/bot.log"
PHP="${PHP_BIN:-php}"
STOP_TIMEOUT=15

mkdir -p "$STATE_DIR"

is_running() {
    local pid
    pid=$(cat "$PID_FILE" 2>/dev/null) || return 1
    kill -0 "$pid" 2>/dev/null
}

get_pid() {
    cat "$PID_FILE" 2>/dev/null || true
}

cmd_start() {
    if is_running; then
        echo "Already running (PID $(get_pid))."
        return 0
    fi

    nohup "$PHP" "$BOT" >> "$LOG_FILE" 2>&1 &
    local pid=$!
    echo "$pid" > "$PID_FILE"
    echo "Started (PID $pid). Log: $LOG_FILE"
}

cmd_stop() {
    if ! is_running; then
        echo "Not running."
        rm -f "$PID_FILE"
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
    rm -f "$PID_FILE"
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
        rm -f "$PID_FILE"
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
