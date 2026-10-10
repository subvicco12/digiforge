#!/usr/bin/env python3
"""Read-only, fail-closed structural check of the owner-supplied Master 500 v2 XLSX."""
import argparse
import collections
import hashlib
import json
import posixpath
import re
import sys
import zipfile
from xml.etree import ElementTree as ET

NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
      "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"}
REL = "{http://schemas.openxmlformats.org/package/2006/relationships}"

def load_rows(path):
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        if len({item.filename for item in entries}) != len(entries):
            raise ValueError("workbook archive contains duplicate entry paths")
        if len(entries) > 2000 or sum(item.file_size for item in entries) > 100 * 1024 * 1024:
            raise ValueError("workbook archive exceeds safe entry or uncompressed size limits")
        if any(item.file_size > 25 * 1024 * 1024 for item in entries):
            raise ValueError("workbook archive contains oversized entry")
        names = set(archive.namelist())
        workbook = ET.fromstring(archive.read("xl/workbook.xml"))
        relations = ET.fromstring(archive.read("xl/_rels/workbook.xml.rels"))
        relation_entries = relations.findall(REL + "Relationship")
        relation_ids = [entry.attrib["Id"] for entry in relation_entries]
        if len(relation_ids) != len(set(relation_ids)):
            raise ValueError("workbook contains duplicate relationship IDs")
        targets = {entry.attrib["Id"]: entry.attrib["Target"] for entry in relation_entries}
        strings = []
        if "xl/sharedStrings.xml" in names:
            root = ET.fromstring(archive.read("xl/sharedStrings.xml"))
            strings = ["".join(t.text or "" for t in si.findall(".//m:t", NS))
                       for si in root.findall("m:si", NS)]
        output = {}
        for sheet in workbook.findall(".//m:sheets/m:sheet", NS):
            name = sheet.attrib["name"]
            if name not in {"Source_Migration_500", "Master_500_v2", "Family_Rebalance"}:
                continue
            target = targets[sheet.attrib["{" + NS["r"] + "}id"]].lstrip("/")
            if not target.startswith("xl/"):
                target = "xl/" + target
            normalized = posixpath.normpath(target)
            if not normalized.startswith("xl/worksheets/") or normalized not in names:
                raise ValueError("worksheet target escapes expected archive directory")
            root = ET.fromstring(archive.read(normalized))
            rows = []
            for row in root.findall(".//m:sheetData/m:row", NS):
                values = {}
                for cell in row.findall("m:c", NS):
                    ref = cell.attrib.get("r", "")
                    match = re.match(r"([A-Z]+)", ref)
                    if not match:
                        continue
                    index = 0
                    for char in match.group(1):
                        index = index * 26 + ord(char) - 64
                    raw = cell.find("m:v", NS)
                    if cell.attrib.get("t") == "inlineStr":
                        value = "".join(t.text or "" for t in cell.findall(".//m:t", NS))
                    elif raw is None:
                        value = ""
                    elif cell.attrib.get("t") == "s":
                        value = strings[int(raw.text)]
                    else:
                        value = raw.text or ""
                    values[index - 1] = str(value).strip()
                rows.append([values.get(i, "") for i in range(max(values, default=-1) + 1)])
            output[name] = rows
        return output

def check(path):
    errors = []
    rows = load_rows(path)
    required = {"Source_Migration_500": ["Source DG ID", "Wave", "V1 Family", "V1 Concept", "Engine", "Disposition", "Migration Note", "V2 Successor ID"],
                "Master_500_v2": ["V2 ID", "Source DG ID", "Family", "Concept", "Engine", "Physical Product", "Supplier Gate", "Template State", "Origin", "Recommended Stage"],
                "Family_Rebalance": ["Family", "V1 Count", "V2 Target"]}
    for sheet, headers in required.items():
        data = rows.get(sheet, [])
        if not data or data[0][:len(headers)] != headers:
            errors.append(sheet + ": missing sheet or unexpected headers")
    if errors:
        return errors, {}
    source = rows["Source_Migration_500"][1:]
    v2 = rows["Master_500_v2"][1:]
    families = rows["Family_Rebalance"][1:]
    if len(source) != 500 or len(v2) != 500:
        errors.append("expected exactly 500 source and 500 v2 rows")
    def field(row, index):
        return row[index] if index < len(row) else ""
    def unique(records, index, label, require_all=False):
        values = [field(row, index) for row in records]
        present = [v for v in values if v]
        if require_all and len(present) != len(values):
            errors.append(label + ": blank identifier")
        if len(present) != len(set(present)):
            errors.append(label + ": duplicate identifier")
        return set(present)
    source_ids = unique(source, 0, "source IDs", True)
    v2_ids = unique(v2, 0, "v2 IDs", True)
    successors = unique(source, 7, "successor IDs")
    v2_sources = unique(v2, 1, "v2 source references")
    if successors - v2_ids:
        errors.append("successor references missing v2 IDs")
    if v2_sources - source_ids:
        errors.append("v2 references missing source IDs")
    expected_sources = {field(row, 0) for row in source if field(row, 5) == "KEEP"}
    if v2_sources != expected_sources:
        errors.append("v2 source references do not match KEEP source IDs")
    if len(successors) != 428:
        errors.append("expected 428 direct successor mappings")
    reverse = {field(row, 0): field(row, 1) for row in v2}
    origins = collections.Counter(field(row, 8) for row in v2)
    if origins != {"V1 retained": 428, "V2 addition": 30, "V2 new family": 42}:
        errors.append("unexpected v2 origin distribution")
    for i, row in enumerate(v2, 2):
        origin, source_ref = field(row, 8), field(row, 1)
        if (origin == "V1 retained") != bool(source_ref):
            errors.append("v2 row %d: origin/source reference mismatch" % i)
        if any(not field(row, index) for index in (2, 3, 4)):
            errors.append("v2 row %d: required family/concept/engine field blank" % i)
        if not field(row, 5) or not field(row, 6) or not field(row, 7) or not field(row, 9):
            errors.append("v2 row %d: required supplier/template/stage field blank" % i)
        if field(row, 6) not in {"PRINTIFY_PRIMARY", "ROUTE_BY_BASE_PRODUCT", "PROVIDER_RESEARCH_REQUIRED"}:
            errors.append("v2 row %d: unknown supplier gate" % i)
    for i, row in enumerate(source, 2):
        disposition, successor = field(row, 5), field(row, 7)
        if any(not field(row, index) for index in (1, 2, 3, 4)):
            errors.append("source row %d: required wave/family/concept/engine field blank" % i)
        if disposition not in {"KEEP", "MERGE", "DOWNGRADE"}:
            errors.append("source row %d: invalid disposition" % i)
        if disposition == "KEEP" and (not successor or reverse.get(successor) != field(row, 0)):
            errors.append("source row %d: missing/mismatched KEEP lineage" % i)
        if disposition != "KEEP" and successor:
            errors.append("source row %d: non-KEEP direct successor" % i)
    if collections.Counter(field(row, 5) for row in source) != {"KEEP": 428, "MERGE": 37, "DOWNGRADE": 35}:
        errors.append("unexpected source disposition distribution")
    counts = collections.Counter(field(row, 2) for row in v2)
    targets = {}
    for i, row in enumerate(families, 2):
        name, target = field(row, 0), field(row, 2)
        if not name:
            errors.append("family row %d: blank family" % i)
            continue
        try:
            number = int(target)
        except ValueError:
            errors.append("family row %d: noninteger target" % i)
            continue
        if name in targets:
            errors.append("family row %d: duplicate family" % i)
        targets[name] = number
    if len(targets) != 24:
        errors.append("expected exactly 24 family targets")
    if any(value <= 0 for value in targets.values()):
        errors.append("family targets must be positive")
    if sum(targets.values()) != 500:
        errors.append("family targets must sum to 500")
    if counts != collections.Counter(targets):
        errors.append("family counts differ from targets")
    summary = {"source_rows": len(source), "v2_rows": len(v2),
               "direct_successors": len(successors), "v2_source_references": len(v2_sources),
               "families": len(targets), "dispositions": dict(collections.Counter(field(r, 5) for r in source))}
    return errors, summary

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("workbook", help="Local XLSX candidate file; never modified")
    args = parser.parse_args()
    digest = None
    try:
        with open(args.workbook, "rb") as stream:
            digest = hashlib.file_digest(stream, "sha256").hexdigest()
        errors, summary = check(args.workbook)
    except (OSError, ValueError, KeyError, IndexError, ET.ParseError, zipfile.BadZipFile) as exc:
        errors, summary = ["unreadable or invalid workbook: " + type(exc).__name__], {}
    print(json.dumps({"status": "REVIEW_REQUIRED" if errors else "STRUCTURAL_PASS_ONLY",
                      "sha256": digest, "summary": summary, "errors": errors}, indent=2, sort_keys=True))
    return 1 if errors else 0

if __name__ == "__main__":
    sys.exit(main())
