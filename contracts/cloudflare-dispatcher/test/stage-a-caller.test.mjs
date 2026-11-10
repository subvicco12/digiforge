import test from "node:test";
import assert from "node:assert/strict";
import { existsSync } from "node:fs";
test("private caller persists intent and checks the exact frozen target build", async () => {
  assert.ok(
    existsSync(new URL("../stage-a-caller/caller.mjs", import.meta.url)),
    "durable private caller required",
  );
  const { callerFixture } = await import("./stage-a-caller-support.mjs");
  const f = await callerFixture();
  const r = await f.caller.execute(f.request());
  assert.equal(r.status, "COMPLETED");
  assert.equal(r.receipt.case_id, "atomic_commit");
  assert.equal(f.calls.qualify, 1);
  assert.equal(f.journal.read().operations[f.id].state, "COMPLETED");
});
async function fresh(options) {
  return (await import("./stage-a-caller-support.mjs")).callerFixture(options);
}
for (const key of [
  "url",
  "command",
  "sql",
  "fault",
  "reset",
  "Authorization",
  "credential",
])
  test("operator request rejects " + key, async () => {
    const f = await fresh();
    const r = await f.caller.execute({ ...f.request(), [key]: "forbidden" });
    assert.equal(r.status, "DENIED");
    assert.deepEqual(f.calls, { status: 0, qualify: 0 });
  });
test("caller rejects unknown action before any target invocation", async () => {
  const f = await fresh();
  assert.equal((await f.caller.execute(f.request("RESET"))).status, "DENIED");
  assert.equal(f.calls.status, 0);
});
test("caller rejects wrong approved build before any target invocation", async () => {
  const f = await fresh();
  assert.equal(
    (await f.caller.execute({ ...f.request(), build: "f".repeat(64) })).status,
    "DENIED",
  );
  assert.equal(f.calls.status, 0);
});
test("caller rejects wrong approval and expired operator initiation", async () => {
  const f = await fresh();
  assert.equal(
    (await f.caller.execute({ ...f.request(), approval: "e".repeat(64) }))
      .status,
    "DENIED",
  );
  assert.equal(
    (await f.caller.execute({ ...f.request(), expires_at: 999 })).status,
    "DENIED",
  );
  assert.equal(f.calls.status, 0);
});
test("operator instance identity must match authenticated initiation request", async () => {
  const f = await fresh();
  assert.equal(
    (await f.caller.execute(f.request(), "other-instance")).status,
    "DENIED",
  );
  assert.equal(f.calls.status, 0);
});
test("wrong target preflight build never reaches qualification", async () => {
  const f = await fresh();
  f.service.status = () => ({
    ...f.target.runner.status(),
    build: "f".repeat(64),
  });
  assert.equal((await f.caller.execute(f.request())).status, "UNKNOWN");
  assert.equal(f.calls.qualify, 0);
});
test("wrong receipt build remains UNKNOWN and blocks further advance", async () => {
  const f = await fresh(),
    qualify = f.service.qualify;
  f.service.qualify = async (x) => ({
    ...(await qualify(x)),
    build: "e".repeat(64),
  });
  assert.equal((await f.caller.execute(f.request())).status, "UNKNOWN");
  f.clock.now += 61;
  assert.equal(
    (await f.caller.execute(f.request("ADVANCE_ONE", "2".repeat(32)))).status,
    "DENIED",
  );
  assert.equal(f.calls.qualify, 1);
});
test("duplicate operator initiation never creates a fresh intent", async () => {
  const f = await fresh(),
    r = f.request();
  assert.equal((await f.caller.execute(r)).status, "COMPLETED");
  const intent = f.journal.read().operations[f.id].intent;
  assert.equal((await f.caller.execute(r)).status, "COMPLETED");
  assert.equal(f.journal.read().operations[f.id].intent, intent);
  assert.equal(f.calls.qualify, 1);
});
test("concurrent duplicate initiation cannot poison a valid completion or dispatch twice", async () => {
  const f = await fresh(),
    r = f.request();
  let release;
  const barrier = new Promise((r) => (release = r));
  const admit = f.journal.admit.bind(f.journal);
  let admissions = 0,
    statusCalls = 0;
  f.journal.admit = (...args) => {
    const result = admit(...args);
    if (++admissions === 2) release();
    return result;
  };
  const status = f.service.status;
  f.service.status = async () => {
    const result = status();
    if (++statusCalls === 1) await barrier;
    return result;
  };
  const rs = await Promise.all([f.caller.execute(r), f.caller.execute(r)]);
  assert.equal(f.calls.qualify, 1);
  assert.ok(rs.some((x) => x.status === "COMPLETED"));
  assert.equal(f.journal.read().operations[f.id].state, "COMPLETED");
});
test("unknown target outcome cannot be inferred from case-only status evidence", async () => {
  const f = await fresh(),
    qualify = f.service.qualify;
  f.service.qualify = async (x) => {
    await qualify(x);
    throw Error("lost-private-response");
  };
  assert.equal((await f.caller.execute(f.request())).status, "UNKNOWN");
  const intent = f.journal.read().operations[f.id].intent;
  f.clock.now += 61;
  const r = await f.caller.execute(f.request("RECONCILE"));
  assert.equal(r.status, "UNKNOWN");
  assert.equal(r.receipt, null);
  assert.equal(f.journal.read().operations[f.id].intent, intent);
  assert.equal(f.calls.qualify, 1);
  assert.equal(f.calls.status, 1);
});
test("caller receipt readback recovers a committed outcome without target redispatch", async () => {
  const f = await fresh();
  await f.caller.execute(f.request());
  f.clock.now += 61;
  const r = await f.caller.execute(f.request("READ_RECEIPT"));
  assert.equal(r.status, "COMPLETED");
  assert.equal(r.receipt.case_id, "atomic_commit");
  assert.deepEqual(f.calls, { status: 1, qualify: 1 });
  assert.ok(
    !JSON.stringify(r).includes(f.journal.read().operations[f.id].intent),
  );
});

test("lost caller intent commit acknowledgement is UNKNOWN with original intent retained", async () => {
  const f = await fresh();
  f.storage.loseAck = true;
  const r = await f.caller.execute(f.request());
  assert.equal(r.status, "UNKNOWN");
  assert.ok(f.journal.read().operations[f.id].intent);
  assert.equal(f.calls.qualify, 0);
});
test("lost caller receipt commit acknowledgement can be reconciled only from committed receipt", async () => {
  const f = await fresh(),
    qualify = f.service.qualify;
  f.service.qualify = async (x) => {
    const r = await qualify(x);
    f.storage.loseAck = true;
    return r;
  };
  assert.equal((await f.caller.execute(f.request())).status, "UNKNOWN");
  assert.equal(f.journal.read().operations[f.id].state, "COMPLETED");
  f.clock.now += 61;
  assert.equal(
    (await f.caller.execute(f.request("RECONCILE"))).status,
    "COMPLETED",
  );
  assert.equal(f.calls.qualify, 1);
});

test("caller paces paired target status and qualification admissions without consuming an unknown operation", async () => {
  const f = await fresh();
  await f.caller.execute(f.request());
  const r = await f.caller.execute(f.request("ADVANCE_ONE", "2".repeat(32)));
  assert.equal(r.status, "DENIED");
  assert.equal(f.journal.read().operations["2".repeat(32)], undefined);
  assert.equal(f.calls.qualify, 1);
});

test("control-plane operator plan uses official manual REST shape without credentials", async () => {
  const f = await fresh(),
    { operatorPlan } = await import("../stage-a-caller/operator-plan.mjs");
  const { build, ...request } = f.request();
  const plan = operatorPlan({ account_id: "a".repeat(32), ...request });
  assert.equal(plan.method, "POST");
  assert.equal(plan.body.instance_id, "stage-a-" + request.request_id);
  assert.equal(typeof plan.body.params, "string");
  assert.equal(JSON.parse(plan.body.params).build, build);
  assert.equal(plan.automatic_retry, false);
  assert.equal(plan.body.id, undefined);
  assert.equal(plan.headers, undefined);
  assert.match(plan.required_permission, /Workers Scripts Write/);
});
test("caller namespace and mode reject secrets and unknown execution configurations", async () => {
  const { callerConfiguration } = await import(
    "../stage-a-caller/contract.mjs"
  );
  assert.throws(() => callerConfiguration({ token: "forbidden" }), /SCHEMA/);
});
test("caller Worker and Workflow initiation contracts are present", async () => {
  const { spawnSync } = await import("node:child_process");
  const r = spawnSync(
    process.execPath,
    [
      "--import",
      new URL("./stage-a-platform-register.mjs", import.meta.url).pathname,
      new URL("./stage-a-caller-entrypoint-probe.mjs", import.meta.url)
        .pathname,
    ],
    { encoding: "utf8", timeout: 10000 },
  );
  assert.equal(r.status, 0, r.stderr + r.stdout);
  assert.match(r.stdout, /MANUAL_OPERATOR_AND_PRIVATE_BINDING_VERIFIED/);
});

test("all 24 manual cases retain recovery counterexample rather than asserting recovery safety", async () => {
  const f = await fresh();
  for (let i = 1; i <= 24; i++) {
    const id = i.toString(16).padStart(32, "0");
    const r = await f.caller.execute(f.request("ADVANCE_ONE", id));
    assert.equal(r.status, "COMPLETED", JSON.stringify(r));
    assert.equal(r.receipt.case_index, i);
    f.clock.now += 61;
  }
  assert.deepEqual(f.calls, { status: 24, qualify: 24 });
  assert.equal(f.journal.read().next_index, 25);
});
test("persistent restart retains committed receipt and never replays in-flight intent", async () => {
  const { mkdtempSync, rmSync } = await import("node:fs"),
    { tmpdir } = await import("node:os"),
    { LocalSqliteStorage } = await import("./support.mjs"),
    dir = mkdtempSync(tmpdir() + "/df-caller-");
  try {
    let storage = new LocalSqliteStorage(dir + "/state.sqlite"),
      f = await fresh({ storage });
    await f.caller.execute(f.request());
    storage.db.close();
    storage = new LocalSqliteStorage(dir + "/state.sqlite");
    f = await fresh({ storage });
    assert.equal(
      (await f.caller.execute(f.request("READ_RECEIPT"))).status,
      "COMPLETED",
    );
    assert.equal(f.calls.qualify, 0);
    storage.db.close();
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
test("restart marks a durable dispatch latch UNKNOWN without automatic rearm", async () => {
  const f = await fresh(),
    { CallerJournal } = await import("../stage-a-caller/journal.mjs"),
    { hash, canonical } = await import("../protocol.mjs");
  f.journal.admit(f.request(), await hash(canonical(f.request())));
  f.journal.latch(f.id);
  const reopened = new CallerJournal(f.storage, f.config, () => f.clock.now);
  assert.equal(reopened.read().operations[f.id].state, "UNKNOWN");
  assert.equal(reopened.read().operations[f.id].reason, "CALLER_RESTART");
  assert.equal(f.calls.qualify, 0);
});

test("target timeout preserves intent and rejects late response and further advance", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout"] });
  const f = await fresh();
  let release, started;
  const waiting = new Promise((r) => (started = r));
  const original = f.service.qualify;
  f.service.qualify = async (x) => {
    const result = await original(x);
    started();
    return new Promise((r) => (release = () => r(result)));
  };
  const pending = f.caller.execute(f.request());
  await waiting;
  t.mock.timers.tick(20001);
  assert.equal((await pending).status, "UNKNOWN");
  release();
  await Promise.resolve();
  assert.equal(f.journal.read().operations[f.id].state, "UNKNOWN");
  f.clock.now += 61;
  assert.equal(
    (await f.caller.execute(f.request("ADVANCE_ONE", "2".repeat(32)))).status,
    "DENIED",
  );
  assert.equal(f.calls.qualify, 1);
});
test("storage outage cannot dispatch or manufacture terminal evidence", async () => {
  const f = await fresh();
  f.storage.available = false;
  assert.equal((await f.caller.execute(f.request())).status, "DENIED");
  assert.equal(f.calls.qualify, 0);
});
test("freshness is rechecked after awaiting target preflight", async () => {
  const f = await fresh(),
    status = f.service.status;
  f.service.status = () => {
    const result = status();
    f.clock.now += 121;
    return result;
  };
  assert.equal((await f.caller.execute(f.request())).status, "UNKNOWN");
  assert.equal(f.calls.qualify, 0);
});
test("operator plan rejects caller-selected credentials and endpoints", async () => {
  const { operatorPlan } = await import("../stage-a-caller/operator-plan.mjs"),
    f = await fresh(),
    { build, ...r } = f.request();
  for (const key of ["url", "Authorization", "token", "fault", "reset"])
    assert.throws(() =>
      operatorPlan({ account_id: "a".repeat(32), ...r, [key]: "forbidden" }),
    );
});
