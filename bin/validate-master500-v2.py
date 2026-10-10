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

class WorksheetRows(list):
    """Retain physical Excel row coordinates separately from canonical values."""
    def __init__(self):
        super().__init__()
        self.row_numbers = []


class ValidationErrors(list):
    """Keep legacy error strings alongside location-aware review exceptions."""
    def __init__(self):
        super().__init__()
        self.exceptions = []

    def append(self, reason):
        self.at(reason)

    def at(self, reason, sheet=None, row=None, column=None, identifier=None):
        super().append(reason)
        self.exceptions.append({"sheet": sheet, "row": row, "column": column,
                                "identifier": identifier, "reason": reason, "severity": "ERROR"})


def canonical_rows_sha256(rows):
    payload = {name: rows[name] for name in sorted(rows)}
    return hashlib.sha256(json.dumps(payload, ensure_ascii=False, sort_keys=True,
                                    separators=(",", ":"), allow_nan=False).encode("utf-8")).hexdigest()

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
        seen_sheet_names = set()
        seen_sheet_targets = set()
        for sheet in workbook.findall(".//m:sheets/m:sheet", NS):
            name = sheet.attrib["name"]
            if name in seen_sheet_names:
                raise ValueError("workbook contains duplicate worksheet names")
            seen_sheet_names.add(name)
            if name not in {"Source_Migration_500", "Master_500_v2", "Family_Rebalance"}:
                continue
            relationship_id = sheet.attrib["{" + NS["r"] + "}id"]
            relation = next((entry for entry in relations if entry.attrib.get("Id") == relationship_id), None)
            if relation is None:
                raise ValueError("worksheet relationship missing")
            if relation.attrib.get("TargetMode", "Internal") != "Internal":
                raise ValueError("external worksheet relationship is forbidden")
            raw_target = targets[relationship_id]
            if ".." in raw_target.replace("\\", "/").split("/"):
                raise ValueError("worksheet relationship contains parent traversal")
            target = raw_target.lstrip("/")
            if not target.startswith("xl/"):
                target = "xl/" + target
            normalized = posixpath.normpath(target)
            if not normalized.startswith("xl/worksheets/") or normalized not in names:
                raise ValueError("worksheet target escapes expected archive directory")
            if normalized in seen_sheet_targets:
                raise ValueError("multiple required worksheets share an archive target")
            seen_sheet_targets.add(normalized)
            root = ET.fromstring(archive.read(normalized))
            rows = WorksheetRows()
            seen_row_numbers = set()
            previous_row_number = 0
            for row in root.findall(".//m:sheetData/m:row", NS):
                row_number = row.attrib.get("r", "")
                if not re.fullmatch(r"[1-9][0-9]*", row_number):
                    raise ValueError("worksheet row number malformed")
                if len(row_number) > 7:
                    raise ValueError("worksheet row exceeds Excel coordinate bounds")
                canonical_row_number = int(row_number)
                if canonical_row_number > 1048576:
                    raise ValueError("worksheet row exceeds Excel coordinate bounds")
                if canonical_row_number in seen_row_numbers:
                    raise ValueError("worksheet contains duplicate row numbers")
                if canonical_row_number <= previous_row_number:
                    raise ValueError("worksheet row numbers must be strictly increasing")
                previous_row_number = canonical_row_number
                seen_row_numbers.add(canonical_row_number)
                values = {}
                previous_column_index = 0
                for cell in row.findall("m:c", NS):
                    ref = cell.attrib.get("r", "")
                    match = re.fullmatch(r"([A-Z]+)([1-9][0-9]*)", ref)
                    if not match or len(match.group(2)) > 7 or int(match.group(2)) != canonical_row_number:
                        raise ValueError("worksheet cell reference malformed or row mismatched")
                    index = 0
                    for char in match.group(1):
                        index = index * 26 + ord(char) - 64
                        if index > 16384:
                            raise ValueError("worksheet cell exceeds Excel column bounds")
                    if index == previous_column_index:
                        raise ValueError("worksheet row contains duplicate cell column")
                    if index < previous_column_index:
                        raise ValueError("worksheet cell columns must be strictly increasing")
                    previous_column_index = index
                    if cell.find("m:f", NS) is not None:
                        raise ValueError("formula cells are forbidden in governed candidate evidence")
                    raw = cell.find("m:v", NS)
                    if cell.attrib.get("t") == "inlineStr":
                        value = "".join(t.text or "" for t in cell.findall(".//m:t", NS))
                    elif raw is None:
                        value = ""
                    elif cell.attrib.get("t") == "s":
                        index_text = raw.text or ""
                        if not re.fullmatch(r"0|[1-9][0-9]*", index_text) or len(index_text) > 7:
                            raise ValueError("shared string index is malformed")
                        string_index = int(index_text)
                        if string_index >= len(strings):
                            raise ValueError("shared string index is out of range")
                        value = strings[string_index]
                    else:
                        value = raw.text or ""
                    if index - 1 in values:
                        raise ValueError("worksheet row contains duplicate cell column")
                    values[index - 1] = str(value).strip()
                rows.append([values.get(i, "") for i in range(max(values, default=-1) + 1)])
                rows.row_numbers.append(canonical_row_number)
            output[name] = rows
        return output

def check(path):
    errors = ValidationErrors()
    rows = load_rows(path)
    digest = canonical_rows_sha256(rows)
    required = {"Source_Migration_500": ["Source DG ID", "Wave", "V1 Family", "V1 Concept", "Engine", "Disposition", "Migration Note", "V2 Successor ID"],
                "Master_500_v2": ["V2 ID", "Source DG ID", "Family", "Concept", "Engine", "Physical Product", "Supplier Gate", "Template State", "Origin", "Recommended Stage"],
                "Family_Rebalance": ["Family", "V1 Count", "V2 Target"]}
    for sheet, headers in required.items():
        data = rows.get(sheet, [])
        if not data or data[0][:len(headers)] != headers:
            errors.append(sheet + ": missing sheet or unexpected headers")
    if errors:
        return errors, {"canonical_rows_sha256": digest, "exceptions": errors.exceptions}
    source = rows["Source_Migration_500"][1:]
    v2 = rows["Master_500_v2"][1:]
    families = rows["Family_Rebalance"][1:]
    if len(source) != 500 or len(v2) != 500:
        errors.append("expected exactly 500 source and 500 v2 rows")
    def physical_row(sheet, position):
        coordinates = getattr(rows[sheet], "row_numbers", [])
        return coordinates[position - 1] if coordinates else position

    def field(row, index):
        return row[index] if index < len(row) else ""
    def unique(records, index, label, sheet, column, require_all=False):
        values = [field(row, index) for row in records]
        present = [v for v in values if v]
        duplicates = {v for v, count in collections.Counter(present).items() if count > 1}
        for position, record in enumerate(records, 2):
            value = field(record, index)
            if require_all and not value:
                errors.at(label + ": blank identifier", sheet, physical_row(sheet, position), column, field(record, 0))
            if value in duplicates:
                errors.at(label + ": duplicate identifier", sheet, physical_row(sheet, position), column, field(record, 0))
        return set(present)
    source_ids = unique(source, 0, "source IDs", "Source_Migration_500", "Source DG ID", True)
    v2_ids = unique(v2, 0, "v2 IDs", "Master_500_v2", "V2 ID", True)
    successors = unique(source, 7, "successor IDs", "Source_Migration_500", "V2 Successor ID")
    v2_sources = unique(v2, 1, "v2 source references", "Master_500_v2", "Source DG ID")
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
    for position, row in enumerate(v2, 2):
        i = physical_row("Master_500_v2", position)
        origin, source_ref = field(row, 8), field(row, 1)
        if (origin == "V1 retained") != bool(source_ref):
            errors.at("v2 row %d: origin/source reference mismatch" % i, "Master_500_v2", i, "Source DG ID", field(row, 0))
        for index in (2, 3, 4, 5, 6, 7, 9):
            if not field(row, index):
                group = "family/concept/engine" if index in (2, 3, 4) else "supplier/template/stage"
                errors.at("v2 row %d: required %s field blank" % (i, group), "Master_500_v2", i,
                          required["Master_500_v2"][index], field(row, 0))
        if field(row, 1) and field(row, 1) not in source_ids:
            errors.at("v2 row %d: source reference missing" % i, "Master_500_v2", i, "Source DG ID", field(row, 0))
        if field(row, 6) not in {"PRINTIFY_PRIMARY", "ROUTE_BY_BASE_PRODUCT", "PROVIDER_RESEARCH_REQUIRED"}:
            errors.at("v2 row %d: unknown supplier gate" % i, "Master_500_v2", i, "Supplier Gate", field(row, 0))
        if field(row, 9) not in {"Re-score", "Provider + demand research", "Deep research"}:
            errors.at("v2 row %d: unknown recommended stage" % i, "Master_500_v2", i, "Recommended Stage", field(row, 0))
    for position, row in enumerate(source, 2):
        i = physical_row("Source_Migration_500", position)
        disposition, successor = field(row, 5), field(row, 7)
        for index in (1, 2, 3, 4):
            if not field(row, index):
                errors.at("source row %d: required wave/family/concept/engine field blank" % i,
                          "Source_Migration_500", i, required["Source_Migration_500"][index], field(row, 0))
        if disposition not in {"KEEP", "MERGE", "DOWNGRADE"}:
            errors.at("source row %d: invalid disposition" % i, "Source_Migration_500", i, "Disposition", field(row, 0))
        if disposition == "KEEP" and (not successor or reverse.get(successor) != field(row, 0)):
            errors.at("source row %d: missing/mismatched KEEP lineage" % i, "Source_Migration_500", i, "V2 Successor ID", field(row, 0))
        if disposition != "KEEP" and successor:
            errors.at("source row %d: non-KEEP direct successor" % i, "Source_Migration_500", i, "V2 Successor ID", field(row, 0))
    if collections.Counter(field(row, 5) for row in source) != {"KEEP": 428, "MERGE": 37, "DOWNGRADE": 35}:
        errors.append("unexpected source disposition distribution")
    counts = collections.Counter(field(row, 2) for row in v2)
    targets = {}
    for position, row in enumerate(families, 2):
        i = physical_row("Family_Rebalance", position)
        name, target = field(row, 0), field(row, 2)
        if not name:
            errors.at("family row %d: blank family" % i, "Family_Rebalance", i, "Family", name)
            continue
        try:
            number = int(target)
        except ValueError:
            errors.at("family row %d: noninteger target" % i, "Family_Rebalance", i, "V2 Target", name)
            continue
        if name in targets:
            errors.at("family row %d: duplicate family" % i, "Family_Rebalance", i, "Family", name)
        targets[name] = number
    if len(targets) != 24:
        errors.append("expected exactly 24 family targets")
    if any(value <= 0 for value in targets.values()):
        errors.append("family targets must be positive")
    if sum(targets.values()) != 500:
        errors.append("family targets must sum to 500")
    if counts != collections.Counter(targets):
        errors.append("family counts differ from targets")
    summary = {"canonical_rows_sha256": digest, "exceptions": errors.exceptions, "source_rows": len(source), "v2_rows": len(v2),
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
                      "sha256": digest,
                      "canonical_rows_sha256": summary.get("canonical_rows_sha256"),
                      "exceptions": summary.get("exceptions", [{"sheet": None, "row": None, "column": None, "identifier": None, "reason": error, "severity": "ERROR"} for error in errors]),
                      "production_authority": False, "promotion_authorized": False,
                      "summary": summary, "errors": errors}, indent=2, sort_keys=True))
    return 1 if errors else 0

if __name__ == "__main__":
    sys.exit(main())
