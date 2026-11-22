#!/usr/bin/env bash
set -euo pipefail
umask 077
root=$(cd -- "$(dirname -- "$0")" && pwd -P)
out=$1
tmp=$(mktemp -d)
container=
cleanup() {
  if [[ -n "$container" ]]; then docker rm -f "$container" >/dev/null 2>&1 || true; fi
  sudo -n rm -rf -- "$tmp"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
python3 "$root/prepare-context.py" "$tmp/context" "$tmp/inputs"
# The built-in driver is part of the reviewed hosted runner. Never pull a floating external builder.
docker info --format '{{json .DriverStatus}}' | jq -e 'any(.[]; .[1] == "io.containerd.snapshotter.v1")' >/dev/null
docker buildx build --builder default --platform linux/amd64 --pull=false --network=none \
  --provenance=false --sbom=false --output "type=oci,dest=$tmp/runtime.oci.tar,oci-mediatypes=true,compression=gzip" "$tmp/context"
python3 "$root/oci-admission.py" "$tmp/runtime.oci.tar" > "$tmp/image.json"
image_config=$(jq -er '.image_config_digest' "$tmp/image.json")
# Load and select by config digest. No tag or remote fallback can satisfy this check.
docker load --input "$tmp/runtime.oci.tar"
[[ $(docker image inspect --format '{{.Id}}' "$image_config") == "$image_config" ]]
for mode in pass assertion-failure partial; do
  work="$tmp/$mode"
  sudo -n install -d -m 0700 -o 10001 -g 10001 "$work"
  container=$(docker create --pull=never --platform=linux/amd64 --network none --read-only \
    --cap-drop ALL --security-opt no-new-privileges --init --user 10001:10001 \
    --pids-limit 128 --memory 1536m --cpus 2 --log-driver none \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=64m \
    --mount "type=bind,src=$work,dst=/work" "$image_config" "$mode")
  docker start "$container" >/dev/null
  timeout 120s docker wait "$container" >/dev/null
  docker inspect "$container" > "$tmp/$mode.inspect.json"
  sudo -n chown -hR "$(id -u):$(id -g)" "$work"
  python3 "$root/check-fixture.py" "$work" "$tmp/$mode.inspect.json" "$mode" "$tmp/$mode.checked.json"
  if [[ "$mode" == pass ]]; then
    docker cp "$container:/opt/shop2/source-manifest.json" "$tmp/source-manifest.readback.json"
    cmp "$tmp/source-manifest.readback.json" "$tmp/context/source-manifest.json"
    docker cp "$container:/opt/shop2/dpkg-installed.tsv" "$tmp/dpkg-installed.tsv"
  fi
  docker rm "$container" >/dev/null
  [[ -z $(docker ps -aq --filter "id=$container") ]]
  container=
done
mkdir -m 700 "$out"
python3 "$root/make-receipts.py" "$tmp" "$out"
mv "$tmp/runtime.oci.tar" "$out/runtime.oci.tar"
