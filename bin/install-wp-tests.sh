#!/usr/bin/env bash
set -euo pipefail

database_name="${1:-digiforge_tests}"
database_user="${2:-root}"
database_password="${3:-root}"
database_host="${4:-127.0.0.1:3306}"
wp_version="${5:-latest}"

tests_dir="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
core_dir="${WP_CORE_DIR:-/tmp/wordpress}"

rm -rf "${tests_dir}" "${core_dir}"
mkdir -p "${tests_dir}" "${core_dir}"

curl --fail --silent --show-error --location "https://wordpress.org/${wp_version}.tar.gz" |
  tar --strip-components=1 -xz -C "${core_dir}"

svn export --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "${tests_dir}/includes"
svn export --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "${tests_dir}/data"
curl --fail --silent --show-error --location   https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php   --output "${tests_dir}/wp-tests-config.php"

sed -i "s/youremptytestdbnamehere/${database_name}/" "${tests_dir}/wp-tests-config.php"
sed -i "s/yourusernamehere/${database_user}/" "${tests_dir}/wp-tests-config.php"
sed -i "s/yourpasswordhere/${database_password}/" "${tests_dir}/wp-tests-config.php"
sed -i "s|localhost|${database_host}|" "${tests_dir}/wp-tests-config.php"
sed -i "s|dirname( __FILE__ ) . '/src/'|'${core_dir}/'|" "${tests_dir}/wp-tests-config.php"
