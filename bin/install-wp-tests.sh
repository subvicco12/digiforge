#!/usr/bin/env bash
set -euo pipefail

database_name="${1:-digiforge_tests}"
database_user="${2:-root}"
database_password="${3:-root}"
database_host="${4:-127.0.0.1:3306}"
wp_version="${5:-latest}"

if [[ "${wp_version}" == latest ]]; then
  core_url="https://wordpress.org/latest.tar.gz"
elif [[ "${wp_version}" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
  core_url="https://wordpress.org/wordpress-${wp_version}.tar.gz"
else
  echo 'A released WordPress version or latest is required.' >&2
  exit 2
fi

tests_dir="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
core_dir="${WP_CORE_DIR:-/tmp/wordpress}"

rm -rf "${tests_dir}" "${core_dir}"
mkdir -p "${tests_dir}" "${core_dir}"

curl --fail --silent --show-error --location --connect-timeout 15 --max-time 120 --retry 3 --retry-delay 2 "${core_url}" |
  tar --strip-components=1 -xz -C "${core_dir}"

resolved_version="$(php -r 'require $argv[1]; if (!isset($wp_version) || !preg_match("/^[0-9]+\\.[0-9]+(?:\\.[0-9]+)?$/D", $wp_version)) { fwrite(STDERR, "Released WordPress core version required.\n"); exit(2); } echo $wp_version;' "${core_dir}/wp-includes/version.php")"
if [[ "${wp_version}" != latest && "${wp_version}" != "${resolved_version}" ]]; then
  echo 'Downloaded core does not match the requested WordPress version.' >&2
  exit 2
fi
tests_source="https://develop.svn.wordpress.org/tags/${resolved_version}"
echo "WordPress core and test library: ${resolved_version}"
timeout 120 svn export --force --quiet "${tests_source}/tests/phpunit/includes/" "${tests_dir}/includes"
timeout 120 svn export --force --quiet "${tests_source}/tests/phpunit/data/" "${tests_dir}/data"
curl --fail --silent --show-error --location --connect-timeout 15 --max-time 120 --retry 3 --retry-delay 2 "${tests_source}/wp-tests-config-sample.php" --output "${tests_dir}/wp-tests-config.php"

sed -i "s/youremptytestdbnamehere/${database_name}/" "${tests_dir}/wp-tests-config.php"
sed -i "s/yourusernamehere/${database_user}/" "${tests_dir}/wp-tests-config.php"
sed -i "s/yourpasswordhere/${database_password}/" "${tests_dir}/wp-tests-config.php"
sed -i "s|localhost|${database_host}|" "${tests_dir}/wp-tests-config.php"
sed -i "s|dirname( __FILE__ ) . '/src/'|'${core_dir}/'|" "${tests_dir}/wp-tests-config.php"
