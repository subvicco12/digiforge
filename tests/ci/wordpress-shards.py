"""Plan general WordPress shards and compare executed JUnit coverage."""
import collections
import json
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
COUNT = 6


def plan():
    original = ET.parse(ROOT / "phpunit.wordpress.xml.dist")
    suite, = original.findall("./testsuites/testsuite")
    directory, = suite.findall("directory")
    assert all(child.tag in {"directory", "exclude"} for child in suite)
    excluded = {ROOT / node.text for node in suite.findall("exclude")}
    files = sorted(set((ROOT / directory.text).rglob("*" + directory.attrib["suffix"])) - excluded)
    assert files
    timings = json.loads(Path(__file__).with_name("wordpress-baseline-ms.json").read_text())
    groups = [[] for _ in range(COUNT)]
    totals = [0] * COUNT

    def cost(path):
        classes = re.findall(r"(?m)^\s*(?:(?:final|abstract)\s+)?class\s+(\w+)", path.read_text())
        return sum(timings.get(cls, 1000) for cls in classes) or 1000

    for path in sorted(files, key=lambda item: (-cost(item), item)):
        index = min(range(COUNT), key=totals.__getitem__)
        groups[index].append(path.relative_to(ROOT).as_posix())
        totals[index] += cost(path)
    assert all(groups)
    return groups, totals


def config(index, output):
    groups, _ = plan()
    original = ET.parse(ROOT / "phpunit.wordpress.xml.dist")
    suite, = original.findall("./testsuites/testsuite")
    if index == "serial":
        for node in suite:
            node.text = str(ROOT / node.text)
    else:
        for child in list(suite):
            suite.remove(child)
        for path in sorted(groups[int(index)]):
            ET.SubElement(suite, "file").text = str(ROOT / path)
    extensions = ET.SubElement(original.getroot(), "extensions")
    ET.SubElement(extensions, "extension", {
        "class": "WordPressAuditMetrics",
        "file": str(ROOT / "tests/ci/WordPressAuditMetrics.php"),
    })
    original.getroot().attrib["bootstrap"] = str(ROOT / original.getroot().attrib["bootstrap"])
    original.write(output, encoding="utf-8", xml_declaration=True)


def inventory(paths):
    cases = {}
    timings = collections.defaultdict(float)
    for path in paths:
        doc = ET.parse(path)
        assert doc.getroot().tag in {"testsuite", "testsuites"}, f"invalid JUnit: {path}"
        items = list(doc.iter("testcase"))
        assert items, f"empty report: {path}"
        for case in items:
            cls = case.attrib.get("class") or case.attrib["classname"]
            key = (cls, case.attrib["name"])
            assert key not in cases, f"duplicate case: {key}"
            assert case.find("failure") is None and case.find("error") is None, f"failed case: {key}"
            skipped = case.find("skipped")
            cases[key] = (int(case.attrib["assertions"]), skipped is not None)
            timings[cls] += float(case.attrib["time"])
    return cases, timings


def compare(directory):
    directory = Path(directory)
    baseline, baseline_time = inventory([directory / "serial.xml"])
    shards, shard_time = inventory([directory / f"shard-{i}.xml" for i in range(COUNT)])
    if baseline != shards:
        missing = sorted(baseline.keys() - shards.keys())
        extra = sorted(shards.keys() - baseline.keys())
        changed = sorted(key for key in baseline.keys() & shards.keys() if baseline[key] != shards[key])
        raise SystemExit(f"JUnit parity failed: missing={missing}, extra={extra}, changed={changed}")
    profiles = ["serial", *(f"shard-{i}" for i in range(COUNT))]
    wall = {name: float((directory / f"{name}.wall").read_text()) for name in profiles}
    assert all(value > 0 for value in wall.values()), "missing wall timing"
    metrics = {
        name: json.loads((directory / f"{name}.metrics.json").read_text())
        for name in profiles
    }
    baseline_queries = metrics["serial"]["classes"]
    shard_queries = {}
    for name in profiles[1:]:
        current = metrics[name]["classes"]
        assert not set(current) & set(shard_queries), "class telemetry split across shards"
        shard_queries.update(current)
    assert set(baseline_queries) == set(shard_queries), "missing class telemetry"
    return {
        "tests": len(baseline),
        "serial_process_seconds": wall["serial"],
        "parallel_process_critical_path_seconds": max(wall[name] for name in profiles[1:]),
        "shard_process_seconds_total": sum(wall[name] for name in profiles[1:]),
        "peak_memory_bytes": {name: values["peak_memory_bytes"] for name, values in metrics.items()},
        "assertions": sum(result[0] for result in baseline.values()),
        "skips": sum(result[1] for result in baseline.values()),
        "slowest_classes": [
            {"class": cls, "serial_test_seconds": baseline_time[cls], "shard_test_seconds": elapsed,
             "comparison_budget_seconds": round(baseline_time[cls] * 1.2 + 1, 3),
             "comparison_budget_exceeded": elapsed > baseline_time[cls] * 1.2 + 1,
             "serial_queries": baseline_queries[cls]["queries"], "shard_queries": shard_queries[cls]["queries"]}
            for cls, elapsed in sorted(shard_time.items(), key=lambda item: -item[1])[:20]
        ],
    }


if __name__ == "__main__":
    action, *args = sys.argv[1:]
    if action == "config":
        config(args[0], args[1])
    elif action == "plan":
        groups, totals = plan()
        print(json.dumps({"files_per_shard": list(map(len, groups)), "estimated_test_ms": totals}))
    elif action == "compare":
        print(json.dumps(compare(args[0]), indent=2))
    else:
        raise SystemExit("Expected config, plan, or compare")
