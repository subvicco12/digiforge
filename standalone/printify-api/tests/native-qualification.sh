#!/usr/bin/env bash
set -euo pipefail
d=$(realpath "$2")
checkout=$(git rev-parse --show-toplevel)
[[ "$(git rev-parse HEAD)" == "$3" && -z "$(git status --porcelain --untracked-files=no)" ]]
case "$1" in
    0|1|lifecycle|approval|workspace|structured) ;;
    *) exit 64 ;;
esac
echo "checkout=$(git rev-parse HEAD) tree=$(git rev-parse HEAD^{tree})"
printf '%s  %s\n' "$4" "$d/standalone.zip" \
  cd44160a3fcce179fe170331132fcfc05e84e2f9284e2aad38df2aea6edaeab1 "$d/wordpress.zip" | sha256sum -c -
printf 'package_sha256=%s\n' "$4"
sha256sum "$d"/{control.php,workspace-config.php,wordpress-install.php,native-egress-guard.c,egress-probe.py}
echo 'SKIP browser, incumbent coexistence, live provider transport, and production qualification'
log_name=coexist-v14
echo "START $(date -u +%FT%TZ) uname=$(uname -r) chromium=$(chromium --version)"
gcc -shared -fPIC -Wall -Wextra -Werror -O2 "$d/native-egress-guard.c" -o "$d/native-egress-guard.so"
guarded() {
    printf 'COMMAND'; printf ' %q' "$@"; printf '\n'
    for arg in "$@"; do
        if [[ "$arg" = *.php && -f "$arg" ]]; then sha256sum "$arg"; fi
    done
    local status=0
    DF_NETWORK_GUARD=cli LD_PRELOAD="$d/native-egress-guard.so" "$@" || status=$?
    printf 'COMMAND_EXIT %s\n' "$status"
    return "$status"
}
guarded python3 "$d/egress-probe.py"
tmp=$(mktemp -d /tmp/df-printify-closure-XXXXXXXX)
trap 'rm -rf "$tmp"' EXIT
unzip -q "$d/wordpress.zip" -d "$tmp"
wp="$tmp/wordpress"
mkdir -p "$wp/wp-content/mu-plugins" "$tmp/db"
cp "$d/control.php" "$wp/wp-content/mu-plugins/z-control.php"
unzip -q "$d/standalone.zip" -d "$wp/wp-content/plugins"
socket="$tmp/mysql.sock"
cat > "$wp/wp-config.php" <<PHP
<?php
define('DB_NAME','printify_fixture');
define('DB_USER','root');
define('DB_PASSWORD','');
define('DB_HOST','localhost:$socket');
define('WP_ENVIRONMENT_TYPE','local');
define("WP_DEBUG",true);
define('WP_HTTP_BLOCK_EXTERNAL',true);
define('DISABLE_WP_CRON',true);
define('AUTOMATIC_UPDATER_DISABLED',true);
define('WP_HOME','http://127.0.0.1:18276');
define('WP_SITEURL',WP_HOME);
\$table_prefix='wp_';
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
require ABSPATH.'wp-settings.php';
PHP
export PATH=/workspace/.onboarding/bin:$PATH
base=/workspace/.onboarding/root/usr
export LD_LIBRARY_PATH=$base/lib/x86_64-linux-gnu
PATH=$base/bin:$PATH "$base/bin/mariadb-install-db" --no-defaults --basedir="$base" --datadir="$tmp/db" --auth-root-authentication-method=normal --skip-test-db > "$tmp/init.log"
"$base/sbin/mariadbd" --no-defaults --basedir="$base" --datadir="$tmp/db" --socket="$socket" --pid-file="$tmp/db.pid" --skip-networking --log-error="$tmp/db.log" &
db=$!
web=
trap 'status=$?; if [[ -f "$tmp/http.log" ]]; then sed -E "s/\?[^ ]*/?[REDACTED]/g" "$tmp/http.log" > "$d/$log_name.http.log"; fi; printf "END %s exit=%s\n" "$(date -u +%FT%TZ)" "$status"; if [[ -n "$web" ]]; then kill "$web" 2>/dev/null || true; fi; kill "$db" 2>/dev/null || true; wait || true; rm -rf "$tmp"' EXIT
for i in {1..20}; do
    if "$base/bin/mariadb" --no-defaults --socket="$socket" -uroot -e 'CREATE DATABASE printify_fixture; SELECT VERSION(), @@skip_networking;' 2>/dev/null; then break; fi
    sleep 1
done
export DF_PRINTIFY_WP_FIXTURE_ROOT="$wp"
export DF_PRINTIFY_WP_FIXTURE_SOCKET="$socket"
export DF_PRINTIFY_DIRECT_FIXTURE="$1"
guarded php "$d/wordpress-install.php" "$wp"
tests="$checkout/standalone/printify-api/tests"
sha256sum "$tests/protected-get-worker.php"
sha256sum "$0"
if [[ "$1" == lifecycle ]]; then
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$tests/disposable-storage.php" write
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$tests/disposable-storage.php" reopen
elif [[ "$1" == approval ]]; then
    fixture="$tests/batch2-approval-wordpress.php"
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$fixture" write
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$fixture" claim a > "$d/claim-a.log" 2>&1 &
    a=$!
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$fixture" claim b > "$d/claim-b.log" 2>&1 &
    b=$!
    wait "$a" "$b"
    cat "$d/claim-a.log" "$d/claim-b.log"
    test "$(grep -hx MOCKUP_CLAIM_UNKNOWN "$d"/claim-{a,b}.log | wc -l)" -eq 1
    test "$(grep -hx MOCKUP_COMPETING_CLAIM_DENIED "$d"/claim-{a,b}.log | wc -l)" -eq 1
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$fixture" reopen
elif [[ "$1" == structured ]]; then
    export DF_STANDALONE_TEST_SCENARIO=structured
    printf 'DF_STANDALONE_TEST_SCENARIO=%s\n' "$DF_STANDALONE_TEST_SCENARIO"
    sha256sum "$tests/structured-operator-wordpress.php"
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$tests/synthetic-sync-workspace.php" write
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$tests/synthetic-sync-workspace.php" reopen
elif [[ "$1" == workspace ]]; then
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$tests/synthetic-sync-workspace.php" write
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$tests/synthetic-sync-workspace.php" reopen
else
    guarded php "$tests/protected-get-wordpress.php" write
    guarded php "$tests/protected-get-wordpress.php" reopen
fi
