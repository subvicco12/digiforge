#!/usr/bin/env python3
"""Build or verify the passive standalone plugin from exact Git blobs."""

import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import zipfile


REPO = Path(__file__).resolve().parents[2]
PLUGIN = "digiforge-printify-api"
SOURCE = f"standalone/printify-api/{PLUGIN}"
MEMBERS = ("Status.php", "digiforge-printify-api.php", "readme.txt")


def git(*args: str) -> bytes:
    return subprocess.check_output(("git", "-C", str(REPO), *args))


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def build(source: str) -> bytes:
    commit = git("rev-parse", "--verify", "--end-of-options", f"{source}^{{commit}}").decode().strip()
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Expected an exact SHA-1 Git commit")
    blobs = {name: git("show", f"{commit}:{SOURCE}/{name}") for name in MEMBERS}
    manifest = {
        "schema": "DF_STANDALONE_PRINTIFY_PACKAGE_1",
        "source_sha": commit,
        "mode": "PASSIVE_OFFLINE",
        "members": {f"{PLUGIN}/{name}": sha256(data) for name, data in blobs.items()},
    }
    blobs["source-manifest.json"] = (json.dumps(manifest, sort_keys=True, indent=2) + "\n").encode()
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w") as archive:
        for name, data in sorted(blobs.items()):
            entry = zipfile.ZipInfo(f"{PLUGIN}/{name}", date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, data)
    return output.getvalue()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_mutually_exclusive_group(required=True)
    commands.add_argument("--output", type=Path)
    commands.add_argument("--verify", type=Path)
    parser.add_argument("--source", default="HEAD", help="Git commit to build or verify against")
    args = parser.parse_args()
    expected = build(args.source)
    if args.verify is not None:
        if not args.verify.is_file() or args.verify.stat().st_size != len(expected):
            parser.exit(1, "Package length differs from the exact source build\n")
        if args.verify.read_bytes() != expected:
            parser.exit(1, "Package bytes differ from the exact source build\n")
        print(f"Verified {args.verify}: {sha256(expected)}")
    else:
        with args.output.open("xb") as output:
            output.write(expected)
        print(f"Wrote {args.output}: {sha256(expected)}")


if __name__ == "__main__":
    main()
