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
MEMBERS = ("AccountProfile.php", "CustodyReadTransport.php", "SyntheticWebhookReceiver.php", "SyntheticWorkspace.php", "SyntheticWorkspacePanel.php", "SyntheticJourney.php", "SyntheticOperatorPanel.php", "CatalogBrowser.php", "CatalogBrowserPanel.php", "RecordBrowserPanel.php", "SyntheticPrintifyClient.php", "SyntheticPrintifyTransport.php", "NativeHeldInspection.php", "HeldAuthorityAdapter.php", "HeldSignedEvidence.php", "held-authority.v1.json", "SnapshotEvidence.php", "FixtureSnapshotStore.php", "OfflineLifecycle.php", "Runtime.php", "Status.php", "digiforge-printify-api.php", "readme.txt")


def git(*args: str) -> bytes:
    return subprocess.check_output(("git", "-C", str(REPO), *args))


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def build(source: str) -> bytes:
    commit = git("rev-parse", "--verify", "--end-of-options", f"{source}^{{commit}}").decode().strip()
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Expected an exact SHA-1 Git commit")
    members = MEMBERS
    protected_get = f"{commit}:{SOURCE}/ProtectedGet.php"
    if subprocess.run(("git", "-C", str(REPO), "cat-file", "-e", protected_get),
                      capture_output=True, check=False).returncode == 0:
        members += ("ProtectedGet.php", "ProtectedGetPanel.php")
    workspace = f"{commit}:{SOURCE}/ProtectedGetWorkspace.php"
    if subprocess.run(("git", "-C", str(REPO), "cat-file", "-e", workspace),
                      capture_output=True, check=False).returncode == 0:
        members += ("ProtectedGetWorkspace.php",)
    http = f"{commit}:{SOURCE}/ProtectedGetHttp.php"
    if subprocess.run(("git", "-C", str(REPO), "cat-file", "-e", http),
                      capture_output=True, check=False).returncode == 0:
        members += ("ProtectedGetHttp.php",)
    direct = f"{commit}:{SOURCE}/DirectGet.php"
    if subprocess.run(("git", "-C", str(REPO), "cat-file", "-e", direct),
                      capture_output=True, check=False).returncode == 0:
        members += ("DirectGet.php",)
    secret = f"{commit}:{SOURCE}/DirectReadSecret.php"
    if subprocess.run(("git", "-C", str(REPO), "cat-file", "-e", secret),
                      capture_output=True, check=False).returncode == 0:
        members += ("DirectReadSecret.php",)
    managed = f"{commit}:{SOURCE}/ManagedConnection.php"
    if subprocess.run(("git", "-C", str(REPO), "cat-file", "-e", managed),
                      capture_output=True, check=False).returncode == 0:
        members += ("ManagedConnection.php",)
    blobs = {name: git("show", f"{commit}:{SOURCE}/{name}") for name in members}
    contracts = (
        ("POD/PrintifyCatalogReadContract.php", "Engine/PrintifyCatalogReadContract.php"),
        ("POD/PrintifyProductRequest.php", "Engine/POD/PrintifyProductRequest.php"),
        ("Listings/Validator.php", "Engine/Listings/Validator.php"),
        ("POD/PrintifyApiRequest.php", "Engine/POD/PrintifyApiRequest.php"),
        ("POD/PrintifyApiReadEvidence.php", "Engine/POD/PrintifyApiReadEvidence.php"),
        ("POD/PrintifyOrderEvidence.php", "Engine/POD/PrintifyOrderEvidence.php"),
        ("POD/PrintifyUploadContract.php", "Engine/POD/PrintifyUploadContract.php"),
        ("POD/PrintifyProductResponse.php", "Engine/POD/PrintifyProductResponse.php"),
        ("POD/PrintifySupportContract.php", "Engine/POD/PrintifySupportContract.php"),
        ("POD/PrintifyLifecycleRequest.php", "Engine/POD/PrintifyLifecycleRequest.php"),
        ("POD/PrintifyLifecycleEvidence.php", "Engine/POD/PrintifyLifecycleEvidence.php"),
        ("POD/PrintifyApiOutcome.php", "Engine/POD/PrintifyApiOutcome.php"),
        ("Research/DesignFactoryContract.php", "Engine/Research/DesignFactoryContract.php"),
    )
    upstream = {}
    for relative, member in contracts:
        path = "includes/" + relative
        data = git("show", f"{commit}:{path}")
        namespace = b"namespace DigiForge\\"
        if data.count(namespace) != 1:
            raise ValueError(f"Unexpected namespace in {path}")
        relocated = data.replace(namespace, b"namespace DigiForgePrintifyApi\\Engine\\", 1)
        relocated = relocated.replace(
            b"\\DigiForge\\Research\\DesignFactoryContract::",
            b"\\DigiForgePrintifyApi\\Engine\\Research\\DesignFactoryContract::",
        )
        if member in {"Engine/POD/PrintifyApiRequest.php", "Engine/POD/PrintifySupportContract.php"}:
            relocated = relocated.replace(b"\\DigiForge\\Listings\\Validator::",
                                          b"\\DigiForgePrintifyApi\\Engine\\Listings\\Validator::")
        if member in {"Engine/POD/PrintifyLifecycleRequest.php", "Engine/POD/PrintifyUploadContract.php"}:
            relocated = relocated.replace(b"use DigiForge\\Listings\\Validator;",
                                          b"use DigiForgePrintifyApi\\Engine\\Listings\\Validator;")
        if member == "Engine/PrintifyCatalogReadContract.php":
            relocated = relocated.replace(b"namespace DigiForgePrintifyApi\\Engine\\POD;",
                                          b"namespace DigiForgePrintifyApi\\Engine;")
        blobs[member] = relocated
        upstream[path] = sha256(data)
    manifest = {
        "schema": "DF_STANDALONE_PRINTIFY_PACKAGE_1",
        "source_sha": commit,
        "mode": "DEGRADED_READ_ONLY",
        "upstream_contracts": upstream,
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
