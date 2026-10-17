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

curl --fail --silent --show-error --location --connect-timeout 15 --max-time 120 --retry 3 --retry-delay 2 "https://wordpress.org/${wp_version}.tar.gz" |
  tar --strip-components=1 -xz -C "${core_dir}"

# Match the test fixture revision to the installed WordPress release.
# Trunk bootstrap may require interfaces absent from released core.
core_version="$(php -r 'require $argv[1]; echo $wp_version;' "${core_dir}/wp-includes/version.php")"
if [[ ! "${core_version}" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
  echo "Unsupported WordPress core version: ${core_version}" >&2
  exit 1
fi
fixture_base="https://develop.svn.wordpress.org/tags/${core_version}"
timeout 120 svn export --force --quiet "${fixture_base}/tests/phpunit/includes/" "${tests_dir}/includes"
timeout 120 svn export --force --quiet "${fixture_base}/tests/phpunit/data/" "${tests_dir}/data"
curl --fail --silent --show-error --location --connect-timeout 15 --max-time 120 --retry 3 --retry-delay 2 "${fixture_base}/wp-tests-config-sample.php" --output "${tests_dir}/wp-tests-config.php"

sed -i "s/youremptytestdbnamehere/${database_name}/" "${tests_dir}/wp-tests-config.php"
sed -i "s/yourusernamehere/${database_user}/" "${tests_dir}/wp-tests-config.php"
sed -i "s/yourpasswordhere/${database_password}/" "${tests_dir}/wp-tests-config.php"
sed -i "s|localhost|${database_host}|" "${tests_dir}/wp-tests-config.php"
sed -i "s|dirname( __FILE__ ) . '/src/'|'${core_dir}/'|" "${tests_dir}/wp-tests-config.php"
