#!/usr/bin/env python3
"""Read-only, fail-closed structural check of the owner-supplied Master 500 v2 XLSX."""
import argparse
import collections
import hashlib
import json
import re
import sys
import zipfile
from xml.etree import ElementTree as ET

NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
      "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"}
REL = "{http://schemas.openxmlformats.org/package/2006/relationships}"

def load_rows(path):
    with zipfile.ZipFile(path) as archive:
        names = set(archive.namelist())
        workbook = ET.fromstring(archive.read("xl/workbook.xml"))
        relations = ET.fromstring(archive.read("xl/_rels/workbook.xml.rels"))
        targets = {r.attrib["Id"]: r.attrib["Target"] for r in relations}
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
            root = ET.fromstring(archive.read(target))
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
    reverse = {field(row, 0): field(row, 1) for row in v2}
    for i, row in enumerate(source, 2):
        disposition, successor = field(row, 5), field(row, 7)
        if disposition not in {"KEEP", "MERGE", "DOWNGRADE"}:
            errors.append("source row %d: invalid disposition" % i)
        if disposition == "KEEP" and (not successor or reverse.get(successor) != field(row, 0)):
            errors.append("source row %d: missing/mismatched KEEP lineage" % i)
        if disposition != "KEEP" and successor:
            errors.append("source row %d: non-KEEP direct successor" % i)
    counts = collections.Counter(field(row, 2) for row in v2)
    targets = {}
    for i, row in enumerate(families, 2):
        name, target = field(row, 0), field(row, 2)
        if not name:
            continue
        try:
            number = int(target)
        except ValueError:
            errors.append("family row %d: noninteger target" % i)
            continue
        if name in targets:
            errors.append("family row %d: duplicate family" % i)
        targets[name] = number
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
    with open(args.workbook, "rb") as stream:
        digest = hashlib.file_digest(stream, "sha256").hexdigest()
    try:
        errors, summary = check(args.workbook)
    except (OSError, ValueError, KeyError, IndexError, ET.ParseError, zipfile.BadZipFile) as exc:
        errors, summary = ["unreadable or invalid workbook: " + type(exc).__name__], {}
    print(json.dumps({"status": "REVIEW_REQUIRED" if errors else "STRUCTURAL_PASS_ONLY",
                      "sha256": digest, "summary": summary, "errors": errors}, indent=2, sort_keys=True))
    return 1 if errors else 0

if __name__ == "__main__":
    sys.exit(main())
