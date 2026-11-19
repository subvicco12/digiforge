#!/usr/bin/env bash
set -euo pipefail
# Runtime container has --network none; DB is a disposable local Unix-socket fixture.
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld
mariadbd --user=mysql --skip-networking --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/audit.pid >/tmp/mysql.log 2>&1 &
for attempt in {1..60}; do
  if mariadb --socket=/run/mysqld/mysqld.sock -uroot -e 'SELECT 1' >/dev/null 2>&1; then break; fi
  sleep 1
done
mariadb --socket=/run/mysqld/mysqld.sock -uroot -e 'CREATE DATABASE digiforge_tests'
export EXACT_PLUGIN_ROOT=/artifact/digiforge
php --version > /results/php-version.txt
composer show --format=json > /results/dependencies.json
while IFS= read -r -d '' file; do php -l "$file"; done < <(find "$EXACT_PLUGIN_ROOT" -name '*.php' -print0) > /results/php-lint.txt
php tests/artifact-audit/contracts.php > /results/interfaces.json
php tests/artifact-audit/drive-boundary.php > /results/drive-boundary.json
vendor/bin/phpunit tests/artifact-audit/unit/SharedPrintifySizeGuideContractTest.php --log-junit /results/size-guide.xml
vendor/bin/phpunit -c tests/artifact-audit/wordpress.xml --log-junit /results/wordpress.xml
vendor/bin/phpunit -c tests/artifact-audit/rate.xml --log-junit /results/rate.xml
