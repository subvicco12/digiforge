#!/usr/bin/env bash
set -euo pipefail

output_dir="${1:-build}"
package_root="${output_dir}/digiforge"
archive="${output_dir}/digiforge.zip"

rm -rf "${package_root}" "${archive}" "${archive}.sha256"
mkdir -p "${package_root}"

rsync -a ./ "${package_root}/" \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.phpunit.cache/' \
  --exclude='.phpstan.cache/' \
  --exclude='build/' \
  --exclude='bin/' \
  --exclude='docs/' \
  --exclude='tests/' \
  --exclude='vendor/' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='phpcs.xml.dist' \
  --exclude='phpstan.neon.dist' \
  --exclude='phpunit.xml.dist' \
  --exclude='phpunit.wordpress.xml.dist'

(
  cd "${output_dir}"
  zip -qr digiforge.zip digiforge
  sha256sum digiforge.zip > digiforge.zip.sha256
)

test -s "${archive}"
test -s "${archive}.sha256"
