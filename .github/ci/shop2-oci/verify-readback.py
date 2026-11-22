"""Unpack a hash-approved GitHub wrapper without using archive extraction APIs."""
import hashlib
import json
from pathlib import Path
import shutil
import sys
import zipfile

wrapper, approval, destination = map(Path, sys.argv[1:])
pin = json.loads(approval.read_bytes())
if pin.get("approved") is not True or not 0 < wrapper.stat().st_size <= 2 * 1024**3:
    raise SystemExit("Wrapper is unapproved or unbounded")
with wrapper.open("rb") as stream:
    if "sha256:" + hashlib.file_digest(stream, "sha256").hexdigest() != pin["github_artifact_digest"]:
        raise SystemExit("GitHub wrapper digest mismatch")
with zipfile.ZipFile(wrapper) as archive:
    limits = {"runtime.oci.tar": 2*1024**3, "component-manifest.json": 4*1024**2, "qualification.json": 4*1024**2}
    entries = archive.infolist()
    if len(entries) != 3 or {i.filename for i in entries} != limits.keys():
        raise SystemExit("Unexpected GitHub archive members")
    destination.mkdir(mode=0o700)
    for entry in entries:
        kind = (entry.external_attr >> 16) & 0o170000
        if kind not in {0, 0o100000} or entry.is_dir() or not 0 < entry.file_size <= limits[entry.filename]:
            raise SystemExit("Unsafe GitHub archive member")
        with archive.open(entry) as source, (destination / entry.filename).open("xb") as output:
            shutil.copyfileobj(source, output)
