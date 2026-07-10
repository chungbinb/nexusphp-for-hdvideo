#!/usr/bin/env bash
set -euo pipefail

SITE_ROOT="${1:?site root is required}"
cd "$SITE_ROOT"

PID_FILE="storage/app/zjh-worker.pid"
LOG_FILE="storage/logs/zjh-worker.log"
mkdir -p "$(dirname "$PID_FILE")" "$(dirname "$LOG_FILE")"

if [[ -f "$PID_FILE" ]]; then
    OLD_PID="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ "$OLD_PID" =~ ^[0-9]+$ ]] && [[ -r "/proc/$OLD_PID/cmdline" ]] && tr '\0' ' ' < "/proc/$OLD_PID/cmdline" | grep -q 'scripts/zjh-worker.php'; then
        kill "$OLD_PID" 2>/dev/null || true
        for _ in {1..20}; do
            kill -0 "$OLD_PID" 2>/dev/null || break
            sleep 0.25
        done
        kill -9 "$OLD_PID" 2>/dev/null || true
    fi
    rm -f "$PID_FILE"
fi

for ATTEMPT in 1 2 3; do
    nohup php scripts/zjh-worker.php >> "$LOG_FILE" 2>&1 < /dev/null &
    NEW_PID=$!
    echo "$NEW_PID" > "$PID_FILE"
    sleep 1
    if kill -0 "$NEW_PID" 2>/dev/null; then
        echo "ZJH worker started with PID $NEW_PID"
        exit 0
    fi
    rm -f "$PID_FILE"
    [[ "$ATTEMPT" -lt 3 ]] && sleep 5
done

echo "ZJH worker failed to start" >&2
tail -n 50 "$LOG_FILE" >&2 || true
exit 1

