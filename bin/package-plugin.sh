#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
output_arg="${1:-build}"

if [[ "${output_arg}" = /* ]]; then
  output_dir="${output_arg}"
else
  output_dir="${repo_root}/${output_arg}"
fi

archive="${output_dir}/digiforge.zip"
checksum="${archive}.sha256"
temporary_archive="$(mktemp "${TMPDIR:-/tmp}/digiforge.XXXXXX.zip")"
trap 'rm -f "${temporary_archive}"' EXIT

mkdir -p "${output_dir}"
rm -f "${archive}" "${checksum}"

git -C "${repo_root}" archive \
  --format=zip \
  --prefix=digiforge/ \
  --output="${temporary_archive}" \
  HEAD

mv "${temporary_archive}" "${archive}"
(
  cd "${output_dir}"
  sha256sum digiforge.zip > digiforge.zip.sha256
)

test -s "${archive}"
test -s "${checksum}"
