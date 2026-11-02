#!/bin/bash
# Linux end-to-end test for yii2-swoole
set -u
CLI=/usr/local/bin/swoole-cli
cd ~/yii2s || exit 1

$CLI example/yii swoole/stop >/dev/null 2>&1
rm -f example/runtime/swoole-server.pid*

echo "=== 1. start (background, port 9601) ==="
($CLI example/yii swoole/start --port=9601 > /tmp/srv.log 2>&1 &)
sleep 3
echo "alive: $(curl -s -o /dev/null -w '%{http_code}' --max-time 3 http://127.0.0.1:9601/)"

echo "=== 2. status --port=9601 ==="
$CLI example/yii swoole/status --port=9601

echo "=== 3. reload (real respawn on Linux) ==="
$CLI example/yii swoole/reload --port=9601
sleep 5
echo "after reload: $(curl -s -o /dev/null -w '%{http_code}' --max-time 3 http://127.0.0.1:9601/)"
$CLI example/yii swoole/status --port=9601

echo "=== 4. SIGTERM graceful ==="
PIDFILE="/tmp/yii2s-swoole-127.0.0.1-9601.pid"
SERVER_PID=$($CLI -r '$d=json_decode(file_get_contents($argv[1]), true); echo (is_array($d) && isset($d["pid"])) ? $d["pid"] : "";' "$PIDFILE")
if [ -z "$SERVER_PID" ]; then
  echo "FAILED to read server pid from $PIDFILE"
  exit 1
fi
kill -TERM "$SERVER_PID"
sleep 3
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 http://127.0.0.1:9601/)
if [ "$CODE" = "000" ]; then echo "server stopped gracefully (SIGTERM)"; else echo "UNEXPECTED: $CODE"; fi

echo "=== 5. done ==="
