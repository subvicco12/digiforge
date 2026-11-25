#!/usr/bin/env bash
set -euo pipefail
umask 077
context=$(mktemp -d "$RUNNER_TEMP/runtime-context.XXXXXXXX")
out="$RUNNER_TEMP/digiforge-runtime-output"
mkdir -p "$out"
printf '%s\n' "$GITHUB_SHA" "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" > "$out/git-run.txt"
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
  --output "type=oci,compression=gzip,force-compression=true,dest=$context/runtime.oci.tar" --metadata-file "$out/build.json" "$context"
# Convert the verified OCI payload into the classic Docker load format locally.
# Keep the original OCI archive and use the compatibility archive only to load.
python3 - "$context/runtime.oci.tar" "$context/runtime.docker.tar" > "$out/oci.json" <<'PYTHON'
import gzip
import hashlib
import io
import json
import re
import sys
import tarfile

with tarfile.open(sys.argv[1]) as archive:
    members = {}
    for item in archive:
        name = item.name.removeprefix("./")
        if name in members:
            raise ValueError(f"Duplicate OCI archive member: {name}")
        members[name] = item

    def member(name):
        item = members[name]
        if not item.isreg():
            raise ValueError(f"Expected regular OCI member: {name}")
        return item

    def verified_blob(descriptor):
        digest = descriptor["digest"]
        if not re.fullmatch(r"sha256:[0-9a-f]{64}", digest):
            raise ValueError(f"Unsupported OCI digest: {digest}")
        item = member("blobs/sha256/" + digest[7:])
        with archive.extractfile(item) as source:
            if item.size != descriptor["size"] or hashlib.file_digest(source, "sha256").hexdigest() != digest[7:]:
                raise ValueError(f"OCI blob mismatch: {digest}")
        return item

    descriptors = json.load(archive.extractfile(member("index.json")))["manifests"]
    if len(descriptors) != 1:
        raise ValueError("Expected one OCI manifest")
    descriptor = descriptors[0]
    manifest = json.load(archive.extractfile(verified_blob(descriptor)))
    config_item = verified_blob(manifest["config"])
    config = json.load(archive.extractfile(config_item))
    if (config["os"], config["architecture"]) != ("linux", "amd64"):
        raise ValueError("Expected linux/amd64 OCI configuration")
    diff_ids = config["rootfs"]["diff_ids"]
    if len(diff_ids) != len(manifest["layers"]):
        raise ValueError("OCI layer count differs from image configuration")

    def add_bytes(output, name, data):
        item = tarfile.TarInfo(name)
        item.size = len(data)
        output.addfile(item, io.BytesIO(data))

    config_name = manifest["config"]["digest"][7:] + ".json"
    layers = []
    with tarfile.open(sys.argv[2], "w") as output:
        with archive.extractfile(config_item) as source:
            add_bytes(output, config_name, source.read())
        for number, (layer, diff_id) in enumerate(zip(manifest["layers"], diff_ids)):
            if layer["mediaType"] != "application/vnd.oci.image.layer.v1.tar+gzip":
                raise ValueError(f"Unsupported OCI layer media type: {layer['mediaType']}")
            item = verified_blob(layer)
            with archive.extractfile(item) as source, gzip.GzipFile(fileobj=source) as raw:
                actual_diff_id = "sha256:" + hashlib.file_digest(raw, "sha256").hexdigest()
            if actual_diff_id != diff_id:
                raise ValueError(f"OCI layer {number} DiffID mismatch")
            name = f"layer-{number}.tar.gz"
            layer_info = tarfile.TarInfo(name)
            layer_info.size = item.size
            with archive.extractfile(item) as source:
                output.addfile(layer_info, source)
            layers.append(name)
        docker_manifest = [{"Config": config_name, "RepoTags": ["digiforge-runtime:verified"], "Layers": layers}]
        add_bytes(output, "manifest.json", json.dumps(docker_manifest).encode())
    print(json.dumps({"manifest": descriptor["digest"], "image_id": manifest["config"]["digest"]}))
PYTHON
mv "$context/runtime.oci.tar" "$out/runtime.oci.tar"
docker load -i "$context/runtime.docker.tar" > "$out/import.txt" 2>&1
docker image inspect digiforge-runtime:verified --format '{{.Id}} {{.Os}}/{{.Architecture}}' > "$out/loaded-image.txt"
image=$(jq -er .image_id "$out/oci.json")
[[ "$(cat "$out/loaded-image.txt")" == "$image linux/amd64" ]]
docker run --rm --pull never --network none --entrypoint /bin/bash "$image" -ec '
php -r "exit(PHP_VERSION===\"8.3.37\" && extension_loaded(\"pdo_mysql\") && extension_loaded(\"mysqli\") && extension_loaded(\"sodium\") && extension_loaded(\"sqlite3\") && extension_loaded(\"pdo_sqlite\") ? 0 : 1);"
php --version
mariadbd --version | grep -F 11.8.9
chromium --version
cat /opt/runtime-packages.tsv
' > "$out/runtime.txt"
