#!/usr/bin/env bash
set -euo pipefail
umask 077
manifest=qualification/digiforge-standalone/approved.json
[[ "$(sha256sum "$manifest" | cut -d' ' -f1)" == "$SEALED_APPROVAL" ]]
mkdir -m 700 "$SEALED_INPUT_DIR"
incoming=$(mktemp -d "$RUNNER_TEMP/coex-acquire.XXXXXXXX")
trap 'rm -rf "$incoming"' EXIT
# The short-lived restricted input URL is passed only in curl configuration on stdin.
if [[ ! "${COEX_INPUTS_URL:-}" =~ ^https://[a-z0-9-]+\.oaiusercontent\.com/files/[a-zA-Z0-9/._-]+/raw\?[a-zA-Z0-9%/+=\&._-]+$ ]]; then
  echo "Restricted input URL unavailable or unsupported"
  exit 1
fi
if ! printf 'url = "%s"\n' "$COEX_INPUTS_URL" |
  curl --config - --proto '=https' --fail --silent --max-time 90 --max-filesize 6000000 --output "$incoming/inputs.zip"; then
  echo "Restricted input download failed"
  exit 1
fi
unset COEX_INPUTS_URL
echo "928643e25f69d9e927f623e8e662130186f2308810fa818847f999a9de75c344  $incoming/inputs.zip" | sha256sum -c -
id=15041584844
gh api "repos/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID" > "$incoming/current.json"
workflow=$(jq -er .workflow_id "$incoming/current.json")
gh api --paginate "repos/$GITHUB_REPOSITORY/actions/workflows/$workflow/runs?head_sha=$SEALED_HEAD&per_page=100" |
  jq -es --argjson current "$GITHUB_RUN_ID" '[.[].workflow_runs[] | select(.id != $current)] | length==0' >/dev/null
gh api "repos/$GITHUB_REPOSITORY/actions/runs/45654093505" |
  jq -e '.run_attempt==1 and .conclusion=="success" and .head_sha=="32658cb75b8b1843e5f76bb3cbecb6b7bde87a40"' >/dev/null
gh api "repos/$GITHUB_REPOSITORY/actions/artifacts/$id" |
  jq -e '.id==15041584844 and .expired==false and .size_in_bytes==608860198 and .workflow_run.id==45654093505 and .digest=="sha256:b1ded43bd6da4fd94a7ed400e0add96f3f012469b45899ca54bd3ee20a8d109c"' >/dev/null
gh api "repos/$GITHUB_REPOSITORY/actions/artifacts/$id/zip" > "$incoming/runtime.zip"
echo "b1ded43bd6da4fd94a7ed400e0add96f3f012469b45899ca54bd3ee20a8d109c  $incoming/runtime.zip" | sha256sum -c -
unset GH_TOKEN
python3 - "$incoming" <<'PY'
import hashlib, os, re, shutil, sys, zipfile
from pathlib import Path
incoming = Path(sys.argv[1])
output = Path(os.environ['SEALED_INPUT_DIR'])
with zipfile.ZipFile(incoming / 'runtime.zip') as archive:
    receipt = {}
    for line in archive.read('SHA256SUMS').decode().splitlines():
        match = re.fullmatch(r'([a-f0-9]{64})  (?:\./)?([A-Za-z0-9._-]+)', line)
        assert match and match[2] not in receipt
        receipt[match[2]] = match[1]
    assert set(archive.namelist()) == set(receipt) | {'SHA256SUMS'}
    assert len(archive.namelist()) == len(receipt) + 1
    for name, digest in receipt.items():
        with archive.open(name) as member:
            assert hashlib.file_digest(member, 'sha256').hexdigest() == digest
    assert archive.read('git-run.txt').decode().splitlines() == [
        '32658cb75b8b1843e5f76bb3cbecb6b7bde87a40', '45654093505', '1']
    with archive.open('runtime.oci.tar') as source, (output / 'runtime.oci.tar').open('xb') as target:
        shutil.copyfileobj(source, target)
with zipfile.ZipFile(incoming / 'inputs.zip') as archive:
    names = ('standalone.zip', 'digiforge.zip', 'shipping.zip')
    assert sorted(archive.namelist()) == sorted(names)
    for name in names:
        with archive.open(name) as source, (output / name).open('xb') as target:
            shutil.copyfileobj(source, target)
PY
curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 120 \
  https://wordpress.org/wordpress-7.1.5.zip -o "$SEALED_INPUT_DIR/wordpress.zip"
echo "cd44160a3fcce179fe170331132fcfc05e84e2f9284e2aad38df2aea6edaeab1  $SEALED_INPUT_DIR/wordpress.zip" | sha256sum -c -
