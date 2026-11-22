"""Inspect OCI bytes without extraction. Admit only exact reviewed custody."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import tarfile

CAP = 2 * 1024**3
MANIFEST = "application/vnd.oci.image.manifest.v1+json"
CONFIG = "application/vnd.oci.image.config.v1+json"
LAYER = "application/vnd.oci.image.layer.v1.tar+gzip"
SOURCE_IDS = {
    "lane_b": "3ca5fa241694843628b52f4800c9662bc33521dc",
    "lane_a": "002973a0cfb93cfab132e6c446a5ea34adb27d37",
    "application_tree": "0259c42e8db360804300ec3d8ca1dee95c5f7711",
    "composition_commit": "86f3cb995508e18ea8ae3567c9f018613f19dbd4",
    "zip_sha256": "475e775e270224cc371805ae3252ca1757da7225d37d18a6fb522a211fdb7bd7",
}

def need(ok, reason):
    if not ok:
        raise ValueError(reason)

def file_sha(path):
    need(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= CAP, "Invalid evidence file")
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()

def bounded_json(path, limit=4*1024**2):
    need(path.is_file() and not path.is_symlink() and path.stat().st_size <= limit, "Invalid JSON file")
    return json.loads(path.read_bytes())

def inspect_archive(path):
    archive_sha = file_sha(path)
    with tarfile.open(path, "r:") as tar:
        files = {}
        for member in tar:
            name = member.name
            if member.isdir():
                need(name in {"blobs", "blobs/sha256"}, "Unexpected OCI directory")
                continue
            need(member.isfile() and member.type in {tarfile.REGTYPE, tarfile.AREGTYPE}
                 and member.sparse is None and 0 <= member.size <= CAP
                 and (name in {"oci-layout", "index.json"} or re.fullmatch(r"blobs/sha256/[0-9a-f]{64}", name))
                 and name not in files and len(files) < 128, "Unsafe OCI archive member")
            files[name] = member
        def json_member(name):
            member = files[name]
            need(member.size <= 4 * 1024**2, "Oversized OCI JSON")
            with tar.extractfile(member) as stream:
                return json.load(stream)
        need(json_member("oci-layout") == {"imageLayoutVersion": "1.0.0"}, "Wrong OCI layout")
        index = json_member("index.json")
        need(index.get("schemaVersion") == 2 and len(index.get("manifests", [])) == 1, "Expected one OCI image")
        used = {"oci-layout", "index.json"}
        def descriptor(d, media):
            digest = d["digest"]
            need(re.fullmatch(r"sha256:[0-9a-f]{64}", digest) and d["mediaType"] == media
                 and not d.get("urls") and not d.get("data"), "Unexpected OCI descriptor")
            name = "blobs/sha256/" + digest[7:]
            member = files[name]
            need(member.size == d["size"] and member.size > 0, "OCI descriptor size mismatch")
            with tar.extractfile(member) as stream:
                need(hashlib.file_digest(stream, "sha256").hexdigest() == digest[7:], "OCI blob digest mismatch")
            used.add(name)
            return name
        descriptor_entry = index["manifests"][0]
        platform = descriptor_entry.get("platform")
        need(platform is None or (platform.get("os"), platform.get("architecture")) == ("linux", "amd64"), "Wrong descriptor platform")
        image = json_member(descriptor(descriptor_entry, MANIFEST))
        need(image.get("schemaVersion") == 2 and not image.get("subject") and 0 < len(image.get("layers", [])) <= 64, "Wrong image manifest")
        configuration = json_member(descriptor(image["config"], CONFIG))
        need((configuration.get("os"), configuration.get("architecture")) == ("linux", "amd64"), "Wrong OCI platform")
        for layer in image["layers"]:
            descriptor(layer, LAYER)
        need(set(files) == used, "Unreferenced OCI bytes")
        actual = configuration["config"]
        need(actual.get("User") == "10001:10001"
             and actual.get("Entrypoint") == ["/bin/bash", "/opt/shop2/fixture/entrypoint.sh"]
             and not actual.get("ExposedPorts") and not actual.get("Volumes"), "Wrong image runtime contract")
        return {"archive_sha256": archive_sha, "image_manifest_digest": descriptor_entry["digest"],
                "image_config_digest": image["config"]["digest"], "platform": "linux/amd64"}

def admit(args):
    approval = bounded_json(args.approval)
    need(approval.get("approved") is True and approval.get("schema") == 1, "OCI approval absent")
    need(all(type(approval[key]) is int and approval[key] > 0 for key in ("run_id", "run_attempt", "artifact_id"))
         and re.fullmatch(r"[0-9a-f]{40}", approval["producer_sha"])
         and re.fullmatch(r"sha256:[0-9a-f]{64}", approval["github_artifact_digest"]), "Invalid custody pins")
    run = bounded_json(args.run)
    artifact = bounded_json(args.artifact)
    repo = "subvicco12/digiforge"
    need(approval["source"] == SOURCE_IDS and approval["repository"] == repo, "Wrong application or repository")
    need(run["id"] == approval["run_id"] and run["run_attempt"] == approval["run_attempt"]
         and run["head_sha"] == approval["producer_sha"] and run["conclusion"] == "success"
         and run["head_repository"]["full_name"] == repo and run["repository"]["full_name"] == repo
         and run["path"] == ".github/workflows/shop2-oci-fixture.yml", "Wrong producer run")
    need(artifact["id"] == approval["artifact_id"] and artifact["name"] == "shop2-oci-runtime"
         and artifact["expired"] is False and artifact["workflow_run"]["id"] == run["id"]
         and artifact["digest"] == approval["github_artifact_digest"], "Wrong artifact custody")
    image = inspect_archive(args.archive)
    need(all(value == approval[key] for key, value in image.items()), "OCI approval mismatch")
    need(file_sha(args.manifest) == approval["component_manifest_sha256"]
         and file_sha(args.receipt) == approval["qualification_receipt_sha256"], "Evidence hash mismatch")
    manifest = bounded_json(args.manifest)
    receipt = bounded_json(args.receipt)
    need(manifest["schema"] == 1 and manifest["source"] == SOURCE_IDS and manifest["image"] == image
         and manifest["input_lock_sha256"] == manifest["source_manifest"]["input_lock_sha256"] == approval["input_lock_sha256"]
         and manifest["producer"] == {"sha": run["head_sha"], "run_id": run["id"], "attempt": run["run_attempt"]},
         "Component manifest identity mismatch")
    results = receipt.get("modes", {})
    need(receipt["schema"] == 1 and receipt["image"] == image and receipt["source"] == SOURCE_IDS
         and receipt["producer"] == manifest["producer"] and set(results) == {"pass", "assertion-failure", "partial"}
         and [results[key]["exit"] for key in ("pass", "assertion-failure", "partial")] == [0, 1, 72]
         and all(r["checked"] is True and r["reaped_and_cleaned"] is True
                 and r["non_root_uid"] == 10001 and r["only_loopback"] is True
                 and r["read_only_root"] is True for r in results.values())
         and all(results[key]["no_tcp_listener"] is True and results[key]["assertions"] >= 4
                 for key in ("pass", "assertion-failure"))
         and results["partial"]["assertions"] == 0, "Qualification evidence mismatch")
    return {"schema": 1, "verdict": "ADMITTED", "source": SOURCE_IDS, "image": image,
            "producer_sha": run["head_sha"], "run_id": run["id"], "run_attempt": run["run_attempt"],
            "artifact_id": artifact["id"], "component_manifest_sha256": approval["component_manifest_sha256"],
            "qualification_receipt_sha256": approval["qualification_receipt_sha256"]}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("archive", type=Path)
    parser.add_argument("--approval", type=Path)
    for flag in ("run", "artifact", "manifest", "receipt"):
        parser.add_argument("--" + flag, type=Path)
    args = parser.parse_args()
    try:
        if args.approval:
            need(all(getattr(args, n) for n in ("run", "artifact", "manifest", "receipt")), "Custody metadata required")
            result = admit(args)
        else:
            result = inspect_archive(args.archive)
    except (ValueError, OSError, KeyError, TypeError, AttributeError, tarfile.TarError) as error:
        raise SystemExit("OCI rejected: " + str(error)) from None
    print(json.dumps(result, indent=2, sort_keys=True))

if __name__ == "__main__":
    main()
