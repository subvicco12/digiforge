#!/usr/bin/env bash
set -euo pipefail
repo_root="$(git rev-parse --show-toplevel)"
output_arg="${1:-build}"
if [[ "${output_arg}" = /* ]]; then
  output_dir="${output_arg}"
else
  output_dir="${repo_root}/${output_arg}"
fi
# Gate2 uses an explicit preservation delta, not a cumulative source archive.
exec python3 "${repo_root}/bin/package-gate2-pod.py" --output "${output_dir}"
