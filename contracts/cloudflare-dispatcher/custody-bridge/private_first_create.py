"""Offline control-plane rehearsal. No HTTP transport, credentials or CLI."""
import base64
import hashlib
import io
import json
import re
import sqlite3
import time
import zipfile

WORKERS = ("digiforge-custody-qualification", "digiforge-stage-a-caller")
FLOW = "digiforge-stage-a-manual"
ARCHIVES = (
    "a7654fe8c3b2bfe9382dccf2fbbe9827c15fb50e77acc8f319f51089cc924b6a",
    "4d0773a1dd9552e6a9bbd21fa950b569a2b7684ad3f85d40085fdd646320f92a",
)
LISTS = (
    "/workers/workers?page=1&per_page=100",
    "/workers/durable_objects/namespaces?page=1&per_page=100",
    "/workflows?page=1&per_page=100",
)
PRIVATE = {"subdomain": {"enabled": False, "previews_enabled": False},
           "logpush": False, "observability": {"enabled": False}}
MAX_REQUESTS = 40
MAX_SECONDS = 300


class Denied(Exception):
    pass


def require(condition, reason):
    if not condition:
        raise Denied(reason)


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":")).encode()


def digest(value):
    return hashlib.sha256(value).hexdigest()


def hex_id(value, length=32):
    return isinstance(value, str) and re.fullmatch(f"[0-9a-f]{{{length}}}", value)


def frozen_archives(archives):
    require(tuple(map(digest, archives)) == ARCHIVES, "ARCHIVE_MISMATCH")
    packages = []
    for raw in archives:
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            config = json.loads(archive.read("wrangler.json"))
            modules = [
                {"name": name, "content_type": "application/javascript+module",
                 "content_base64": base64.b64encode(archive.read(name)).decode()}
                for name in sorted(archive.namelist()) if name.endswith(".mjs")
            ]
            packages.append((config, modules))
    return packages


def version_body(package, namespace=None):
    config, modules = package
    bindings = [
        {"type": "plain_text", "name": name, "text": text}
        for name, text in sorted(config["vars"].items())
    ]
    for binding in config["durable_objects"]["bindings"]:
        binding = {"type": "durable_object_namespace", **binding}
        if namespace is not None:
            binding["namespace_id"] = namespace
        bindings.append(binding)
    bindings.extend({"type": "service", "name": b["binding"], "service": b["service"]}
                    for b in config.get("services", []))
    body = {"main_module": config["main"], "compatibility_date": config["compatibility_date"],
            "bindings": bindings, "modules": modules, "cache_options": {"enabled": False}}
    if namespace is None:
        migration = config["migrations"][0]
        body["migrations"] = {"new_tag": migration["tag"],
                              "new_sqlite_classes": migration["new_sqlite_classes"]}
    else:
        bindings.extend({"type": "workflow", "name": b["binding"], "workflow_name": b["name"],
                         "script_name": config["name"], "class_name": b["class_name"]}
                         for b in config["workflows"])
    return body


def rehearse(archives, account, evidence, fixture, journal, now=time.monotonic):
    """Use a normalized mock response stream; journal one attempt per account."""
    require(hex_id(account) and fixture.account == account, "ACCOUNT_MISMATCH")
    require(evidence.get("headroom") is True and evidence.get("paid_upgrade_required") is False
            and evidence.get("atomic_private_supported") is True
            and type(evidence.get("extra_cost_usd")) is int
            and evidence == {"account": account, "workers_plan": "free", "extra_cost_usd": 0,
                         "headroom": True, "paid_upgrade_required": False,
                         "atomic_private_supported": True,
                         "scope": "synthetic_control_plane_fixture"},
            "ENTITLEMENT_OR_PRIVATE_CREATION_UNVERIFIED")
    packages = frozen_archives(archives)
    deadline = now() + MAX_SECONDS
    spent = 0
    worker_ids = {}
    namespaces = {}
    versions = {}
    journal.execute("PRAGMA synchronous=FULL")
    journal.execute("CREATE TABLE IF NOT EXISTS attempts (account TEXT PRIMARY KEY, state TEXT)")
    journal.execute("CREATE TABLE IF NOT EXISTS writes (account TEXT, ordinal INTEGER,"
                    " request_digest TEXT, PRIMARY KEY(account,ordinal))")
    try:
        with journal:
            journal.execute("INSERT INTO attempts VALUES (?, 'IN_PROGRESS_NO_RETRY')", (account,))
    except sqlite3.IntegrityError:
        raise Denied("ATTEMPT_ALREADY_CONSUMED") from None

    def request(method, path, body=None):
        nonlocal spent
        require(spent < MAX_REQUESTS and now() < deadline, "REQUEST_BUDGET")
        payload = encoded(body)
        require(len(payload) <= 256 * 1024, "REQUEST_SIZE")
        spent += 1
        if method != "GET":
            with journal:
                journal.execute("INSERT INTO writes VALUES (?,?,?)",
                                (account, spent, digest(encoded([method, path, body]))))
        result = fixture.request(method, f"/accounts/{account}{path}", json.loads(payload))
        require(now() < deadline and len(encoded(result)) <= 1024 * 1024, "RESPONSE_BUDGET")
        return result

    def observe_worker(worker, name, empty=False):
        require(worker["name"] == name and hex_id(worker["id"]), "WORKER_IDENTITY")
        require(all(worker.get(k) == v for k, v in PRIVATE.items()), "PUBLIC_OR_LOGGING_ENABLED")
        # Route inventory is separately normalized by the fixture. Absence is not proof.
        require(worker.get("public_routes") == [], "PUBLIC_ROUTES_UNVERIFIED")
        if empty:
            require("deployed_on" in worker and worker["deployed_on"] is None, "SHELL_NOT_EMPTY")
        return worker["id"]

    def read_worker(name, empty=False):
        observed = request("GET", "/workers/workers/" + worker_ids[name])
        require(observe_worker(observed, name, empty) == worker_ids[name], "WORKER_IDENTITY")

    def read_namespaces(count):
        rows = request("GET", LISTS[1])
        require(len(rows) == count, "NAMESPACE_COUNT")
        found = {}
        for row in rows:
            name = row["script"]
            require(name in WORKERS[:count] and name not in found, "NAMESPACE_SCRIPT")
            expected = packages[WORKERS.index(name)][0]["migrations"][0]["new_sqlite_classes"][0]
            require(row["class"] == expected and row["use_sqlite"] is True and hex_id(row["id"])
                    and row["id"] not in found.values(), "NAMESPACE_IDENTITY")
            found[name] = row["id"]
        require(all(found.get(k) == v for k, v in namespaces.items()), "NAMESPACE_REPLACED")
        namespaces.update(found)

    def upload(index, namespace=None):
        name = WORKERS[index]
        read_worker(name)
        body = version_body(packages[index], namespace)
        version = request("POST", "/workers/workers/" + worker_ids[name] + "/versions", body)
        version_id = version["id"]
        require(isinstance(version_id, str) and re.fullmatch(
            r"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}", version_id)
            and version_id not in versions.values() and version.get("urls") == []
            and version.get("uploaded_body_sha256") == digest(encoded(body)), "VERSION_EVIDENCE")
        versions[name] = version_id
        read_worker(name)
        deployment = {"strategy": "percentage",
                      "versions": [{"version_id": version_id, "percentage": 100}]}
        observed = request("POST", f"/workers/scripts/{name}/deployments", deployment)
        require(all(observed.get(k) == v for k, v in deployment.items()), "DEPLOYMENT_EVIDENCE")
        read_worker(name)
        return body["bindings"]

    try:
        for path in LISTS:
            require(request("GET", path) == [], "BASELINE_NOT_EMPTY")
        for name in WORKERS:
            shell = request("POST", "/workers/workers", {"name": name, **PRIVATE})
            identity = observe_worker(shell, name, empty=True)
            require(identity not in worker_ids.values(), "WORKER_IDENTITY")
            worker_ids[name] = identity
        for name in WORKERS:
            read_worker(name, empty=True)
        bridge = upload(0)
        read_namespaces(1)
        upload(1)
        read_namespaces(2)
        flow_path = "/workflows/" + FLOW
        flow = {"script_name": WORKERS[1], "class_name": "DigiForgeStageAManual", "schedules": []}
        request("PUT", flow_path, flow)
        require(request("GET", flow_path) == dict(flow, name=FLOW), "WORKFLOW_IDENTITY")
        require(request("GET", flow_path + "/instances") == [], "WORKFLOW_INSTANCES")
        caller = upload(1, namespaces[WORKERS[1]])
        for name, bindings in zip(WORKERS, (bridge, caller)):
            settings = request("GET", f"/workers/scripts/{name}/settings")
            require(settings.get("bindings") == bindings and settings.get("version_id") == versions[name],
                    "BINDING_OR_DEPLOYMENT_MISMATCH")
        read_namespaces(2)
        workers = request("GET", LISTS[0])
        require(len(workers) == 2 and {w["name"] for w in workers} == set(WORKERS),
                "WORKER_COUNT")
        for worker in workers:
            name = worker["name"]
            require(observe_worker(worker, name) == worker_ids[name], "WORKER_IDENTITY")
        require(request("GET", LISTS[2]) == [dict(flow, name=FLOW)], "WORKFLOW_IDENTITY")
        require(request("GET", flow_path + "/instances") == [], "WORKFLOW_INSTANCES")
    except Exception:
        with journal:
            journal.execute("UPDATE attempts SET state='UNKNOWN_NO_RETRY' WHERE account=?", (account,))
        raise Denied("STOP_RECONCILE_READ_ONLY") from None

    with journal:
        journal.execute("UPDATE attempts SET state='OFFLINE_DISABLED_OBSERVED' WHERE account=?", (account,))
    return {"workers": worker_ids, "namespaces": namespaces, "workflow": FLOW,
            "versions": versions, "requests": spent, "workflow_instances": 0}
