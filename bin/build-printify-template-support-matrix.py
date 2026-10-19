#!/usr/bin/env python3
"""Build a transparent local-render and supplier-evidence inventory for Master 500 v2."""
import csv
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs/engineering/original-source/Master_500_v2.tsv"
OUTPUT = ROOT / "docs/engineering/printify-template-support-20261011.tsv"
LOCAL = {"NAME_MONOGRAM", "PHOTO_TEXT", "SINGLE_PHOTO", "PHOTO_COLLAGE", "MULTI_PHOTO", "FACE_REPEAT", "PET_FACE_REPEAT", "AOP_PATTERN", "HANDWRITING", "CHILD_ART", "MEMORIAL"}
CONTRACT_ONLY = {"PET_PORTRAIT", "WATERCOLOR", "LINE_ART", "VINTAGE_PORTRAIT", "AI_TRANSFORM"}

with SOURCE.open(encoding="utf-8", newline="") as stream:
    rows = list(csv.DictReader(stream, delimiter="\t"))
if len(rows) != 500:
    raise SystemExit(f"Expected exactly 500 Master 500 v2 concepts; found {len(rows)}")

fields = list(rows[0]) + ["Local renderer status", "Printify supplier status", "Exact blocker"]
with OUTPUT.open("w", encoding="utf-8", newline="") as stream:
    writer = csv.DictWriter(stream, fieldnames=fields, delimiter="\t", lineterminator="\n")
    writer.writeheader()
    for row in rows:
        engine = row["Engine"]
        if engine in LOCAL:
            local = "LOCAL_ENGINE_IMPLEMENTED"
            local_reason = "Existing deterministic renderer engine; exact product and provider geometry still required."
        elif engine in CONTRACT_ONLY:
            local = "CONTRACT_ONLY_NO_RENDERER"
            local_reason = "Versioned engine contract exists; specialized deterministic or authorized provider renderer is not implemented/certified."
        else:
            local = "UNSUPPORTED_ENGINE"
            local_reason = "No matching local deterministic renderer or approved engine contract was identified."
        row.update({
            "Local renderer status": local,
            "Printify supplier status": "NOT_VERIFIED",
            "Exact blocker": local_reason + " Printify catalog variant/placement evidence, supplier safe-zone/bleed specifications, and approved physical samples are not established by this offline inventory.",
        })
        writer.writerow(row)
print(f"Wrote {len(rows)} concept rows to {OUTPUT.relative_to(ROOT)}")
