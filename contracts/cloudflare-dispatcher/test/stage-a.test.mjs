import test from "node:test";
import assert from "node:assert/strict";
import { existsSync } from "node:fs";

test("Stage A factory supplies bounded synthetic-only SQLite qualification", async () => {
  assert.ok(
    existsSync(new URL("../stage-a/factory.mjs", import.meta.url)),
    "Stage A synthetic factory required",
  );
  const { stageFixture } = await import("./stage-a-support.mjs");
  const f = stageFixture();
  const report = await f.runner.step();
  assert.equal(report.case_id, "atomic_commit");
  assert.equal(report.result, "VERIFIED_SYNTHETIC");
  assert.equal(report.provider_network_calls, 0);
});

async function fresh(options) {
  return (await import("./stage-a-support.mjs")).stageFixture(options);
}
test("fixed Stage A suite completes with the rollback counterexample explicitly blocked", async () => {
  const f = await fresh();
  const { CASES } = await import("../stage-a/suite.mjs");
  const results = [];
  for (const id of CASES) {
    const r = await f.runner.step();
    assert.equal(r.case_id, id, JSON.stringify(r));
    results.push(r);
    f.clock.now += 21;
  }
  assert.equal(results.at(-1).complete, true);
  assert.equal(
    results.find((r) => r.case_id === "all_root_counterexample").result,
    "COUNTEREXAMPLE_REPRODUCED",
  );
  assert.ok(results.every((r) => r.provider_network_calls === 0));
  assert.ok(results.at(-1).invocations <= 1000);
  assert.ok(results.at(-1).storage_bytes <= 10000000);
});
for (const field of [
  "PRINTIFY_TOKEN",
  "CLOUDFLARE_API_TOKEN",
  "VAULT",
  "ASSETS",
])
  test("factory rejects unexpected " + field, async () => {
    await assert.rejects(
      fresh({ envOverrides: { [field]: "forbidden" } }),
      /SCHEMA/,
    );
  });
for (const mode of ["production", "UNKNOWN", "DISABLED", ""])
  test("factory rejects execution mode " + mode, async () => {
    await assert.rejects(
      fresh({ envOverrides: { DF_QUAL_MODE: mode } }),
      /SYNTHETIC_MODE/,
    );
  });
test("missing binding and arbitrary URL bindings fail before synthetic work", async () => {
  await assert.rejects(
    fresh({ envOverrides: { DF_QUAL_STATE: null } }),
    /NAMESPACE/,
  );
  await assert.rejects(
    fresh({ envOverrides: { DF_QUAL_STATE: "https://example.invalid" } }),
    /NAMESPACE/,
  );
});
test("test window rejects expired or overlong configuration", async () => {
  await assert.rejects(
    fresh({ envOverrides: { DF_QUAL_WINDOW_END: "999" } }),
    /TEST_WINDOW/,
  );
  await assert.rejects(
    fresh({ envOverrides: { DF_QUAL_WINDOW_END: "99999" } }),
    /TEST_WINDOW/,
  );
});
test("private RPC admission is bounded at three per rolling minute", async () => {
  const f = await fresh();
  for (let n = 0; n < 3; n++) assert.ok((await f.runner.step()).case_id);
  assert.equal((await f.runner.step()).status, "QUALIFICATION_DENIED");
  f.clock.now += 61;
  assert.ok((await f.runner.step()).case_id);
});
test("receipt retrieval cannot bypass the 1000 invocation budget", async () => {
  const f = await fresh();
  const d = JSON.parse(
    f.storage.db.prepare("SELECT document FROM df_stage_a_budget").get()
      .document,
  );
  d.used = 999;
  f.storage.db
    .prepare("UPDATE df_stage_a_budget SET document=?")
    .run(JSON.stringify(d));
  f.runner.status();
  assert.throws(() => f.runner.status());
});
test("expiry during a run prevents new case execution", async () => {
  const f = await fresh();
  f.clock.now = 4590;
  assert.equal((await f.runner.step()).status, "QUALIFICATION_DENIED");
});
test("global network APIs are sealed and cannot be replaced", async () => {
  const f = await fresh();
  for (const name of [
    "fetch",
    "WebSocket",
    "XMLHttpRequest",
    "EventSource",
    "WebTransport",
  ]) {
    assert.throws(
      () => f.globals[name]("https://example.invalid"),
      /EGRESS_DENIED/,
    );
    assert.equal(
      Object.getOwnPropertyDescriptor(f.globals, name).writable,
      false,
    );
  }
});
test("unsealable runtime is denied instead of falling back to network", async () => {
  const globals = {};
  Object.defineProperty(globals, "fetch", {
    value: () => {},
    writable: false,
    configurable: false,
  });
  await assert.rejects(fresh({ globals }), /EGRESS_SEAL_UNAVAILABLE/);
});
test("Stage A entrypoint exists with private qualification RPC and unconditional HTTP503", async () => {
  assert.ok(
    existsSync(new URL("../stage-a/entrypoint.mjs", import.meta.url)),
    "private Cloudflare entrypoint required",
  );
  const { spawnSync } = await import("node:child_process");
  const r = spawnSync(
    process.execPath,
    [
      "--import",
      new URL("./stage-a-platform-register.mjs", import.meta.url).pathname,
      new URL("./stage-a-entrypoint-probe.mjs", import.meta.url).pathname,
    ],
    { encoding: "utf8", timeout: 10000 },
  );
  assert.equal(r.status, 0, r.stderr + r.stdout);
  assert.match(r.stdout, /PRIVATE_RPC_AND_HTTP_DENIAL_VERIFIED/);
});

test("receipt queries share the private three-per-minute admission limit", async () => {
  const f = await fresh();
  for (let i = 0; i < 3; i++) f.runner.status();
  assert.throws(() => f.runner.status(), /RPC_QUOTA/);
});
test("namespace replacement cannot silently reuse an existing run budget", async () => {
  const f = await fresh();
  const { createStageA } = await import("../stage-a/factory.mjs");
  const env = {
    ...f.env,
    DF_QUAL_STATE: { idFromName: () => "c".repeat(64), get: () => {} },
  };
  assert.throws(
    () =>
      createStageA({ ...f.ctx, id: "c".repeat(64) }, env, {
        clock: () => f.clock.now,
        globals: {},
      }),
    /DEPLOYMENT_BINDING/,
  );
});
test("SQLite results and consumed nonces survive closing and reopening the database", async () => {
  const { mkdtempSync, rmSync } = await import("node:fs");
  const { tmpdir } = await import("node:os");
  const { join } = await import("node:path");
  const { LocalSqliteStorage } = await import("./support.mjs");
  const dir = mkdtempSync(join(tmpdir(), "df-stage-a-"));
  try {
    const a = await fresh({
      storage: new LocalSqliteStorage(join(dir, "fixture.sqlite")),
    });
    await a.runner.step();
    a.storage.db.close();
    const b = await fresh({
      storage: new LocalSqliteStorage(join(dir, "fixture.sqlite")),
    });
    assert.equal(b.runner.status().completed, 1);
    assert.equal(
      JSON.parse(
        b.storage.db
          .prepare("SELECT document FROM atomic_commit_state WHERE id=1")
          .get().document,
      ).nonces.one,
      true,
    );
    b.storage.db.close();
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
test("interrupted case rehydration fences rather than retrying it", async () => {
  const f = await fresh();
  const doc = JSON.parse(
    f.storage.db.prepare("SELECT document FROM df_stage_a_budget").get()
      .document,
  );
  doc.phase = "RUNNING";
  f.storage.db
    .prepare("UPDATE df_stage_a_budget SET document=?")
    .run(JSON.stringify(doc));
  const r = await fresh({ storage: f.storage, clock: f.clock });
  assert.equal((await r.runner.step()).status, "QUALIFICATION_DENIED");
  assert.equal(
    JSON.parse(
      f.storage.db.prepare("SELECT document FROM df_stage_a_budget").get()
        .document,
    ).recovery_required,
    true,
  );
});
test("near-limit SQLite storage is denied conservatively", async () => {
  const { LocalSqliteStorage } = await import("./support.mjs");
  const storage = new LocalSqliteStorage();
  Object.defineProperty(storage.sql, "databaseSize", { get: () => 9999999 });
  const { createStageA, MODE } = await import("../stage-a/factory.mjs");
  const env = {
    DF_QUAL_MODE: MODE,
    DF_QUAL_WINDOW_START: "1",
    DF_QUAL_WINDOW_END: "3601",
    DF_QUAL_BUILD_ID: "b".repeat(64),
    DF_QUAL_STATE: { idFromName: () => "a".repeat(64), get: () => {} },
  };
  assert.throws(
    () =>
      createStageA({ id: "a".repeat(64), storage }, env, {
        clock: () => 1000,
        globals: {},
      }),
    /STORAGE_LIMIT/,
  );
});

test("synthetic driver never retries and requires the exact private service contract", async () => {
  const { runStageAStep } = await import("../stage-a/driver.mjs");
  await assert.rejects(
    runStageAStep({
      fetch: () => {
        throw Error("not allowed");
      },
    }),
    /PRIVATE_SERVICE/,
  );
  let calls = 0;
  await assert.rejects(
    runStageAStep({
      qualify: () => {
        calls++;
        throw Error("synthetic failure");
      },
    }),
  );
  assert.equal(calls, 1);
  const f = await fresh();
  const r = await runStageAStep({ qualify: () => f.runner.step() });
  assert.equal(r.case_id, "atomic_commit");
});
test("deployment template has no public routes, provider binding, alarms or triggers", async () => {
  const { readFileSync } = await import("node:fs");
  const c = JSON.parse(
    readFileSync(new URL("../stage-a/wrangler.template.json", import.meta.url)),
  );
  assert.equal(c.workers_dev, false);
  assert.equal(c.preview_urls, false);
  assert.deepEqual(c.routes, []);
  assert.equal(c.name, "digiforge-dispatcher-qualification");
  assert.equal(c.vars.DF_QUAL_MODE, "DISABLED");
  assert.equal(c.durable_objects.bindings[0].name, "DF_QUAL_STATE");
  assert.deepEqual(c.migrations[0].new_sqlite_classes, [
    "DigiForgeQualificationState",
  ]);
  for (const k of [
    "services",
    "queues",
    "triggers",
    "kv_namespaces",
    "d1_databases",
    "secrets",
    "dispatch_namespaces",
  ])
    assert.equal(c[k], undefined);
  assert.equal(c.no_bundle, true);
});

test("private driver returns a bounded error instead of service exception details", async () => {
  const { runStageAStep } = await import("../stage-a/driver.mjs");
  await assert.rejects(
    runStageAStep({
      qualify: () => {
        throw Error("private-fixture-sentinel");
      },
    }),
    (e) => e.message === "STAGE_A_INVOCATION_UNKNOWN",
  );
});

test("offline driver completes all24 cases with synthetic time and no provider calls", async () => {
  const { spawnSync } = await import("node:child_process");
  const r = spawnSync(
    process.execPath,
    [new URL("../stage-a/offline-driver.mjs", import.meta.url).pathname],
    { encoding: "utf8", timeout: 10000 },
  );
  assert.equal(r.status, 0, r.stderr);
  const j = JSON.parse(r.stdout);
  assert.equal(j.cases, 24);
  assert.equal(j.clock, "SYNTHETIC_CLOCK");
  assert.equal(j.hosted, "HOSTED_NOT_EXECUTED");
  assert.equal(j.provider_network_calls, 0);
  assert.equal(j.recovery, "RECOVERY_BLOCKED");
});

test("repeated admission intent after a lost RPC response cannot advance the case sequence", async () => {
  const f = await fresh();
  const a = await f.runner.step("1".repeat(32));
  f.clock.now += 21;
  const b = await f.runner.step("1".repeat(32));
  assert.equal(b.case_id, a.case_id);
  assert.equal(b.case_index, a.case_index);
  f.clock.now += 21;
  assert.equal(f.runner.status().completed, 1);
});

for (const used of [-1, null, 1.5])
  test(
    "malformed durable budget counter is denied: " + String(used),
    async () => {
      const f = await fresh();
      const d = JSON.parse(
        f.storage.db.prepare("SELECT document FROM df_stage_a_budget").get()
          .document,
      );
      d.used = used;
      f.storage.db
        .prepare("UPDATE df_stage_a_budget SET document=?")
        .run(JSON.stringify(d));
      assert.equal((await f.runner.step()).status, "QUALIFICATION_DENIED");
    },
  );

test("wall-clock session cap denies a stalled case even when fixture time is unchanged", async () => {
  const f = await fresh();
  const p = f.runner.step();
  f.clock.now += 301;
  assert.equal((await p).status, "QUALIFICATION_DENIED");
});
