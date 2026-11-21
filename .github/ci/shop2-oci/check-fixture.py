"""Validate fixture observations; emit only allowlisted, bounded fields."""
import json
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

work, docker_file, mode, destination = sys.argv[1:]
work = Path(work)
def need(ok, reason):
    if not ok:
        raise SystemExit(reason)
def read(path, cap):
    need(path.is_file() and not path.is_symlink() and path.stat().st_size <= cap, "Missing or oversized evidence")
    return path.read_bytes()
report = json.loads(read(work / "safety.json", 8192))
records = json.loads(read(Path(docker_file), 262144))
need(len(records) == 1, "Unexpected inspect result")
record = records[0]
host = record["HostConfig"]
state = record["State"]
exit_code = {"pass": 0, "assertion-failure": 1, "partial": 72}[mode]
need(host["NetworkMode"] == "none" and host["ReadonlyRootfs"] is True
     and host["Privileged"] is False and not host["PortBindings"] and not host.get("PublishAllPorts")
     and "ALL" in host["CapDrop"] and "no-new-privileges" in host["SecurityOpt"]
     and record["Config"]["User"] == "10001:10001" and state["Running"] is False
     and state["Pid"] == 0 and state["OOMKilled"] is False and state["ExitCode"] == exit_code,
     "Container isolation or exit mismatch")
need(report["schema"] == 1 and report["mode"] == mode and report["exit"] == exit_code
     and report["uid"] == 10001 and report["only_loopback"] is True
     and report["reaped_and_cleaned"] is True, "Fixture isolation or cleanup incomplete")
need(all(not (work / name).exists() and not (work / name).is_symlink()
         for name in ("db", "db.sock", "db.pid", "db.log", "init.log")), "Database state retained")
assertions = 0
if mode == "partial":
    need(report["phpunit_exit"] is None and report["db_ready"] is False
         and not (work / "junit.xml").exists() and not (work / "junit.xml").is_symlink(),
         "Partial startup unexpectedly reached PHPUnit")
else:
    need(report["db_ready"] is True and report["no_tcp_listener"] is True
         and report["phpunit_exit"] == exit_code, "Database or PHPUnit evidence mismatch")
    raw = read(work / "junit.xml", 262144)
    need(b"<!" not in raw, "Unexpected XML declaration")
    cases = list(ET.fromstring(raw).iter("testcase"))
    need(len(cases) == 1 and cases[0].get("class") == "FixtureLifecycleTest"
         and cases[0].get("name") == "testFixtureLifecycle", "Wrong fixture test")
    assertions = int(cases[0].get("assertions", "0"))
    tags = {el.tag for el in cases[0]}
    need(4 <= assertions <= 100 and not tags & {"error", "skipped"}
         and (("failure" in tags) == (mode == "assertion-failure")), "Wrong fixture outcome")
extensions = report["extensions"]
need(type(extensions) is list and len(extensions) <= 100 and
     all(isinstance(v, str) and re.fullmatch(r"[A-Za-z0-9 ._+~:-]{1,120}", v)
         for v in [report["php"], *extensions]), "Unbounded PHP identity")
need(mode == "partial" or re.fullmatch(r"[0-9][A-Za-z0-9._+~:-]{1,120}", report["mariadb"]), "Unbounded MariaDB identity")
result = {"checked": True, "exit": exit_code, "assertions": assertions,
          "no_tcp_listener": mode != "partial", "reaped_and_cleaned": True,
          "non_root_uid": 10001, "only_loopback": True, "read_only_root": True,
          "php": report["php"], "extensions": sorted(extensions),
          "mariadb": report["mariadb"] if mode != "partial" else None}
Path(destination).write_text(json.dumps(result, indent=2, sort_keys=True) + "\n")
