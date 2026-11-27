#!/usr/bin/env python3
"""Build two disabled first-create review archives. Never contacts Cloudflare."""

import argparse
import hashlib
import json
from pathlib import Path
import posixpath
import re
import subprocess
import zipfile


ROOT = Path(__file__).resolve().parents[3]
PREFIX = "contracts/cloudflare-dispatcher/"


def encode(value):
    return (json.dumps(value, indent=2, sort_keys=True) + "\n").encode()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def build(source, directory):
    if re.fullmatch(r"[0-9a-f]{40}", source) is None:
        raise ValueError("An exact commit SHA is required")

    def blob(path):
        return subprocess.check_output(["git", "-C", str(ROOT), "show", source + ":" + PREFIX + path])

    configs = [
        ("custody-bridge", json.loads(blob("custody-bridge/wrangler.template.json"))),
        ("stage-a-caller", json.loads(blob("custody-bridge/stage-a-caller.template.json"))),
    ]
    bridge, caller = [config for _, config in configs]
    caller["migrations"] = [{
        "tag": "stage-a-caller-v1", "new_sqlite_classes": ["DigiForgeStageACallerState"],
    }]
    if (bridge["name"] != "digiforge-custody-qualification"
            or caller["name"] != "digiforge-stage-a-caller"
            or caller["services"] != [{"binding": "DF_CUSTODY_BRIDGE", "service": bridge["name"]}]
            or caller["workflows"] != [{"name": "digiforge-stage-a-manual", "binding": "DF_MANUAL_WORKFLOW",
                                        "class_name": "DigiForgeStageAManual"}]
            or bridge.get("services") or bridge.get("workflows")):
        raise ValueError("Wrong private topology")

    for name, config in configs:
        binding, expected_class, tag, mode, scope = (
            ("DF_CUSTODY_STATE", "CustodyFixtureState", "custody-fixture-v1", "DF_CUSTODY_MODE", "DF_CUSTODY_SCOPE_JSON")
            if name == "custody-bridge" else
            ("DF_CALLER_STATE", "DigiForgeStageACallerState", "stage-a-caller-v1",
             "DF_CUSTODY_CALLER_MODE", "DF_CUSTODY_CALLER_CONFIG")
        )
        if (config["workers_dev"] is not False or config["preview_urls"] is not False
                or config["routes"] != [] or config["vars"] != {mode: "DISABLED", scope: ""}
                or set(config) & {"triggers", "queues", "secrets_store_secrets", "unsafe"}
                or config["durable_objects"]["bindings"] != [{"name": binding, "class_name": expected_class}]
                or config["migrations"] != [{"tag": tag, "new_sqlite_classes": [expected_class]}]):
            raise ValueError("Unsafe disabled first-create configuration")
        modules = config["rules"][0]["globs"]
        if config["main"] not in modules or any("*" in name for name in modules):
            raise ValueError("Runtime closure must enumerate exact modules")
        files = {path: blob(path) for path in modules}
        for path, data in files.items():
            for target in re.findall(r"""from\s+['"]([^'"]+)['"]""", data.decode()):
                if target == "cloudflare:workers":
                    continue
                if not target.startswith(".") or posixpath.normpath(posixpath.join(posixpath.dirname(path), target)) not in files:
                    raise ValueError("Unpackaged runtime dependency: " + target)
        files["wrangler.json"] = encode(config)
        files["source-manifest.json"] = encode({
            "source_sha": source, "mode": "DISABLED_FIRST_CREATE_REVIEW_ONLY",
            "files": {path: digest(content) for path, content in files.items()},
        })
        archive = directory / (name + "-first-create-disabled.zip")
        with zipfile.ZipFile(archive, "x") as package:
            for path, content in sorted(files.items()):
                info = zipfile.ZipInfo(path)
                info.external_attr = 0o100644 << 16
                package.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        print(archive, digest(archive.read_bytes()))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source")
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    build(args.source, args.output)
