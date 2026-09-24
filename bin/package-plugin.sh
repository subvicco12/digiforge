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
manifest="${output_dir}/digiforge.release-manifest.json"
temporary_archive="$(mktemp "${TMPDIR:-/tmp}/digiforge.XXXXXX.zip")"
trap 'rm -f "${temporary_archive}"' EXIT

mkdir -p "${output_dir}"
rm -f "${archive}" "${checksum}" "${manifest}"

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
(
  cd "${output_dir}"
  sha256sum --check digiforge.zip.sha256
)

version="$(php -r '$s=file_get_contents($argv[1]); if(!preg_match("/const DIGIFORGE_VERSION = \'([^\']+)\';/",$s,$m)){exit(2);} echo $m[1];' "${repo_root}/digiforge.php")"
schema="$(php -r '$s=file_get_contents($argv[1]); if(!preg_match("/const DIGIFORGE_DB_VERSION = \'([^\']+)\';/",$s,$m)){exit(2);} echo $m[1];' "${repo_root}/digiforge.php")"
commit="$(git -C "${repo_root}" rev-parse HEAD)"
archive_sha256="$(awk '{print $1}' "${checksum}")"
file_count="$(unzip -Z1 "${archive}" | wc -l | tr -d ' ')"

DIGIFORGE_RELEASE_VERSION="${version}" \
DIGIFORGE_RELEASE_SCHEMA="${schema}" \
DIGIFORGE_RELEASE_COMMIT="${commit}" \
DIGIFORGE_RELEASE_SHA256="${archive_sha256}" \
DIGIFORGE_RELEASE_FILE_COUNT="${file_count}" \
DIGIFORGE_RELEASE_MANIFEST="${manifest}" \
php -r '
$payload = [
    "schema" => "digiforge-release-manifest-v1",
    "plugin_version" => getenv("DIGIFORGE_RELEASE_VERSION"),
    "database_schema_version" => getenv("DIGIFORGE_RELEASE_SCHEMA"),
    "commit_sha" => getenv("DIGIFORGE_RELEASE_COMMIT"),
    "artifact" => "digiforge.zip",
    "sha256" => getenv("DIGIFORGE_RELEASE_SHA256"),
    "file_count" => (int) getenv("DIGIFORGE_RELEASE_FILE_COUNT"),
    "external_actions_performed" => false,
    "production_activation_authorized" => false
];
file_put_contents(getenv("DIGIFORGE_RELEASE_MANIFEST"), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
'
test -s "${manifest}"
php -r '$m=json_decode(file_get_contents($argv[1]),true); if(!is_array($m) || ($m["schema"]??"")!=="digiforge-release-manifest-v1" || ($m["artifact"]??"")!=="digiforge.zip" || !preg_match("/^[a-f0-9]{64}$/",(string)($m["sha256"]??"")) || ($m["external_actions_performed"]??true)!==false || ($m["production_activation_authorized"]??true)!==false){exit(1);}' "${manifest}"
