#!/usr/bin/env bash
set -euo pipefail
out=$RUNNER_TEMP/digiforge-coex-evidence
mkdir -m 700 "$out"
fixture=$RUNNER_TEMP/digiforge-coex-input
control=$RUNNER_TEMP/digiforge-coex-control
mkdir -m 700 "$fixture" "$control"
trap 'status=$?; trap - EXIT; printf "%s\n" "$status" > "$out/supervisor-exit.txt"; if [ -n "${cid:-}" ]; then docker logs "$cid" > "$out/container.log" 2>&1 || true; docker rm -f "$cid" >/dev/null || true; fi; find "$out" -type l -delete; (cd "$out"; find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS); exit "$status"' EXIT
sha=$(sha256sum qualification/digiforge-standalone/approved.json | cut -d' ' -f1)
[[ "$sha" == "$SEALED_APPROVAL" ]]
export FIXTURE_INPUT="$fixture"
python3 - <<'PY'
import hashlib,json,os,re,shutil,tarfile
from pathlib import Path
p=Path('qualification/digiforge-standalone')
m=json.loads((p/'approved.json').read_text())
assert m['schema']=='DF_COEX_ADMISSION_1'
assert m['actor']==os.environ['GITHUB_ACTOR']==os.environ['GITHUB_TRIGGERING_ACTOR']
assert m['ref']==os.environ['GITHUB_REF'] and os.environ['GITHUB_REF_PROTECTED']=='true'
assert m['readme_approval']=='OWNER_AUTHORIZED_ONE_RUN'
assert m['profile']=='production-no-woocommerce'
assert set(m['versions'])=={'wordpress','php','mariadb','chromium'}
assert m['versions']['wordpress']=='7.1.5'
assert m['versions']['php']=='8.3.37'
assert m['versions']['mariadb']=='11.8.9'
assert all(re.fullmatch(r'[0-9]+(?:[.][0-9]+){1,4}',m['versions'][x]) for x in ('chromium',))
assert re.fullmatch(r'sha256:[a-f0-9]{64}',m['image_id'])
assert re.fullmatch(r'sha256:[a-f0-9]{64}',m['oci_manifest_digest'])
assert m['inputs']['standalone.zip']=='f36db9e5da8b74f2b427f9ca5a2982ed2f54cc97490c361ddec3d611e40d2223'
assert m['inputs']['digiforge.zip']=='e12c923d6e8342570506a4f20ff3b4ba4b6f67e39bbee7e313c9e87aad1e8aa0'
assert m['inputs']['wordpress.zip']=='cd44160a3fcce179fe170331132fcfc05e84e2f9284e2aad38df2aea6edaeab1'
assert m['inputs']['shipping.zip']=='c36cfd01f5de69ce36e0024c97c4109bee972a6be3a27e89bc762383aad02157'
expected={'runtime.oci.tar','standalone.zip','digiforge.zip','shipping.zip','wordpress.zip'}
assert set(m['inputs'])==expected
for name in sorted(expected):
    f=Path(os.environ['SEALED_INPUT_DIR'])/name
    assert f.is_file() and not f.is_symlink()
    with f.open('rb') as stream:
        if name!='runtime.oci.tar':
            assert hashlib.file_digest(stream,'sha256').hexdigest()==m['inputs'][name]
    if name!='runtime.oci.tar':
        shutil.copyfile(f,Path(os.environ['FIXTURE_INPUT'])/name)
for name, digest in m['control_sha256'].items():
    assert hashlib.sha256(Path(name).read_bytes()).hexdigest()==digest
PY
dir=qualification/digiforge-standalone
cp "$dir"/{approved.json,qualify-inside.sh,workload.sh,lifecycle.php,browser.cjs,browser-adapter.php} "$control/"
cp -r "$dir"/{tests,gateway} "$control/"
python3 "$dir/oci-convert.py" "$SEALED_INPUT_DIR/runtime.oci.tar" "$RUNNER_TEMP/coex-docker.tar" > "$out/oci.json"
jq -e --slurpfile expected "$dir/approved.json" '.manifest==$expected[0].oci_manifest_digest and .image_id==$expected[0].image_id' "$out/oci.json" >/dev/null
sha256sum "$SEALED_INPUT_DIR/runtime.oci.tar" > "$out/oci-archive.sha256"
find "$control" -type d -exec chmod 755 {} +
find "$control" "$fixture" -type f -exec chmod 644 {} +
image_id=$(jq -er .image_id qualification/digiforge-standalone/approved.json)
docker load -i "$RUNNER_TEMP/coex-docker.tar" > "$out/docker-load.txt"
[[ "$(docker image inspect "$image_id" --format '{{.Id}}')" == "$image_id" ]]
[[ "$(docker image inspect "$image_id" --format '{{.Os}}/{{.Architecture}}')" == linux/amd64 ]]
chmod 755 "$control" "$fixture"
# The isolated UID owns only the empty output; it cannot write or see the Git checkout.
sudo setfacl -m u:65532:rwx "$out"
cid=$(docker create --pull never --network none --read-only --no-healthcheck --user 65532:65532 --cap-drop ALL --security-opt no-new-privileges \
  --pids-limit 384 --memory 6g --tmpfs /tmp:rw,nosuid,nodev,size=1536m,uid=65532,gid=65532 \
  --tmpfs /work:rw,nosuid,nodev,size=2048m,uid=65532,gid=65532 \
  --mount "type=bind,src=$fixture,dst=/fixture,readonly" --mount "type=bind,src=$control,dst=/admission,readonly" \
  --mount "type=bind,src=$out,dst=/evidence" --entrypoint /bin/bash "$image_id" /admission/qualify-inside.sh)
docker inspect "$cid" | jq '.[0] | {Id,Image,User:.Config.User,NetworkMode:.HostConfig.NetworkMode,CapDrop:.HostConfig.CapDrop,SecurityOpt:.HostConfig.SecurityOpt,Mounts}' > "$out/container-inspect.json"
jq -e --arg id "$image_id" '.Image==$id and .User=="65532:65532" and .NetworkMode=="none" and .CapDrop==["ALL"] and (.Mounts|length==3)' "$out/container-inspect.json" >/dev/null
docker start -a "$cid" > "$out/console.log" 2>&1
[[ "$(docker inspect --format '{{.State.ExitCode}}' "$cid")" == 0 ]]
jq -e '.status=="PASS" and .fixture_only==true and .mariadb_socket_only==true and .isolated==true' "$out/qualification.json" >/dev/null
printf '{"run":"%s","attempt":"%s","head":"%s","image":"%s","manifest":"%s"}\n' "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" "$GITHUB_SHA" "$image_id" "$sha" > "$out/identity.json"
