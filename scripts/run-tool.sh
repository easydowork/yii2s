#!/usr/bin/env bash
# Run a dev CLI tool with a PHP 8.4+ runtime that has ext-swoole.
#
# CI and production images normally provide that as the system `php` (with
# pecl swoole); macOS dev boxes often have an older system PHP plus the
# self-contained `swoole-cli` binary. Never hard-require swoole-cli: it is not
# installed by setup-php / pecl workflows.
set -eu

if php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' >/dev/null 2>&1; then
    exec php "$@"
fi

if command -v swoole-cli >/dev/null 2>&1; then
    exec swoole-cli "$@"
fi

echo 'yii2s: PHP >= 8.4 with ext-swoole (or swoole-cli) is required for the dev tools.' >&2
exit 1
