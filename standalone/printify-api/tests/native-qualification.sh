#!/usr/bin/env bash
set -euo pipefail
[[ "$#" == 5 ]] || { echo 'Usage: MODE INPUT_DIR COMMIT TREE ZIP_SHA256' >&2; exit 64; }
output=$(realpath "$2")
cd "$(git rev-parse --show-toplevel)"
git_dir=$(git rev-parse --absolute-git-dir)
work_tree=$PWD
case "$1" in
    0|1|lifecycle|approval|workspace|structured|upgrade|support) ;;
    *) exit 64 ;;
esac
if [[ "$(git rev-parse HEAD)" != "$3" || "$(git rev-parse HEAD^{tree})" != "$4"
    || -n "$(git status --porcelain --untracked-files=all)"
    || -n "$(git ls-files --others --ignored --exclude-standard -- standalone/printify-api)" ]]; then
    echo 'SEAL_REFUSED checkout identity or unexpected workspace changes' >&2
    exit 65
fi
[[ "$5" =~ ^[0-9a-f]{64}$ ]] || exit 65
tmp=$(mktemp -d /tmp/df-printify-closure-XXXXXXXX)
db=
trap 'status=$?; printf "END %s exit=%s\n" "$(date -u +%FT%TZ)" "$status"; if [[ -n "$db" ]]; then kill "$db" 2>/dev/null || true; wait "$db" || true; fi; rm -rf "$tmp"' EXIT
d="$tmp/inputs"
mkdir "$d" "$tmp/source"
for name in standalone.zip wordpress.zip workspace-config.php control.php wordpress-install.php native-egress-guard.c egress-probe.py; do
    [[ -f "$output/$name" && ! -L "$output/$name" ]] || exit 65
    cp -- "$output/$name" "$d/$name"
done
echo "checkout=$3 tree=$4 package_sha256=$5"
printf '%s  %s\n' "$5" "$d/standalone.zip" | sha256sum --strict -c -
(
  cd "$d"
  sha256sum --strict -c - <<'DIGESTS'
cd44160a3fcce179fe170331132fcfc05e84e2f9284e2aad38df2aea6edaeab1  wordpress.zip
6235346374a3f512ec1c60202304a3dd0bef485bd962d3a5ed4edb3657ce8151  workspace-config.php
d0e56bdb754d3f0a7fac841c13a07060a7cf0631e39b2d5a93908ab49ad0fe1f  control.php
06a302379ea07018b875570e8cf76ff986dcac8c2f1c3041a490a8172b00426c  wordpress-install.php
f0d5ac59d7146d1dec362a9567ea3d91d68cfa6c1561a49919195cab4da541e9  native-egress-guard.c
477cbed99c9fc8c72448a37c3ee691e1768ed4724b468930c9dab9c988cd2581  egress-probe.py
DIGESTS
)
if [[ "$1" == upgrade ]]; then
    for name in accepted-0153.zip digiforge.zip shipping.zip; do
        [[ -f "$output/$name" && ! -L "$output/$name" ]] || exit 65
        cp -- "$output/$name" "$d/$name"
    done
    (
      cd "$d"
      sha256sum --strict -c - <<'DIGESTS'
efd02e50aafc73e7e22fe9094a964bbbd928bacd21d879b4d52a337b9fb9275c  accepted-0153.zip
e12c923d6e8342570506a4f20ff3b4ba4b6f67e39bbee7e313c9e87aad1e8aa0  digiforge.zip
c36cfd01f5de69ce36e0024c97c4109bee972a6be3a27e89bc762383aad02157  shipping.zip
DIGESTS
    )
fi
git ls-tree -r -z "$3" standalone/printify-api > "$tmp/git-inputs"
while IFS= read -r -d '' record; do
    IFS=' ' read -r mode kind blob <<< "$(cut -f1 <<< "$record")"
    path=$(cut -f2- <<< "$record")
    [[ "$kind" == blob && ( "$mode" == 100644 || "$mode" == 100755 ) ]] || exit 65
    file="$tmp/source/$path"
    mkdir -p -- "$(dirname "$file")"
    git cat-file blob "$blob" > "$file"
    [[ "$(git hash-object --no-filters "$file")" == "$blob" ]] || exit 65
done < "$tmp/git-inputs"
echo 'VERIFY package bytes against the approved commit'
GIT_DIR="$git_dir" GIT_WORK_TREE="$work_tree" /usr/bin/python3     "$tmp/source/standalone/printify-api/package.py" --verify "$d/standalone.zip" --source "$3"
tests="$tmp/source/standalone/printify-api/tests"
[[ -s "$tests/protected-get-worker.php" && -s "$tests/batch2-approval-wordpress.php" ]] || exit 65
find "$tests" -type f -print0 | sort -z | xargs -0 sha256sum
echo 'SKIP browser, live provider transport, and production qualification'
if [[ "$1" != upgrade ]]; then echo 'SKIP incumbent coexistence'; fi
echo "START $(date -u +%FT%TZ) uname=$(uname -r)"
echo 'COMMAND gcc -shared native-egress-guard.c'
gcc -shared -fPIC -Wall -Wextra -Werror -O2 "$d/native-egress-guard.c" -o "$d/native-egress-guard.so"
echo 'COMMAND_EXIT 0'
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
unzip -q "$d/wordpress.zip" -d "$tmp"
wp="$tmp/wordpress"
mkdir -p "$wp/wp-content/mu-plugins" "$tmp/db"
cp "$d/control.php" "$wp/wp-content/mu-plugins/z-control.php"
install_pkg() {
    rm -rf -- "$wp/wp-content/plugins/digiforge-printify-api"
    unzip -q "$d/$1" -d "$wp/wp-content/plugins"
}
if [[ "$1" == upgrade ]]; then
    install_pkg accepted-0153.zip
    mkdir "$wp/wp-content/plugins/printify-for-woocommerce"
    unzip -q "$d/digiforge.zip" -d "$wp/wp-content/plugins"
    unzip -q "$d/shipping.zip" -d "$wp/wp-content/plugins/printify-for-woocommerce"
else
    install_pkg standalone.zip
fi
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
export PATH=/workspace/.onboarding/bin:/usr/bin:/bin
php --version
base=/workspace/.onboarding/root/usr
export LD_LIBRARY_PATH=$base/lib/x86_64-linux-gnu
PATH=$base/bin:$PATH "$base/bin/mariadb-install-db" --no-defaults --basedir="$base" --datadir="$tmp/db" --auth-root-authentication-method=normal --skip-test-db > "$tmp/init.log"
"$base/sbin/mariadbd" --no-defaults --basedir="$base" --datadir="$tmp/db" --socket="$socket" --pid-file="$tmp/db.pid" --skip-networking --log-error="$tmp/db.log" &
db=$!
for i in {1..20}; do
    if "$base/bin/mariadb" --no-defaults --socket="$socket" -uroot -e 'CREATE DATABASE printify_fixture; SELECT VERSION(), @@skip_networking;' 2>/dev/null; then break; fi
    sleep 1
done
export DF_PRINTIFY_WP_FIXTURE_ROOT="$wp"
export DF_PRINTIFY_WP_FIXTURE_SOCKET="$socket"
export DF_PRINTIFY_DIRECT_FIXTURE="$1"
guarded php "$d/wordpress-install.php" "$wp"
sha256sum "$tests/protected-get-worker.php"
if [[ "$1" == upgrade ]]; then
    inventory() {
        (cd "$wp/wp-content/plugins" && find digiforge printify-for-woocommerce -type f -print0 | sort -z | xargs -0 sha256sum)
    }
    inventory > "$output/incumbents-before.sha256"
    fixture="$tests/upgrade-coexistence-wordpress.php"
    guarded php "$fixture" seed
    install_pkg standalone.zip
    guarded php "$fixture" upgrade
    install_pkg accepted-0153.zip
    guarded php "$fixture" rollback
    install_pkg standalone.zip
    guarded php "$fixture" reinstall
    inventory > "$output/incumbents-after.sha256"
    cmp "$output/incumbents-before.sha256" "$output/incumbents-after.sha256"
    echo 'Incumbent files remain byte-identical.'
elif [[ "$1" == support ]]; then
    guarded php "$tests/support-read-wordpress.php"
elif [[ "$1" == lifecycle ]]; then
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$tests/disposable-storage.php" write
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$tests/disposable-storage.php" reopen
elif [[ "$1" == approval ]]; then
    fixture="$tests/batch2-approval-wordpress.php"
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$fixture" write
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$fixture" claim a > "$output/claim-a.log" 2>&1 &
    a=$!
    guarded php -d auto_prepend_file="$d/workspace-config.php" "$fixture" claim b > "$output/claim-b.log" 2>&1 &
    b=$!
    wait "$a" "$b"
    cat "$output/claim-a.log" "$output/claim-b.log"
    test "$(grep -hx MOCKUP_CLAIM_UNKNOWN "$output"/claim-{a,b}.log | wc -l)" -eq 1
    test "$(grep -hx MOCKUP_COMPETING_CLAIM_DENIED "$output"/claim-{a,b}.log | wc -l)" -eq 1
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
