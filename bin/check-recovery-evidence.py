#!/usr/bin/env python3
"""Read-only fail-closed assessment of redacted recovery evidence JSON."""
import argparse
import json
import os
import re
import stat
import sys

SHA = re.compile(r"^[0-9a-f]{64}$")
GIT_COMMIT = re.compile(r"^[0-9a-f]{40}$")

def assess(evidence):
    errors = []
    if not isinstance(evidence, dict):
        return {"status": "REVIEW_REQUIRED", "errors": ["expected evidence object"],
                "read_only": True, "checker_performed_external_actions": False}
    allowed_keys = {"environment", "stop_all", "externally_locked", "automation_enabled",
                    "external_actions_performed", "runtime_schema_current",
                    "backup_currently_retrievable", "backup_checksum_verified",
                    "installed_package_identity_verified", "installed_commit_sha",
                    "certified_package_sha256", "installed_package_sha256",
                    "drill_package_sha256", "backup_sha256", "drill_backup_sha256",
                    "drill_fresh", "drill_bound_to_current_artifacts", "drill_passed"}
    if set(evidence) - allowed_keys:
        errors.append("unexpected recovery evidence fields")
    def require(condition, message):
        if not condition:
            errors.append(message)
    require(evidence.get("environment") == "digiforgestaging.converentis.com", "isolated staging identity unverified")
    require(evidence.get("stop_all") is True, "STOP ALL not confirmed")
    require(evidence.get("externally_locked") is True, "external safety lock not confirmed")
    require(evidence.get("automation_enabled") is False, "automation not confirmed disabled")
    require(evidence.get("external_actions_performed") is False, "external action evidence unsafe")
    require(evidence.get("runtime_schema_current") is True, "runtime schema not confirmed current")
    require(evidence.get("backup_currently_retrievable") is True, "current backup retrieval unverified")
    require(evidence.get("backup_checksum_verified") is True, "backup checksum unverified")
    require(evidence.get("installed_package_identity_verified") is True, "installed package identity unverified")
    commit = evidence.get("installed_commit_sha")
    require(isinstance(commit, str) and bool(GIT_COMMIT.fullmatch(commit)), "installed Git commit SHA missing or malformed")
    for field in ("certified_package_sha256", "installed_package_sha256", "drill_package_sha256", "backup_sha256", "drill_backup_sha256"):
        value = evidence.get(field)
        require(isinstance(value, str) and bool(SHA.fullmatch(value)), field + " missing or malformed")
    require(evidence.get("installed_package_sha256") == evidence.get("certified_package_sha256"), "installed package does not match certified package")
    require(evidence.get("drill_package_sha256") == evidence.get("certified_package_sha256"), "drill package not bound to certified package")
    require(evidence.get("drill_backup_sha256") == evidence.get("backup_sha256"), "drill backup not bound to current backup")
    require(evidence.get("drill_fresh") is True, "drill evidence stale")
    require(evidence.get("drill_bound_to_current_artifacts") is True, "drill evidence unbound")
    require(evidence.get("drill_passed") is True, "drill evidence not passed")
    return {"status": "REVIEW_REQUIRED" if errors else "EVIDENCE_PREDICATES_PASS",
            "errors": errors, "read_only": True, "checker_performed_external_actions": False}

def reject_duplicate_keys(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate JSON evidence key")
        result[key] = value
    return result

def reject_nonfinite_constant(value):
    raise ValueError("non-finite JSON numeric constant")

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("evidence_json", help="Local redacted evidence file; never modified")
    args = parser.parse_args()
    try:
        if os.path.islink(args.evidence_json):
            raise ValueError("evidence symlink not permitted")
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
        descriptor = os.open(args.evidence_json, flags)
        with os.fdopen(descriptor, "rb") as stream:
            if not stat.S_ISREG(os.fstat(stream.fileno()).st_mode):
                raise ValueError("evidence must be a regular file")
            payload = stream.read(1024 * 1024 + 1)
        if len(payload) > 1024 * 1024:
            raise ValueError("evidence file exceeds size limit")
        data = json.loads(payload.decode("utf-8"), object_pairs_hook=reject_duplicate_keys,
                          parse_constant=reject_nonfinite_constant)
        if not isinstance(data, dict):
            raise ValueError("expected JSON object")
        result = assess(data)
    except (OSError, ValueError, UnicodeError, json.JSONDecodeError):
        result = {"status": "REVIEW_REQUIRED", "errors": ["invalid evidence file"],
                  "read_only": True, "checker_performed_external_actions": False}
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0 if result["status"] == "EVIDENCE_PREDICATES_PASS" else 1

if __name__ == "__main__":
    sys.exit(main())
