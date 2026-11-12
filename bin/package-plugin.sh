#!/usr/bin/env bash
set -euo pipefail
repo_root="$(git rev-parse --show-toplevel)"
output_arg="${1:-build}"
if [[ "${output_arg}" = /* ]]; then
  output_dir="${output_arg}"
else
  output_dir="${repo_root}/${output_arg}"
fi
# Normalized .17 is an explicit qualified-payload baseline, not a cumulative archive.
exec python3 "${repo_root}/bin/package-normalized-10317.py" --output "${output_dir}"
