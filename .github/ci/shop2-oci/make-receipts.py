"""Bind checked evidence and the final image to exact source and build identities."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys

tmp, out = map(Path, sys.argv[1:])
source = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("oci_admission", source / "oci-admission.py")
oci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(oci)
image = json.loads((tmp / "image.json").read_bytes())
inputs = json.loads((tmp / "context/source-manifest.json").read_bytes())
raw = (tmp / "dpkg-installed.tsv").read_bytes()
if len(raw) > 262144:
    raise SystemExit("Unbounded installed package list")
installed = {}
for line in raw.decode().splitlines():
    name, version, arch = line.split("\t")
    if not re.fullmatch(r"[a-z0-9.+:-]+", name) or not re.fullmatch(r"[A-Za-z0-9._+~:-]+", version) or arch not in {"all", "amd64"}:
        raise SystemExit("Invalid installed package identity")
    if name in installed:
        raise SystemExit("Duplicate package identity")
    installed[name] = {"version": version, "architecture": arch}
locked = json.loads((source / "input-lock.json").read_bytes())
for item in locked["debian"]:
    if installed.get(item["name"]) != {"version": item["version"], "architecture": item["arch"]}:
        raise SystemExit("Installed package version mismatch")
workflow_sha = os.environ["GITHUB_WORKFLOW_SHA"]
if not re.fullmatch(r"[0-9a-f]{40}", workflow_sha):
    raise SystemExit("Invalid producer source SHA")
producer = {"sha": workflow_sha, "run_id": int(os.environ["GITHUB_RUN_ID"]), "attempt": int(os.environ["GITHUB_RUN_ATTEMPT"])}
runner = {name: os.environ.get(name) for name in ("ImageOS", "ImageVersion", "RUNNER_ARCH")}
runner["buildx"] = subprocess.check_output(["docker", "buildx", "version"], text=True).split()[1]
runner["docker_server"] = subprocess.check_output(["docker", "version", "--format", "{{.Server.Version}}"], text=True).strip()
if not all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9.+_-]{1,100}", v) for v in runner.values()):
    raise SystemExit("Missing or invalid hosted runner identity")
manifest = {"schema": 1, "source": oci.SOURCE_IDS, "image": image, "producer": producer,
            "input_lock_sha256": inputs["input_lock_sha256"], "source_manifest": inputs, "runner": runner,
            "installed": installed, "dpkg_manifest_sha256": hashlib.sha256(raw).hexdigest()}
receipt = {"schema": 1, "source": oci.SOURCE_IDS, "image": image, "producer": producer,
           "modes": {mode: json.loads((tmp / (mode + ".checked.json")).read_bytes())
                     for mode in ("pass", "assertion-failure", "partial")}}
for name, result in (("component-manifest.json", manifest), ("qualification.json", receipt)):
    path = out / name
    path.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n")
    if path.stat().st_size > 4 * 1024**2:
        raise SystemExit("Unbounded artifact metadata")
