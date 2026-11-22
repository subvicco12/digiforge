#!/usr/bin/env bash
set -euo pipefail
umask 077
mode=${1:-}
[[ $# == 1 && $(id -u) == 10001 && $(id -g) == 10001 ]]
[[ "$mode" == pass || "$mode" == assertion-failure || "$mode" == partial ]]
[[ $(ls -1 /sys/class/net) == lo ]]
grep -Eq '^CapEff:[[:space:]]+0+$' /proc/self/status
grep -Eq '^NoNewPrivs:[[:space:]]+1$' /proc/self/status
[[ -d /work && -O /work && -z $(find /work -mindepth 1 -print -quit) ]]
export R1_MODE="$mode" R1_DB_SOCKET=/work/db.sock
run=work
server_pid=
db_ready=false
no_tcp=false
phpunit_status=null
sql_version=
cleanup() {
  local primary=$? clean=true
  trap - EXIT
  set +e
  if [[ -n "$server_pid" ]]; then
    kill -TERM "$server_pid" 2>/dev/null
    for ((i=0;i<30;i++)); do
      kill -0 "$server_pid" 2>/dev/null || break
      sleep 0.2
    done
    if kill -0 "$server_pid" 2>/dev/null; then
      kill -KILL "$server_pid" 2>/dev/null
      clean=false
    fi
    wait "$server_pid" 2>/dev/null
    if kill -0 "$server_pid" 2>/dev/null; then clean=false; fi
  fi
  # No test SQL, logs or configuration are retained.
  rm -rf -- /work/db /work/db.sock /work/db.pid /work/db.log /work/init.log || clean=false
  [[ ! -e /work/db && ! -S /work/db.sock && ! -e /work/db.pid ]] || clean=false
  /usr/bin/php -r '
    [$script,$mode,$exit,$phpunit,$ready,$noTcp,$clean,$sqlVersion] = $argv;
    $out = ["schema"=>1,"mode"=>$mode,"exit"=>(int)$exit,
      "phpunit_exit"=>$phpunit==="null"?null:(int)$phpunit,
      "uid"=>posix_geteuid(),"only_loopback"=>scandir("/sys/class/net")===[".","..","lo"],
      "db_ready"=>$ready==="true","no_tcp_listener"=>$noTcp==="true",
      "reaped_and_cleaned"=>$clean==="true","php"=>PHP_VERSION,
      "extensions"=>get_loaded_extensions(),"mariadb"=>$sqlVersion];
    echo json_encode($out, JSON_THROW_ON_ERROR),PHP_EOL;
  ' -- "$mode" "$primary" "$phpunit_status" "$db_ready" "$no_tcp" "$clean" "$sql_version" > /work/safety.json
  local receipt=$?
  [[ "$receipt" == 0 && "$clean" == true ]] || exit 1
  exit "$primary"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -m 700 /work/db
/usr/bin/mariadb-install-db --no-defaults --basedir=/usr --datadir=/work/db \
  --auth-root-authentication-method=normal --skip-networking --skip-test-db --skip-name-resolve >/work/init.log 2>&1
/usr/sbin/mariadbd --no-defaults --basedir=/usr --datadir=/work/db \
  --socket=/work/db.sock --pid-file=/work/db.pid --skip-networking \
  --tmpdir=/work --log-error=/work/db.log &
server_pid=$!
if [[ "$mode" == partial ]]; then
  # Abort after process spawn, before a successful readiness observation.
  kill -0 "$server_pid"
  exit 72
fi
client=(/usr/bin/mariadb --no-defaults --protocol=SOCKET --socket=/work/db.sock --user=root --batch --skip-column-names)
for ((i=0;i<80;i++)); do
  kill -0 "$server_pid"
  if [[ -S /work/db.sock ]] && "${client[@]}" -e 'SELECT 1' >/dev/null 2>&1; then
    db_ready=true
    break
  fi
  sleep 0.25
done
[[ "$db_ready" == true && $("${client[@]}" -e 'SELECT @@GLOBAL.skip_networking') == 1 ]]
# Refuse any listening TCP socket in the isolated namespace.
awk 'FNR>1 && $4=="0A" {bad=1} END {exit bad+0}' /proc/net/tcp /proc/net/tcp6
no_tcp=true
sql_version=$("${client[@]}" -e 'SELECT VERSION()')
"${client[@]}" -e 'CREATE DATABASE digiforge_tests'
phpunit_status=0
/usr/bin/php -d auto_prepend_file=/opt/shop2/fixture/prepend.php \
  /opt/shop2/fixture/vendor/bin/phpunit -c /opt/shop2/fixture/phpunit.xml \
  --filter testFixtureLifecycle --log-junit /work/junit.xml || phpunit_status=$?
exit "$phpunit_status"
