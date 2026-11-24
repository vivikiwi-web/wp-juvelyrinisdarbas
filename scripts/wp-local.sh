#!/usr/bin/env bash
set -euo pipefail

jd_theme_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
jd_wp_root="$(cd "$jd_theme_dir/../../.." && pwd)"
jd_services="$HOME/Library/Application Support/Local/lightning-services"
jd_run="${JD_LOCAL_RUN:-$HOME/Library/Application Support/Local/run/_dumnQz1T}"
jd_php="${JD_WP_PHP:-$jd_services/php-8.5.13+4/bin/darwin-arm64/bin/php}"
jd_mysql_bin="${JD_MYSQL_BIN:-$jd_services/mysql-8.4.0+2/bin/darwin-arm64/bin}"

if [[ ! -x "$jd_php" || ! -S "$jd_run/mysql/mysqld.sock" ]]; then
    echo 'Local PHP or MySQL socket unavailable. Check Local and the JD_* path overrides.' >&2
    exit 1
fi

# Suppress only external CLI deprecations; PHP warnings/errors remain visible.
exec env MYSQL_UNIX_PORT="$jd_run/mysql/mysqld.sock" PATH="$jd_mysql_bin:$PATH" \
    "$jd_php" -d error_reporting=8191 -d "mysqli.default_socket=$jd_run/mysql/mysqld.sock" \
    /usr/local/bin/wp --path="$jd_wp_root" "$@"
