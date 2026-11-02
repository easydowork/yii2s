#!/bin/bash
set -u
CLI=/usr/local/bin/swoole-cli
cd ~/yii2s || exit 1
pkill -f "swoole" 2>/dev/null
rm -f example/runtime/swoole-server.pid*
sleep 1
($CLI example/yii swoole/start --port=9601 > /tmp/srv.log 2>&1 &)
sleep 3
echo "alive: $(curl -s -o /dev/null -w '%{http_code}' --max-time 3 http://127.0.0.1:9601/)"
for path in / /demo/db /demo/redis; do
  echo "=== $path ==="
  $CLI scripts/bench.php --path="$path" --port=9601 --concurrency=100 --duration=5 --warmup=0 2>&1 | grep -E '"(qps|p50|p95|p99|errors)"'
done
echo "=== pool after bench ==="
curl -s --max-time 3 http://127.0.0.1:9601/__pool | head -c 500
echo
$CLI example/yii swoole/stop
