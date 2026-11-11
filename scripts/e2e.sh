#!/bin/bash
# Server lifecycle end-to-end test (macOS and Linux).
#
#   bash scripts/e2e.sh            # example/ on port 9601
#   PORT=9701 APP_DIR=/path bash scripts/e2e.sh
#
# Verifies: start, health, `swoole/status`, `swoole/reload` (SIGUSR1 worker
# reload, master pid unchanged), SIGTERM graceful stop and pid-file cleanup.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# Prefer swoole-cli when present (macOS dev boxes whose system PHP is too
# old), otherwise use the system php: CI installs swoole via pecl on PHP 8.4
# and has no swoole-cli binary. Override with CLI=... when needed.
CLI="${CLI:-$(command -v swoole-cli || command -v php || echo php)}"
APP_DIR="${APP_DIR:-$ROOT/example}"
PORT="${PORT:-9601}"
BASE="http://127.0.0.1:${PORT}"
YII="$APP_DIR/yii"
TMP="${TMPDIR:-/tmp}"
TMP="${TMP%/}"
PIDFILE="$TMP/yii2s-swoole-$(id -u)/127.0.0.1-${PORT}.pid"

PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m %s (expected %s, got %s)\n' "$1" "$2" "$3"; }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "$2" "$3"; fi }

stop_server() {
    "$CLI" "$YII" swoole/stop >/dev/null 2>&1
    sleep 1
    if [ -f "$PIDFILE" ]; then
        pid=$("$CLI" -r '$d=json_decode(file_get_contents($argv[1]), true); echo (is_array($d) && isset($d["pid"])) ? $d["pid"] : "";' "$PIDFILE" 2>/dev/null)
        [ -n "$pid" ] && kill -TERM "$pid" 2>/dev/null
        sleep 1
    fi
    rm -f "$PIDFILE" "$PIDFILE.stop"
}

echo "== 0. clean previous instance =="
stop_server
ok "no leftover server on port $PORT"

echo "== 1. start =="
("$CLI" "$YII" swoole/start --port="$PORT" > "$TMP/yii2s-e2e-server.log" 2>&1 &)
code=""
for _ in $(seq 1 60); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$BASE/" 2>/dev/null || true)
    [ "$code" = "200" ] && break
    sleep 0.2
done
expect "server answers 200 after start" "200" "$code"
[ -f "$PIDFILE" ] && ok "pid file written ($PIDFILE)" || bad "pid file written" "exists" "missing"

echo "== 2. status =="
status_out=$("$CLI" "$YII" swoole/status --port="$PORT" 2>&1)
case "$status_out" in
    *"Server is running"*) ok "swoole/status reports a running server" ;;
    *) bad "swoole/status reports a running server" "Server is running" "$status_out" ;;
esac
old_pid=$("$CLI" -r '$d=json_decode(file_get_contents($argv[1]), true); echo (is_array($d) && isset($d["pid"])) ? $d["pid"] : "";' "$PIDFILE" 2>/dev/null)

echo "== 3. reload (SIGUSR1 worker reload, master unchanged) =="
reload_out=$("$CLI" "$YII" swoole/reload --port="$PORT" 2>&1)
case "$reload_out" in
    *"reload signal sent"*) ok "reload command completed" ;;
    *) bad "reload command completed" "reload signal sent" "$reload_out" ;;
esac
code=""
for _ in $(seq 1 80); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$BASE/" 2>/dev/null || true)
    [ "$code" = "200" ] && break
    sleep 0.25
done
expect "server answers 200 after reload" "200" "$code"
new_pid=$("$CLI" -r '$d=json_decode(file_get_contents($argv[1]), true); echo (is_array($d) && isset($d["pid"])) ? $d["pid"] : "";' "$PIDFILE" 2>/dev/null)
if [ -n "$old_pid" ] && [ -n "$new_pid" ] && [ "$old_pid" = "$new_pid" ]; then
    ok "reload kept the master pid ($old_pid) - worker reload, not a respawn"
else
    bad "reload kept the master pid" "same pid" "old=$old_pid new=$new_pid"
fi

echo "== 4. SIGTERM graceful stop =="
[ -n "${new_pid:-}" ] && kill -TERM "$new_pid" 2>/dev/null
code=""
for _ in $(seq 1 40); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 1 "$BASE/" 2>/dev/null || true)
    [ "$code" = "000" ] && break
    sleep 0.25
done
expect "server is unreachable after SIGTERM" "000" "$code"
# The listener closes before the master finishes draining workers, so the
# pid file disappears a beat after the port stops answering. Wait for the
# master to exit before asserting cleanup.
for _ in $(seq 1 60); do
    [ -f "$PIDFILE" ] || break
    sleep 0.25
done
[ -f "$PIDFILE" ] && bad "pid file removed on shutdown" "removed" "still present" || ok "pid file removed on shutdown"

echo
if [ "$FAIL" -eq 0 ]; then
    printf '\033[32mALL PASSED\033[0m (%d checks)\n' "$PASS"
    exit 0
fi
printf '\033[31m%d FAILED\033[0m, %d passed\n' "$FAIL" "$PASS"
exit 1
