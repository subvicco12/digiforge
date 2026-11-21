"""Focused rehearsal against the accepted archive bytes and a local API fixture."""
import copy
from contextlib import closing
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest

import private_first_create as private

ACCOUNT = "1" * 32
EVIDENCE = {"account": ACCOUNT, "workers_plan": "free", "extra_cost_usd": 0,
            "headroom": True, "paid_upgrade_required": False, "atomic_private_supported": True,
            "scope": "synthetic_control_plane_fixture"}


class CloudflareFixture:
    account = ACCOUNT

    def __init__(self):
        self.calls = []
        self.workers = {}
        self.versions = {}
        self.settings = {}
        self.namespaces = []
        self.flows = []
        self.fault = lambda method, path, result: result

    def request(self, method, path, body):
        prefix = f"/accounts/{ACCOUNT}"
        if not path.startswith(prefix + "/"):
            raise AssertionError(path)
        path = path.removeprefix(prefix)
        self.calls.append((method, path, body))
        result = self.apply(method, path, body)
        return self.fault(method, path, copy.deepcopy(result))

    def apply(self, method, path, body):
        if (method, path) == ("POST", "/workers/workers"):
            worker = {"id": str(len(self.workers) + 2) * 32, "deployed_on": None,
                      "public_routes": [], **body}
            self.workers[worker["id"]] = worker
            return worker
        if path == private.LISTS[0]:
            return list(self.workers.values())
        if path == private.LISTS[1]:
            return self.namespaces
        if path == private.LISTS[2]:
            return self.flows
        if path == "/workflows/" + private.FLOW and method == "PUT":
            self.flows.append(dict(body, name=private.FLOW))
            return self.flows[0]
        if path == "/workflows/" + private.FLOW and method == "GET":
            return self.flows[0]
        if path == "/workflows/" + private.FLOW + "/instances" and method == "GET":
            return []
        parts = path.split("/")
        if parts[2] == "workers" and method == "GET":
            return self.workers[parts[3]]
        if parts[-1] == "versions" and method == "POST":
            worker = self.workers[parts[3]]
            if not all(w["subdomain"] == private.PRIVATE["subdomain"] for w in self.workers.values()):
                raise AssertionError("public shell")
            identity = f"00000000-0000-0000-0000-{len(self.versions) + 1:012d}"
            self.versions[identity] = (worker, body)
            return {"id": identity, "urls": [], "uploaded_body_sha256": private.digest(private.encoded(body))}
        if parts[-1] == "deployments" and method == "POST":
            version = body["versions"][0]["version_id"]
            worker, uploaded = self.versions[version]
            if worker["name"] != parts[3]:
                raise AssertionError("worker substitution")
            if "migrations" in uploaded:
                self.namespaces.append({"id": str(len(self.namespaces) + 4) * 32,
                                        "script": worker["name"], "use_sqlite": True,
                                        "class": uploaded["migrations"]["new_sqlite_classes"][0]})
            worker["deployed_on"] = version
            self.settings[worker["name"]] = {"version_id": version, "bindings": uploaded["bindings"]}
            return body
        if parts[-1] == "settings" and method == "GET":
            return self.settings[parts[3]]
        raise AssertionError((method, path))


class FirstCreateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        directory = Path(os.environ["DF_FIRST_CREATE_ARCHIVES"])
        cls.archives = tuple((directory / name).read_bytes() for name in
                             ("custody-bridge-first-create-disabled.zip",
                              "stage-a-caller-first-create-disabled.zip"))

    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.path = Path(directory.name) / "attempts.sqlite"
        self.fixture = CloudflareFixture()

    def run_plan(self, archives=None, evidence=None, now=None):
        with closing(sqlite3.connect(self.path)) as journal:
            return private.rehearse(self.archives if archives is None else archives, ACCOUNT,
                                    EVIDENCE if evidence is None else evidence, self.fixture,
                                    journal, **({} if now is None else {"now": now}))

    def test_disabled_sequence(self):
        receipt = self.run_plan()
        writes = [(method, path) for method, path, _ in self.fixture.calls if method != "GET"]
        self.assertEqual(len(writes), 9)
        self.assertEqual(writes[:2], [("POST", "/workers/workers")] * 2)
        first_upload = next(i for i, (_, p, _) in enumerate(self.fixture.calls) if p.endswith("/versions"))
        self.assertEqual(len({p for m, p, _ in self.fixture.calls[5:first_upload] if m == "GET"}), 2)
        self.assertEqual(receipt["workflow_instances"], 0)
        self.assertEqual(receipt["requests"], len(self.fixture.calls))
        self.assertEqual(len(receipt["namespaces"]), 2)
        caller = self.fixture.settings[private.WORKERS[1]]["bindings"]
        self.assertIn({"type": "service", "name": "DF_CUSTODY_BRIDGE", "service": private.WORKERS[0]}, caller)
        self.assertEqual([b["text"] for b in caller if b["type"] == "plain_text"], ["", "DISABLED"])
        self.assertFalse(any("instances" in p and m != "GET" for m, p in writes))

    def test_unknown_or_paid_cost(self):
        for evidence in ({}, {**EVIDENCE, "headroom": False}, {**EVIDENCE, "headroom": 1},
                         {**EVIDENCE, "paid_upgrade_required": True},
                         {**EVIDENCE, "atomic_private_supported": False}):
            with self.subTest(evidence=evidence), self.assertRaisesRegex(private.Denied, "UNVERIFIED"):
                self.run_plan(evidence=evidence)
        self.assertEqual(self.fixture.calls, [])

    def test_archive_mismatch(self):
        with self.assertRaisesRegex(private.Denied, "ARCHIVE_MISMATCH"):
            self.run_plan(archives=(b"substituted", self.archives[1]))
        self.assertEqual(self.fixture.calls, [])

    def test_public_route_readback_prevents_upload(self):
        def fault(method, path, result):
            if method == "GET" and path == "/workers/workers/" + "3" * 32:
                result["public_routes"] = ["unexpected"]
            return result
        self.fixture.fault = fault
        with self.assertRaisesRegex(private.Denied, "RECONCILE"):
            self.run_plan()
        self.assertFalse(any(path.endswith("/versions") for _, path, _ in self.fixture.calls))

    def test_lost_creation_acknowledgement_never_retries_after_restart(self):
        def fault(method, path, result):
            if method == "POST":
                raise TimeoutError()
            return result
        self.fixture.fault = fault
        with self.assertRaisesRegex(private.Denied, "RECONCILE"):
            self.run_plan()
        self.assertEqual(len(self.fixture.workers), 1)
        prior = self.fixture.calls[:]
        with self.assertRaisesRegex(private.Denied, "CONSUMED"):
            self.run_plan()
        self.assertEqual(self.fixture.calls, prior)
        with closing(sqlite3.connect(self.path)) as journal:
            self.assertEqual(journal.execute("SELECT state FROM attempts").fetchone(), ("UNKNOWN_NO_RETRY",))
            self.assertRegex(journal.execute("SELECT request_digest FROM writes").fetchone()[0], "[0-9a-f]{64}")

    def test_version_evidence_substitution_stops_before_deployment(self):
        def fault(method, path, result):
            if path.endswith("/versions"):
                result["uploaded_body_sha256"] = "0" * 64
            return result
        self.fixture.fault = fault
        with self.assertRaisesRegex(private.Denied, "RECONCILE"):
            self.run_plan()
        self.assertFalse(self.fixture.settings)

    def test_deadline_after_write_stops(self):
        times = iter([0, 0, 0, 0, 0, 0, 0, 0, 300])
        with self.assertRaisesRegex(private.Denied, "RECONCILE"):
            self.run_plan(now=lambda: next(times))
        self.assertEqual(len(self.fixture.workers), 1)

    def test_instances_must_be_empty(self):
        self.fixture.fault = lambda m, p, r: ["unexpected"] if p.endswith("/instances") else r
        with self.assertRaisesRegex(private.Denied, "RECONCILE"):
            self.run_plan()
