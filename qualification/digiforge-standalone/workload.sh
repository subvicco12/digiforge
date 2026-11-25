#!/usr/bin/env bash
set -euo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
daemon=$(type -P mariadbd) || { echo 'MariaDB daemon missing from image system PATH' >&2; exit 127; }
[[ -x "$daemon" ]] || { echo 'MariaDB daemon is not executable by fixture UID' >&2; exit 126; }
"$daemon" --version > /evidence/mariadb-daemon-version.txt
grep -Fq '11.8.9' /evidence/mariadb-daemon-version.txt
printf '%s\n' "$daemon" > /evidence/mariadb-daemon-path.txt
d=$(mktemp -d /tmp/df-printify-coex-XXXXXXXX)
export DF_PRINTIFY_WP_FIXTURE_ROOT=$d/wordpress DF_PRINTIFY_WP_FIXTURE_SOCKET=$d/mysql.sock
mkdir "$d/db"
mariadb-install-db --no-defaults --datadir="$d/db" --auth-root-authentication-method=normal --skip-test-db > /evidence/mariadb-init.txt
db=
web=
save_startup_log() {
  if [[ -f "$d/mariadb.log" ]]; then
    tail -n 100 "$d/mariadb.log" | sed -E "s|$d|[fixture-runtime]|g; s/[[:cntrl:]]//g" > /evidence/mariadb-startup.log
  fi
}
cleanup() {
  if [[ -n "$web" ]]; then kill "$web" 2>/dev/null || true; fi
  if [[ -n "$db" ]]; then kill "$db" 2>/dev/null || true; fi
  wait || true
  if [[ ! -e /evidence/mariadb-startup.log ]]; then save_startup_log; fi
}
trap cleanup EXIT
"$daemon" --no-defaults --datadir="$d/db" --socket="$DF_PRINTIFY_WP_FIXTURE_SOCKET" --pid-file="$d/mariadb.pid" --skip-networking --log-error="$d/mariadb.log" &
db=$!
ready=0
for n in {1..30}; do
  if ! kill -0 "$db" 2>/dev/null; then
    echo 'MariaDB exited before its fixture socket became ready' >&2
    exit 1
  fi
  if [[ -S "$DF_PRINTIFY_WP_FIXTURE_SOCKET" ]] &&
     mariadb --no-defaults --protocol=SOCKET --socket="$DF_PRINTIFY_WP_FIXTURE_SOCKET" -uroot -e 'CREATE DATABASE printify_fixture'; then
    ready=1
    break
  fi
  sleep 1
done
[[ "$ready" == 1 ]] || { echo 'MariaDB fixture socket readiness timed out' >&2; exit 1; }
mariadb --no-defaults --protocol=SOCKET --socket="$DF_PRINTIFY_WP_FIXTURE_SOCKET" -uroot printify_fixture -N -e 'SELECT @@skip_networking' | grep -qx 1
save_startup_log
ss -ltn > /evidence/socket-listeners.txt
if grep -Eq '[:.]3306[[:space:]]' /evidence/socket-listeners.txt; then exit 1; fi
unzip -q /fixture/wordpress.zip -d "$d"
wp=$DF_PRINTIFY_WP_FIXTURE_ROOT
plugins=$wp/wp-content/plugins
test -f "$wp/wp-settings.php"
unzip -q /fixture/standalone.zip -d "$plugins"
mkdir -p "$wp/wp-content/mu-plugins"
cp /admission/browser-adapter.php "$wp/wp-content/mu-plugins/acceptance-browser.php"
cat > "$wp/wp-config.php" <<PHP
<?php
define('DB_NAME','printify_fixture');
define('DB_USER','root');
define('DB_PASSWORD','');
define('DB_HOST','localhost:$DF_PRINTIFY_WP_FIXTURE_SOCKET');
define('WP_ENVIRONMENT_TYPE','local');
define('WP_HTTP_BLOCK_EXTERNAL',true);
define('DISABLE_WP_CRON',true);
define('WP_HOME','http://127.0.0.1:18276');
define('WP_SITEURL',WP_HOME);
define('DIGIFORGE_PRINTIFY_DISPOSABLE_STORE',true);
define('DIGIFORGE_PRINTIFY_OFFLINE_SHOP','107');
define('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT','fixture-account');
\$table_prefix='wp_';
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
require ABSPATH.'wp-settings.php';
PHP
php /admission/lifecycle.php install >> /evidence/lifecycle.jsonl
mapfile -t versions < <(php -r 'include $argv[1]; echo $wp_version,PHP_EOL,PHP_VERSION,PHP_EOL;' "$wp/wp-includes/version.php")
maria=$(mariadb --no-defaults --socket="$DF_PRINTIFY_WP_FIXTURE_SOCKET" -uroot -N -e 'SELECT VERSION()' | cut -d- -f1)
chrome=$(chromium --version | grep -Eo '[0-9]+(\.[0-9]+){2,4}' | head -1)
jq -n --arg wordpress "${versions[0]}" --arg php "${versions[1]}" --arg mariadb "$maria" --arg chromium "$chrome" \
    '{wordpress:$wordpress,php:$php,mariadb:$mariadb,chromium:$chromium}' > /evidence/versions.json
jq -e --slurpfile actual /evidence/versions.json '.versions==$actual[0]' /admission/approved.json >/dev/null
export DF_PRINTIFY_DIRECT_FIXTURE=1
php /admission/tests/protected-get-wordpress.php write | tee /evidence/protected-write.txt
php /admission/tests/protected-get-wordpress.php reopen | tee /evidence/protected-reopen.txt
grep -F '10 direct synthetic operator reads.' /evidence/protected-write.txt
mkdir "$plugins/printify-for-woocommerce"
unzip -q /fixture/digiforge.zip -d "$plugins"
unzip -q /fixture/shipping.zip -d "$plugins/printify-for-woocommerce"
test -f "$plugins/digiforge/digiforge.php"
test -f "$plugins/printify-for-woocommerce/printify.php"
snapshot() {
  (cd "$plugins"; find . -type f ! -path './digiforge-printify-api/*' -print0 | sort -z | xargs -0 sha256sum)
}
snapshot > /evidence/incumbents-before.sha256
php /admission/lifecycle.php incumbents >> /evidence/lifecycle.jsonl
mariadb --no-defaults --socket="$DF_PRINTIFY_WP_FIXTURE_SOCKET" -uroot printify_fixture \
  -e "DELETE FROM wp_options WHERE option_name LIKE 'digiforge_printify_direct_quota_%'"
printf '{"schema":1,"scope":{"shop":"107","account":"fixture-account","integration":"fixture-read","custody":"custody-ref:fixture"},"token":"synthetic-operational-not-a-pat","state":"approved-read-only","expires_at":%s,"stop_all":false,"external_lock":false,"release_hold":false}\n' "$(( $(date +%s)+1800 ))" > "$d/operational-fixture.json"
php -S 127.0.0.1:18276 -t "$wp" > "$d/browser-http.log" 2>&1 &
web=$!
for n in {1..30}; do
  if curl -fsS http://127.0.0.1:18276/wp-login.php >/dev/null; then break; fi
  sleep 1
done
DF_COEX_CREDENTIAL_FILE="$d/operational-fixture.json" node /admission/browser.cjs | tee /evidence/browser.txt
kill "$web"
web=
test ! -e "$d/operational-fixture.json"
php /admission/tests/direct-secret.php "$plugins/digiforge-printify-api" | tee /evidence/credential-lifecycle.txt
php /admission/tests/protected-get-worker.php "$plugins/digiforge-printify-api" | tee /evidence/v13-projection.txt
grep -F "Scoped artwork metadata, placement, absent/invalid values, private URL omission and provider binding passed." /evidence/v13-projection.txt
php /admission/lifecycle.php unknown-write >> /evidence/lifecycle.jsonl
php /admission/lifecycle.php deactivate >> /evidence/lifecycle.jsonl
mv "$plugins/digiforge-printify-api" "$d/standalone-retired"
snapshot > /evidence/incumbents-rollback.sha256
cmp /evidence/incumbents-before.sha256 /evidence/incumbents-rollback.sha256
unzip -q /fixture/standalone.zip -d "$plugins"
php /admission/lifecycle.php reactivate >> /evidence/lifecycle.jsonl
php /admission/lifecycle.php unknown-reopen >> /evidence/lifecycle.jsonl
snapshot > /evidence/incumbents-after.sha256
cmp /evidence/incumbents-before.sha256 /evidence/incumbents-after.sha256
jq -n --slurpfile versions /evidence/versions.json \
  '{versions:$versions[0],fixture_only:true,mariadb_socket_query:true,mariadb_tcp_listener:false,browser_loopback:true,incumbents_unchanged:true,woocommerce_absent:true,standalone_only:true,coexistence:true,deactivate_reactivate:true,rollback_reinstall:true,unknown_preserved:true,replay_denied:true,protected_get_passed:10}' > /evidence/driver-receipt.json
