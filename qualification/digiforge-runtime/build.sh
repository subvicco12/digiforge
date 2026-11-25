#!/usr/bin/env bash
set -euo pipefail
umask 077
context=$(mktemp -d "$RUNNER_TEMP/runtime-context.XXXXXXXX")
out="$RUNNER_TEMP/digiforge-runtime-output"
mkdir "$out"
cp qualification/digiforge-runtime/Dockerfile "$context/"
echo "a3c66e6638bbdb8ead6278fdb508612c226884879d93cea40032fd5f2e0e549f  $context/Dockerfile" | sha256sum -c -
curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 180 https://codeload.github.com/php/php-src/tar.gz/refs/tags/php-8.3.37 -o "$context/php-src.tar.gz"
echo "2d08c1e67defb2f5f2db6c9c2c07642002dbdb6c3b0e24cf0552940f32cb3cf7  $context/php-src.tar.gz" | sha256sum -c -
docker version > "$out/docker.txt"
docker buildx version > "$out/buildx.txt"
df -h "$RUNNER_TEMP" > "$out/disk.txt"
builder="digiforge-${GITHUB_RUN_ID}"
trap 'docker buildx rm "$builder" >/dev/null 2>&1 || true' EXIT
docker buildx create --name "$builder" --driver docker-container
docker buildx build --builder "$builder" --platform linux/amd64 --provenance=false --sbom=false \
  --output "type=oci,dest=$out/runtime.oci.tar" --metadata-file "$out/build.json" "$context"
python3 - "$out/runtime.oci.tar" > "$out/oci.json" <<'PY'
import hashlib, json, sys, tarfile
with tarfile.open(sys.argv[1]) as archive:
    def read(name):
        candidates = [item for item in archive if item.name.removeprefix("./") == name]
        assert len(candidates) == 1 and candidates[0].isreg()
        return archive.extractfile(candidates[0]).read()
    def blob(desc):
        algorithm, digest = desc["digest"].split(":")
        assert algorithm == "sha256"
        data = read("blobs/sha256/" + digest)
        assert len(data) == desc["size"] and hashlib.sha256(data).hexdigest() == digest
        return data
    descriptors = json.loads(read("index.json"))["manifests"]
    assert len(descriptors) == 1
    manifest = json.loads(blob(descriptors[0]))
    config = json.loads(blob(manifest["config"]))
    assert (config["os"], config["architecture"]) == ("linux", "amd64")
    for layer in manifest["layers"]:
        blob(layer)
    print(json.dumps({"manifest": descriptors[0]["digest"], "image_id": manifest["config"]["digest"]}))
PY
docker load -i "$out/runtime.oci.tar" > "$out/import.txt"
image=$(jq -er .image_id "$out/oci.json")
[[ "$(docker image inspect "$image" --format '{{.Os}}/{{.Architecture}}')" == linux/amd64 ]]
docker run --rm --pull never --network none --entrypoint /bin/bash "$image" -ec '
php -r "exit(PHP_VERSION===\"8.3.37\" && extension_loaded(\"pdo_mysql\") && extension_loaded(\"mysqli\") && extension_loaded(\"sodium\") ? 0 : 1);"
php --version
mariadbd --version | grep -F 11.8.9
chromium --version
cat /opt/runtime-packages.tsv
' > "$out/runtime.txt"
printf '%s\n' "$GITHUB_SHA" "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" > "$out/git-run.txt"
(cd "$out"; sha256sum runtime.oci.tar oci.json build.json runtime.txt git-run.txt docker.txt buildx.txt disk.txt import.txt > SHA256SUMS)
