#!/bin/bash
# End-to-end smoke test for the blog demo.
#
# It boots the coroutine server (unless BASE_URL points at an already running
# instance), then exercises every feature the demo exposes: MVC pages, error
# page, REST API with API-key auth, session login + CSRF, comment submission,
# cover upload + static serving, CSV download through sendFile(), the Redis
# queue worker and a small concurrency burst.
set -u

cd "$(dirname "$0")/.." || exit 1

CLI="${CLI:-swoole-cli}"
PORT="${PORT:-9602}"
BASE_URL="${BASE_URL:-http://127.0.0.1:${PORT}}"
TMP="$(mktemp -d)"
JAR="$TMP/cookies.txt"
PASS=0
FAIL=0
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

ok() {
    PASS=$((PASS + 1))
    printf '  \033[32mPASS\033[0m %s\n' "$1"
}

bad() {
    FAIL=$((FAIL + 1))
    printf '  \033[31mFAIL\033[0m %s (expected %s, got %s)\n' "$1" "$2" "$3"
}

expect() { # name expected actual
    if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "$2" "$3"; fi
}

contains() { # name needle haystack
    case "$3" in
        *"$2"*) ok "$1" ;;
        *) bad "$1" "contains '$2'" "not found" ;;
    esac
}

status() { # url [curl args...]
    local url="$1"; shift
    curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$@" "$url"
}

csrf() { # url
    curl -sS --max-time 15 -b "$JAR" -c "$JAR" "$1" \
        | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'
}

echo "== 0. migrate =="
$CLI yii migrate/up --interactive=0 >"$TMP/migrate.log" 2>&1 || {
    echo "migration failed:"; tail -5 "$TMP/migrate.log"; exit 1
}
ok "migrations applied"

if [ -z "${BASE_URL_OVERRIDE:-}" ]; then
    echo "== 1. start server on port $PORT =="
    $CLI yii swoole/start --port="$PORT" >"$TMP/server.log" 2>&1 &
    SERVER_PID=$!
    up=0
    for _ in $(seq 1 60); do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$BASE_URL/" 2>/dev/null || true)
        if [ "$code" = "200" ]; then up=1; break; fi
        sleep 0.2
    done
    if [ "$up" != "1" ]; then
        echo "server did not become healthy (last status: ${code:-none})"
        echo "--- response ---"; curl -sS --max-time 3 "$BASE_URL/" | head -5
        echo "--- server log ---"; tail -20 "$TMP/server.log"
        exit 1
    fi
    ok "server is up ($BASE_URL)"
fi

echo "== 2. public MVC pages =="
code=$(status "$BASE_URL/")
expect "GET / returns 200" "200" "$code"
home=$(curl -sS --max-time 15 "$BASE_URL/")
# The newest five posts are on page 1 - the seeded post may have been pushed
# to page 2 by the posts this script created, so only assert the list renders.
contains "home lists posts" "/site/view?id=" "$home"
contains "home shows comment counts" "comments" "$home"

code=$(status "$BASE_URL/site/view?id=1")
expect "GET /site/view?id=1 returns 200" "200" "$code"
view=$(curl -sS --max-time 15 "$BASE_URL/site/view?id=1")
contains "detail page renders the seeded post" "Hello Swoole" "$view"
contains "detail page renders comments" "Comments" "$view"
contains "detail page shows the view counter" "views" "$view"

code=$(status "$BASE_URL/site/nope")
expect "unknown route returns 404" "404" "$code"
error_body=$(curl -sS --max-time 15 "$BASE_URL/site/nope")
contains "custom error page is used" "Not Found" "$error_body"

echo "== 3. JSON API =="
code=$(status "$BASE_URL/api/posts")
expect "GET /api/posts returns 200" "200" "$code"
api=$(curl -sS --max-time 15 "$BASE_URL/api/posts?per_page=2")
contains "API returns pagination meta" '"total"' "$api"
contains "API returns post data" '"title"' "$api"

code=$(status "$BASE_URL/api/posts" -X POST -H 'Content-Type: application/json' -d '{"title":"Denied"}')
expect "POST without API key returns 401" "401" "$code"

created=$(curl -sS --max-time 15 -X POST \
    -H 'Content-Type: application/json' -H 'X-Api-Key: blog-demo-api-key' \
    -d '{"title":"Smoke API post","content":"created by scripts/smoke.sh"}' \
    -w '\n%{http_code}' "$BASE_URL/api/posts")
api_code=$(echo "$created" | tail -1)
api_body=$(echo "$created" | head -1)
expect "POST with API key returns 201" "201" "$api_code"
contains "created post is returned" '"id"' "$api_body"
NEW_ID=$(echo "$api_body" | sed 's/.*"id":\([0-9]*\).*/\1/')

code=$(status "$BASE_URL/api/posts/$NEW_ID" -X PUT \
    -H 'Content-Type: application/json' -H 'X-Api-Key: blog-demo-api-key' \
    -d '{"title":"Smoke API post (edited)"}')
expect "PUT updates the post" "200" "$code"

code=$(status "$BASE_URL/api/posts/$NEW_ID" -X DELETE -H 'X-Api-Key: blog-demo-api-key')
expect "DELETE removes the post" "200" "$code"

echo "== 4. login + CSRF session flow =="
token=$(csrf "$BASE_URL/admin/default/login")
[ -n "$token" ] && ok "login page issues a CSRF token" || bad "login page issues a CSRF token" "non-empty" "empty"
code=$(status "$BASE_URL/admin/default/login" -b "$JAR" -c "$JAR" -X POST \
    --data-urlencode "_csrf=$token" \
    --data-urlencode 'LoginForm[username]=admin' \
    --data-urlencode 'LoginForm[password]=admin123')
expect "login POST redirects (302)" "302" "$code"
code=$(status "$BASE_URL/admin/post/index" -b "$JAR")
expect "admin page is reachable after login" "200" "$code"
admin=$(curl -sS --max-time 15 -b "$JAR" "$BASE_URL/admin/post/index")
contains "admin page lists posts" "Posts admin" "$admin"

echo "== 5. comment submission (pending, not rendered) =="
token=$(csrf "$BASE_URL/site/view?id=1")
code=$(status "$BASE_URL/site/comment?id=1" -b "$JAR" -c "$JAR" -X POST \
    --data-urlencode "_csrf=$token" \
    --data-urlencode 'Comment[author_name]=Smoke' \
    --data-urlencode 'Comment[author_email]=smoke@example.com' \
    --data-urlencode 'Comment[content]=hello from smoke')
expect "comment POST redirects (302)" "302" "$code"
after=$(curl -sS --max-time 15 -b "$JAR" "$BASE_URL/site/view?id=1")
case "$after" in
    *"hello from smoke"*) bad "pending comment is not public" "hidden" "visible" ;;
    *) ok "pending comment is not public" ;;
esac

echo "== 6. cover upload + static serving =="
# 1x1 transparent PNG
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' \
    | openssl base64 -d -A > "$TMP/cover.png" 2>/dev/null
token=$(csrf "$BASE_URL/admin/post/create")
headers="$TMP/upload.headers"
code=$(curl -sS -o /dev/null -D "$headers" -w '%{http_code}' --max-time 20 \
    -b "$JAR" -c "$JAR" \
    -F "_csrf=$token" \
    -F 'Post[title]=Post with cover' \
    -F 'Post[content]=uploaded through CoroutineUploadedFile' \
    -F 'Post[status]=1' \
    -F "Post[cover]=@$TMP/cover.png;type=image/png" \
    "$BASE_URL/admin/post/create")
expect "cover upload creates the post (302)" "302" "$code"
uploaded=$(ls -t web/uploads/*.png 2>/dev/null | head -1 || true)
[ -n "$uploaded" ] && ok "cover file was stored under web/uploads" || bad "cover file was stored under web/uploads" "file" "none"
if [ -n "$uploaded" ]; then
    url="/uploads/$(basename "$uploaded")"
    code=$(status "$BASE_URL$url")
    expect "uploaded cover is served statically" "200" "$code"
    ctype=$(curl -sS -o /dev/null -w '%{content_type}' --max-time 10 "$BASE_URL$url")
    contains "static server sets the image mime type" "image/png" "$ctype"
fi

echo "== 7. CSV export through sendFile() =="
code=$(curl -sS -o "$TMP/export.csv" -D "$TMP/export.headers" -w '%{http_code}' --max-time 15 -b "$JAR" "$BASE_URL/admin/post/export")
expect "export returns 200" "200" "$code"
head1=$(head -1 "$TMP/export.csv")
contains "export has a CSV header" "id,title" "$head1"
contains "export is sent as text/csv" "text/csv" "$(tr -d '\r' < "$TMP/export.headers")"
contains "export includes the uploaded post" "Post with cover" "$(cat "$TMP/export.csv")"

echo "== 8. queue worker =="
$CLI yii queue/serve --limit=20 >"$TMP/queue.log" 2>&1 || true
audit=$($CLI yii blog/audit --limit=5 2>&1)
contains "queue job wrote an audit row" "post.published" "$audit"

echo "== 9. concurrency burst =="
pids=""
for _ in $(seq 1 25); do
    curl -s -o /dev/null -w '%{http_code}\n' --max-time 15 "$BASE_URL/site/view?id=2" >> "$TMP/codes.txt" &
    pids="$pids $!"
done
# Wait only for the curl jobs - a bare `wait` would also wait for the server
# process started above and never return.
# shellcheck disable=SC2086
wait $pids
ok_count=$(grep -c '^200$' "$TMP/codes.txt" || true)
expect "25 concurrent requests all return 200" "25" "$ok_count"

echo "== 10. published static assets =="
asset=$(printf '%s' "$home" | grep -o '/assets/[^" ]*\.js' | head -1 || true)
if [ -n "$asset" ]; then
    code=$(curl -sS -o /dev/null -D "$TMP/asset.headers" -w '%{http_code}' --max-time 10 "$BASE_URL$asset")
    expect "published asset is served ($asset)" "200" "$code"
    headers=$(tr -d '\r' < "$TMP/asset.headers")
    contains "asset carries a cache header" "max-age" "$headers"
else
    bad "published asset URL on the home page" "one /assets/*.js" "none"
fi

echo "== 11. transaction rollback (post + audit + FK violation) =="
tx=$(curl -sS --max-time 15 -b "$JAR" "$BASE_URL/admin/post/tx-probe")
contains "transaction rolled back" '"rolledBack":true' "$tx"
contains "post insert was rolled back" '"postsUnchanged":true' "$tx"
contains "audit insert was rolled back" '"auditUnchanged":true' "$tx"

echo "== 12. upload rejection =="
printf 'x' > "$TMP/evil.exe"
token=$(csrf "$BASE_URL/admin/post/create")
code=$(curl -sS -o "$TMP/reject-ext.html" -w '%{http_code}' --max-time 15 \
    -b "$JAR" -c "$JAR" \
    -F "_csrf=$token" \
    -F 'Post[title]=bad upload' -F 'Post[content]=x' -F 'Post[status]=1' \
    -F "Post[cover]=@$TMP/evil.exe;type=application/octet-stream" \
    "$BASE_URL/admin/post/create")
expect "wrong extension is rejected with the form" "200" "$code"
contains "extension error is rendered" "Only png/jpg/jpeg/webp/gif are allowed" "$(cat "$TMP/reject-ext.html")"

dd if=/dev/zero of="$TMP/big.png" bs=1048576 count=3 2>/dev/null
token=$(csrf "$BASE_URL/admin/post/create")
code=$(curl -sS -o "$TMP/reject-size.html" -w '%{http_code}' --max-time 20 \
    -b "$JAR" -c "$JAR" \
    -F "_csrf=$token" \
    -F 'Post[title]=too big' -F 'Post[content]=x' -F 'Post[status]=1' \
    -F "Post[cover]=@$TMP/big.png;type=image/png" \
    "$BASE_URL/admin/post/create")
expect "oversized cover is rejected with the form" "200" "$code"
contains "size error is rendered" "smaller than 2 MB" "$(cat "$TMP/reject-size.html")"

echo "== 13. 500 error page + logging =="
code=$(status "$BASE_URL/site/boom")
expect "uncaught exception returns 500" "500" "$code"
error_body=$(curl -sS --max-time 15 "$BASE_URL/site/boom")
contains "custom error page is used for 500" "blog-error-page" "$error_body"
logged=0
for _ in $(seq 1 30); do
    if grep -q "blog-boom-for-error-page" runtime/logs/app.log 2>/dev/null; then logged=1; break; fi
    sleep 0.1
done
expect "uncaught exception is written to the log" "1" "$logged"

echo "== 14. queue retry -> dead letter =="
$CLI yii blog/queue-failure >/dev/null 2>&1
round=0
for _ in 1 2 3; do
    round=$((round + 1))
    $CLI yii queue/serve --limit=5 >/dev/null 2>&1
    echo "  (worker run $round finished, waiting for the retry delay)"
    [ "$round" -lt 3 ] && sleep 3
done
stats=$($CLI yii blog/queue-stats 2>&1)
dead=$(printf '%s' "$stats" | sed -n 's/.*dead=\([0-9]*\).*/\1/p')
if [ -n "$dead" ] && [ "$dead" -ge 1 ]; then
    ok "failed job was retried and buried ($stats)"
else
    bad "failed job was retried and buried" "dead>=1" "$stats"
fi

echo "== 15. remember-me / auto login (last: rotates the session) =="
identity=$(awk '$6 == "_identity" {print $7}' "$JAR" | tail -1)
if [ -n "$identity" ]; then
    ok "login issued an identity cookie"
    who=$(curl -sS --max-time 15 -H "Cookie: _identity=$identity" "$BASE_URL/admin/default/whoami")
    contains "identity cookie alone restores the user" '"guest":false' "$who"
    contains "identity cookie restores user id 1" '"id":1' "$who"
    tampered=$(curl -sS --max-time 15 -H "Cookie: _identity=deadbeef" "$BASE_URL/admin/default/whoami")
    contains "tampered identity cookie is rejected" '"guest":true' "$tampered"
else
    bad "login issued an identity cookie" "_identity present" "missing"
fi

echo
if [ "$FAIL" -eq 0 ]; then
    printf '\033[32mALL PASSED\033[0m (%d checks)\n' "$PASS"
    exit 0
fi
printf '\033[31m%d FAILED\033[0m, %d passed\n' "$FAIL" "$PASS"
exit 1
