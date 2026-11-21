#!/usr/bin/env bash
set -euo pipefail
umask 077
root=$(cd -- "$(dirname -- "$0")" && pwd -P)
approval=$1
out=$2
jq -e '.schema == 1 and .approved == true and .repository == "subvicco12/digiforge"
       and (.run_id | type == "number" and .>0 and .==floor)
       and (.artifact_id | type == "number" and .>0 and .==floor)' "$approval" >/dev/null
run=$(jq -r .run_id "$approval")
artifact=$(jq -r .artifact_id "$approval")
mkdir -m 700 "$out"
# Future consumer uses only a separately authorized GitHub read token.
gh api "repos/subvicco12/digiforge/actions/runs/$run" > "$out/run.json"
gh api "repos/subvicco12/digiforge/actions/artifacts/$artifact" > "$out/artifact.json"
gh api "repos/subvicco12/digiforge/actions/artifacts/$artifact/zip" > "$out/github-wrapper.zip"
python3 "$root/verify-readback.py" "$out/github-wrapper.zip" "$approval" "$out/runtime"
python3 "$root/oci-admission.py" "$out/runtime/runtime.oci.tar" --approval "$approval"   --run "$out/run.json" --artifact "$out/artifact.json" --manifest "$out/runtime/component-manifest.json"   --receipt "$out/runtime/qualification.json" > "$out/admission.json.tmp"
mv "$out/admission.json.tmp" "$out/admission.json"
