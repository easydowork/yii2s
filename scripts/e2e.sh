#!/bin/bash
# Server lifecycle end-to-end test (Linux/WSL and macOS).
#
#   bash scripts/wsl-e2e.sh            # example/ on port 9601
#   PORT=9701 APP_DIR=/path bash scripts/wsl-e2e.sh
#
# Verifies: start, health, `swoole/status`, `swoole/reload` (real respawn,
# pid changes), SIGTERM graceful stop and pid-file cleanup.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLI="${CLI:-$(command -v swoole-cli || echo /usr/local/bin/swoole-cli)}"
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

echo "== 3. reload (real respawn) =="
reload_out=$("$CLI" "$YII" swoole/reload --port="$PORT" 2>&1)
case "$reload_out" in
    *"reloaded"*|*"could not be respawned"*) ok "reload command completed" ;;
    *) bad "reload command completed" "reload output" "$reload_out" ;;
esac
code=""
for _ in $(seq 1 80); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$BASE/" 2>/dev/null || true)
    [ "$code" = "200" ] && break
    sleep 0.25
done
expect "server answers 200 after reload" "200" "$code"
new_pid=$("$CLI" -r '$d=json_decode(file_get_contents($argv[1]), true); echo (is_array($d) && isset($d["pid"])) ? $d["pid"] : "";' "$PIDFILE" 2>/dev/null)
if [ -n "$old_pid" ] && [ -n "$new_pid" ] && [ "$old_pid" != "$new_pid" ]; then
    ok "reload respawned a new process ($old_pid -> $new_pid)"
else
    bad "reload respawned a new process" "new pid" "old=$old_pid new=$new_pid"
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
[ -f "$PIDFILE" ] && bad "pid file removed on shutdown" "removed" "still present" || ok "pid file removed on shutdown"

echo "== 5. same-port SO_REUSEPORT (two instances) =="
REUSE_PORT=$((PORT + 1))
REUSE_BASE="http://127.0.0.1:${REUSE_PORT}"
A_PIDFILE="$TMP/yii2s-reuse-a.pid"
B_PIDFILE="$TMP/yii2s-reuse-b.pid"
rm -f "$A_PIDFILE" "$B_PIDFILE" "$A_PIDFILE.stop" "$B_PIDFILE.stop"
(SWOOLE_PID_FILE="$A_PIDFILE" "$CLI" "$YII" swoole/start --port="$REUSE_PORT" > "$TMP/reuse-a.log" 2>&1 &)
sleep 1
(SWOOLE_PID_FILE="$B_PIDFILE" "$CLI" "$YII" swoole/start --port="$REUSE_PORT" > "$TMP/reuse-b.log" 2>&1 &)
code=""
for _ in $(seq 1 60); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$REUSE_BASE/" 2>/dev/null || true)
    [ "$code" = "200" ] && break
    sleep 0.2
done
expect "two instances share port $REUSE_PORT" "200" "$code"
a_pid=$("$CLI" -r '$d=json_decode((string)@file_get_contents($argv[1]), true); echo is_array($d) ? ($d["pid"] ?? "") : "";' "$A_PIDFILE" 2>/dev/null)
b_pid=$("$CLI" -r '$d=json_decode((string)@file_get_contents($argv[1]), true); echo is_array($d) ? ($d["pid"] ?? "") : "";' "$B_PIDFILE" 2>/dev/null)
if [ -n "$a_pid" ] && [ -n "$b_pid" ] && kill -0 "$a_pid" 2>/dev/null && kill -0 "$b_pid" 2>/dev/null; then
    ok "both instances are alive ($a_pid, $b_pid)"
else
    bad "both instances are alive" "two pids" "a=$a_pid b=$b_pid"
fi
served=0
for _ in $(seq 1 10); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$REUSE_BASE/" 2>/dev/null)" = "200" ] && served=$((served + 1))
done
expect "requests are served while both instances run" "10" "$served"
[ -n "${a_pid:-}" ] && kill -TERM "$a_pid" 2>/dev/null
[ -n "${b_pid:-}" ] && kill -TERM "$b_pid" 2>/dev/null
sleep 2
rm -f "$A_PIDFILE" "$B_PIDFILE" "$A_PIDFILE.stop" "$B_PIDFILE.stop"
if curl -s -o /dev/null --max-time 1 "$REUSE_BASE/" 2>/dev/null; then
    bad "both instances stopped" "unreachable" "still serving"
else
    ok "both instances stopped"
fi

echo
if [ "$FAIL" -eq 0 ]; then
    printf '\033[32mALL PASSED\033[0m (%d checks)\n' "$PASS"
    exit 0
fi
printf '\033[31m%d FAILED\033[0m, %d passed\n' "$FAIL" "$PASS"
exit 1
