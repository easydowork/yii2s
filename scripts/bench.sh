#!/bin/bash
# Coroutine load baseline (macOS and Linux).
#
#   bash scripts/bench.sh                         # 100 conns / 5s per path
#   CONCURRENCY=50 DURATION=3 bash scripts/bench.sh
#
# Starts example/ on PORT, benches /, /demo/db and /demo/redis with the
# built-in keep-alive load generator, prints pool metrics and stops the server.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLI="${CLI:-$(command -v swoole-cli || echo /usr/local/bin/swoole-cli)}"
APP_DIR="${APP_DIR:-$ROOT/example}"
PORT="${PORT:-9601}"
CONCURRENCY="${CONCURRENCY:-100}"
DURATION="${DURATION:-5}"
BASE="http://127.0.0.1:${PORT}"
TMP="${TMPDIR:-/tmp}"
TMP="${TMP%/}"
PIDFILE="$TMP/yii2s-swoole-$(id -u)/127.0.0.1-${PORT}.pid"

cleanup() {
    "$CLI" "$APP_DIR/yii" swoole/stop >/dev/null 2>&1
    sleep 1
    if [ -f "$PIDFILE" ]; then
        pid=$("$CLI" -r '$d=json_decode(file_get_contents($argv[1]), true); echo (is_array($d) && isset($d["pid"])) ? $d["pid"] : "";' "$PIDFILE" 2>/dev/null)
        [ -n "$pid" ] && kill -TERM "$pid" 2>/dev/null
    fi
    rm -f "$PIDFILE" "$PIDFILE.stop"
}
trap cleanup EXIT

echo "== start example/ on :$PORT =="
cleanup
("$CLI" "$APP_DIR/yii" swoole/start --port="$PORT" > "$TMP/yii2s-bench-server.log" 2>&1 &)
code=""
for _ in $(seq 1 60); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$BASE/" 2>/dev/null || true)
    [ "$code" = "200" ] && break
    sleep 0.2
done
if [ "$code" != "200" ]; then
    echo "server did not start:"; tail -20 "$TMP/yii2s-bench-server.log"; exit 1
fi
echo "alive: $code"
echo "concurrency=$CONCURRENCY duration=${DURATION}s"

for path in / /demo/db /demo/redis; do
    echo "=== $path ==="
    "$CLI" "$ROOT/scripts/bench.php" --path="$path" --port="$PORT" \
        --concurrency="$CONCURRENCY" --duration="$DURATION" --warmup=1 2>&1 \
        | grep -E '"(qps|p50|p95|p99|errors)"'
done

echo "=== pool after bench ==="
curl -s --max-time 3 "$BASE/__pool" | head -c 600
echo

echo "=== stop ==="
cleanup
echo "done"
