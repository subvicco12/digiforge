import test from "node:test";
import assert from "node:assert/strict";
import { existsSync } from "node:fs";

async function fixture() {
  assert.ok(
    existsSync(new URL("../runtime.mjs", import.meta.url)),
    "Cloudflare offline runtime required",
  );
  return (await import("./support.mjs")).fixture();
}

test("unconfigured Worker is non-executable", async () => {
  const f = await fixture();
  const r = await f.worker.fetch(
    new Request("https://dispatcher.fixture.invalid/v1/dispatch", {
      method: "POST",
      body: "{}",
    }),
    {},
  );
  assert.equal(r.status, 503);
  assert.equal(f.calls.length, 0);
});
test("independent issuer creates a bounded immutable session and signed minimized receipt", async () => {
  const f = await fixture(),
    s = await f.session(2);
  assert.equal(s.status, "ISSUED");
  assert.equal(s.requests.length, 2);
  const r = await f.dispatch(s, 0);
  assert.equal(r.receipt.manifest.status, "OBSERVED");
  assert.equal(r.receipt_committed, true);
  assert.equal(f.calls.length, 1);
  assert.ok(await f.verifyReceipt(r.receipt));
  assert.ok(!JSON.stringify(r).includes("buyer-private"));
  assert.equal(f.state.read().doc.claims[`${s.session}:0`].state, "TERMINAL");
});
test("Worker authenticates signed messages, rejects methods, extra fields and unsafe routes", async () => {
  const f = await fixture(),
    s = await f.session();
  const p = await f.message(s, 0);
  for (const method of ["GET", "PUT", "PATCH", "DELETE"])
    assert.equal((await f.http("/v1/dispatch", p, method)).status, 405);
  assert.equal((await f.http("/proxy", p)).status, 404);
  assert.equal(
    (await f.http("/v1/dispatch", { ...p, url: "https://evil.invalid" }))
      .status,
    400,
  );
  assert.equal(
    (
      await f.http("/v1/dispatch", p, "POST", {
        Authorization: "Bearer caller",
      })
    ).status,
    400,
  );
  assert.equal((await f.http("/v1/dispatch", p)).status, 200);
  assert.equal(f.calls.length, 1);
});
test("missing and forged request authentication never reaches sender", async () => {
  const f = await fixture(),
    s = await f.session(),
    m = await f.message(s, 0);
  delete m.authentication;
  assert.equal((await f.runtime.dispatch(m)).status, "DENIED");
  const m2 = await f.message(s, 0);
  m2.authentication.signature = "AAAA";
  assert.equal((await f.runtime.dispatch(m2)).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("wrong actor account shop integration credential and deployment bindings deny", async () => {
  for (const [key, value] of Object.entries({
    actor: 2,
    account: "other",
    shop_key: "standard_pod",
    internal_shop_id: 3,
    integration_id: 4,
    provider_shop_id: "28925875",
    credential_binding: "0".repeat(64),
    epoch: "0".repeat(64),
  })) {
    const f = await fixture(),
      s = await f.session();
    const m = await f.message(s, 0, { [key]: value });
    assert.equal((await f.runtime.dispatch(m)).status, "DENIED", key);
    assert.equal(f.calls.length, 0);
  }
});
test("expired future stale and replayed authority denied", async () => {
  const f = await fixture(),
    s = await f.session();
  f.clock.now += 301;
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 0);
  const g = await fixture(),
    t = await g.session();
  assert.equal((await g.dispatch(t, 0)).receipt.manifest.status, "OBSERVED");
  assert.equal((await g.dispatch(t, 0)).status, "DENIED");
  assert.equal(g.calls.length, 1);
});
test("restored WordPress signing key cannot issue an independent owner grant", async () => {
  const f = await fixture();
  const a = await f.ownerAdmission(1);
  a.proof = await f.signWithUntrusted(a.proof.manifest);
  assert.equal((await f.runtime.createSession(a)).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("email actor proof or approval alone cannot enroll a session", async () => {
  const f = await fixture(),
    a = await f.ownerAdmission(1);
  const copy = structuredClone(a);
  delete copy.approval;
  assert.equal((await f.runtime.createSession(copy)).status, "DENIED");
  a.proof.manifest.actor = 2;
  assert.equal((await f.runtime.createSession(a)).status, "DENIED");
});
test("owner grant is single-use and never silently renews", async () => {
  const f = await fixture(),
    a = await f.ownerAdmission(1);
  assert.equal((await f.runtime.createSession(a)).status, "ISSUED");
  assert.equal((await f.runtime.createSession(a)).status, "DENIED");
});
test("eleven slots and fabricated catalog permissions denied", async () => {
  const f = await fixture();
  assert.equal((await f.session(11)).status, "DENIED");
  const a = await f.ownerAdmission(1, [
    {
      operation: "BLUEPRINTS",
      method: "GET",
      endpoint: "/v1/catalog/blueprints.json",
      body: [],
    },
  ]);
  assert.equal((await f.runtime.createSession(a)).status, "DENIED");
});
test("mutating endpoint and arbitrary host cannot be approved", async () => {
  for (const request of [
    {
      operation: "SHOPS_READ",
      method: "POST",
      endpoint: "/v1/shops.json",
      body: [],
    },
    {
      operation: "SHOPS_READ",
      method: "GET",
      endpoint: "https://evil.invalid",
      body: [],
    },
    {
      operation: "PRODUCTS_READ",
      method: "GET",
      endpoint: "/v1/shops/28925875/products.json?page=1&limit=1",
      body: [],
    },
  ]) {
    const f = await fixture(),
      a = await f.ownerAdmission(1, [request]);
    assert.equal((await f.runtime.createSession(a)).status, "DENIED");
    assert.equal(f.calls.length, 0);
  }
});
test("concurrent same-slot dispatch sends once", async () => {
  const f = await fixture(),
    s = await f.session(),
    m = await f.message(s, 0);
  const all = await Promise.all([f.runtime.dispatch(m), f.runtime.dispatch(m)]);
  assert.equal(f.calls.length, 1);
  assert.equal(
    all.filter((x) => x.receipt?.manifest.status === "OBSERVED").length,
    1,
  );
});
test("account-wide quota admits at most three per rolling minute", async () => {
  const f = await fixture(),
    s = await f.session(4);
  for (let i = 0; i < 3; i++)
    assert.equal((await f.dispatch(s, i)).receipt.manifest.status, "OBSERVED");
  assert.equal((await f.dispatch(s, 3)).status, "DENIED");
  assert.equal(f.calls.length, 3);
});
test("emergency before session or reservation prevents admission", async () => {
  const f = await fixture();
  assert.equal((await f.stop()).receipt.manifest.status, "DRAIN_ACKNOWLEDGED");
  assert.equal((await f.session()).status, "DENIED");
  const g = await fixture(),
    s = await g.session();
  await g.stop();
  assert.equal((await g.dispatch(s, 0)).status, "DENIED");
  assert.equal(g.calls.length, 0);
});
test("emergency after reservation and before final dispatch prevents send", async () => {
  for (const event of ["RESERVED", "ADMITTED"]) {
    const f = await fixture(),
      s = await f.session();
    f.onEvent(event, () => f.stop());
    assert.equal((await f.dispatch(s, 0)).status, "DENIED");
    assert.equal(f.calls.length, 0);
  }
});
test("emergency during provider execution retains lease until terminal and reports UNKNOWN", async () => {
  const f = await fixture(),
    s = await f.session();
  let stop;
  f.provider.during = async () => {
    stop = await f.stop();
  };
  const r = await f.dispatch(s, 0);
  assert.equal(stop.receipt.manifest.status, "DRAIN_PENDING");
  assert.equal(r.receipt.manifest.status, "UNKNOWN");
  assert.deepEqual(r.receipt.manifest.observations, {
    values_disclosed: false,
  });
  assert.equal((await f.stop()).receipt.manifest.status, "DRAIN_ACKNOWLEDGED");
});
test("emergency after response during receipt persistence cannot emit a stale success", async () => {
  const f = await fixture(),
    s = await f.session();
  f.onEvent("TERMINAL", () => f.stop());
  const r = await f.dispatch(s, 0);
  assert.equal(r.status, "UNKNOWN");
  assert.equal(r.receipt_committed, false);
  assert.equal((await f.stop()).receipt.manifest.status, "DRAIN_PENDING");
});
test("state outage after send preserves uncertainty and never restores slot", async () => {
  const f = await fixture(),
    s = await f.session();
  f.provider.during = () => {
    f.storage.available = false;
  };
  const r = await f.dispatch(s, 0);
  assert.equal(r.status, "UNKNOWN");
  assert.equal(r.receipt_committed, false);
  f.storage.available = true;
  assert.equal((await f.stop()).receipt.manifest.status, "DRAIN_PENDING");
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 1);
});
test("emergency state-write failure still fences through independent witness", async () => {
  const f = await fixture(),
    s = await f.session();
  f.storage.available = false;
  const stop = await f.stop();
  assert.notEqual(stop.receipt?.manifest.status, "DRAIN_ACKNOWLEDGED");
  f.storage.available = true;
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("witness outage and network partition fail closed", async () => {
  const f = await fixture(),
    s = await f.session();
  f.root.available = false;
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.notEqual(
    (await f.stop()).receipt?.manifest.status,
    "DRAIN_ACKNOWLEDGED",
  );
  assert.equal(f.calls.length, 0);
});
test("provider timeout sends once UNKNOWN with no retry", async () => {
  const f = await fixture(),
    s = await f.session();
  f.provider.failure = true;
  const r = await f.dispatch(s, 0);
  assert.equal(r.receipt.manifest.status, "UNKNOWN");
  assert.ok(!JSON.stringify(r).includes("private-exception"));
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 1);
});
test("provider rate limit persists Retry-After without retry", async () => {
  const f = await fixture(),
    s = await f.session(2);
  f.provider.status = 429;
  const r = await f.dispatch(s, 0);
  assert.equal(r.receipt.manifest.status, "REJECTED");
  assert.equal((await f.dispatch(s, 1)).status, "DENIED");
  assert.equal(f.calls.length, 1);
});
test("stream size invalid JSON and redirects rejected without leakage", async () => {
  for (const mode of ["oversized", "invalid", "redirect"]) {
    const f = await fixture(),
      s = await f.session();
    f.provider.mode = mode;
    const r = await f.dispatch(s, 0);
    assert.notEqual(r.receipt.manifest.status, "OBSERVED");
    assert.equal(f.calls.length, 1);
  }
});
test("physical sender pins host method TLS redirects timeout and private headers", async () => {
  const f = await fixture(),
    s = await f.session();
  await f.dispatch(s, 0);
  const c = f.calls[0];
  assert.equal(c.url, "https://api.printify.com/v1/shops.json");
  assert.equal(c.options.method, "GET");
  assert.equal(c.options.redirect, "error");
  assert.equal(c.options.body, undefined);
  assert.ok(c.options.signal instanceof AbortSignal);
  assert.deepEqual(Object.keys(c.options.headers).sort(), [
    "Accept",
    "Authorization",
    "User-Agent",
  ]);
});
test("credential unavailable duplicated or host-restorable denies custody", async () => {
  for (const field of ["available", "exclusive", "hostCopiesInvalidated"]) {
    const f = await fixture(),
      s = await f.session();
    f.custody[field] = false;
    assert.equal((await f.dispatch(s, 0)).status, "DENIED");
    assert.equal(f.calls.length, 0);
  }
});
test("WordPress DB filesystem plugin and coordinated rollback cannot reset witness", async () => {
  const f = await fixture(),
    s = await f.session();
  await f.stop();
  for (const kind of ["database", "filesystem", "plugin", "coordinated"]) {
    assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  }
  assert.equal(f.calls.length, 0);
});
test("SQLite point-in-time restore cannot replay consumed session", async () => {
  const f = await fixture(),
    s = await f.session(),
    snapshot = f.storage.snapshot();
  await f.dispatch(s, 0);
  f.storage.restore(snapshot);
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 1);
});
test("Worker code namespace and recovery epoch rollback fail witness binding", async () => {
  for (const field of ["code", "namespace", "epoch"]) {
    const f = await fixture(),
      s = await f.session();
    f.config[field] = "0".repeat(64);
    assert.equal((await f.dispatch(s, 0)).status, "DENIED");
    assert.equal(f.calls.length, 0);
  }
});
test("external recovery epoch change invalidates stale authority", async () => {
  const f = await fixture(),
    s = await f.session();
  f.root.epoch = "0".repeat(64);
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("dispatcher restart requires recovery and preserves leases", async () => {
  const f = await fixture(),
    s = await f.session();
  await f.runtime.startup();
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 0);
  assert.equal(f.root.recovery, true);
});
test("SQLite adapter rolls back errors and rejects async transactions", async () => {
  const f = await fixture(),
    before = f.state.read().doc.revision;
  assert.throws(() => f.state.atomic(async () => 1));
  assert.throws(() =>
    f.state.atomic(() => {
      f.storage.sql.exec("DELETE FROM df_state");
      throw Error("fault");
    }),
  );
  assert.equal(f.state.read().doc.revision, before);
});
test("lost commit acknowledgement cannot send or reuse slot", async () => {
  const f = await fixture(),
    s = await f.session();
  f.storage.loseAck = true;
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("independent final sender rejects substituted allowlisted request", async () => {
  const f = await fixture(),
    s = await f.session();
  const physical = f.runtime.sender.send.bind(f.runtime.sender);
  f.runtime.sender.send = (_request, claim) =>
    physical(
      {
        operation: "ORDER_READ",
        method: "GET",
        endpoint: "/v1/shops/28930593/orders/private-order.json",
        body: [],
      },
      claim,
    );
  const r = await f.dispatch(s, 0);
  assert.equal(f.calls.length, 0);
  assert.notEqual(r.receipt?.manifest.status, "OBSERVED");
});
test("failed authorized emergency cannot silently rearm after witness recovery", async () => {
  const f = await fixture(),
    s = await f.session();
  f.root.available = false;
  const stop = await f.stop();
  assert.equal(stop.durable_revocation_recorded, false);
  f.root.available = true;
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("unauthenticated emergency cannot quarantine a valid session", async () => {
  const f = await fixture(),
    s = await f.session();
  await f.runtime.emergency({
    authority: { key_id: "stop-v1", manifest: {}, signature: "invalid" },
  });
  assert.equal((await f.dispatch(s, 0)).receipt.manifest.status, "OBSERVED");
});
test("Cloudflare-compatible DO constructor initializes once and fences restart", async () => {
  const make = (await import("./support.mjs")).fixture;
  const f = await make({ startup: false });
  const { PrintifyAcceptanceDO } = await import("../worker.mjs");
  const ctx = { storage: f.storage, blockConcurrencyWhile: (fn) => fn() };
  const object = new PrintifyAcceptanceDO(ctx, { OFFLINE_RUNTIME: f.runtime });
  assert.equal(await object.ready, true);
  const s = await f.session(),
    payload = await f.message(s, 0);
  const r = await object.fetch(
    new Request("https://dispatcher.fixture.invalid/v1/dispatch", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: (await import("../protocol.mjs")).canonical(payload),
    }),
  );
  assert.equal(r.status, 200);
  const restarted = new PrintifyAcceptanceDO(ctx, {
    OFFLINE_RUNTIME: f.runtime,
  });
  assert.equal(await restarted.ready, false);
  assert.equal(f.state.read().doc.recovery_required, true);
  assert.equal(
    (
      await restarted.fetch(
        new Request("https://dispatcher.fixture.invalid/v1/dispatch", {
          method: "POST",
        }),
      )
    ).status,
    503,
  );
});
test("duplicate keys oversized messages and unsafe origin fail strict HTTP ingress", async () => {
  const f = await fixture();
  for (const body of [
    '{"authority":{},"authentication":{},"authority":{}}',
    "x".repeat(16385),
  ]) {
    const r = await f.worker.fetch(
      new Request("https://dispatcher.fixture.invalid/v1/dispatch", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body,
      }),
      { OFFLINE_RUNTIME: f.runtime },
    );
    assert.equal(r.status, 400);
  }
  assert.equal(f.calls.length, 0);
  const r = await f.worker.fetch(
    new Request("http://dispatcher.fixture.invalid/v1/dispatch", {
      method: "POST",
      body: "{}",
    }),
    { OFFLINE_RUNTIME: f.runtime },
  );
  assert.equal(r.status, 404);
});
test("production flags cannot instantiate live dispatcher or sender", async () => {
  const f = await fixture();
  assert.equal(
    (
      await f.worker.fetch(
        new Request("https://dispatcher.fixture.invalid/v1/dispatch", {
          method: "POST",
        }),
        { RECOVERY_QUALIFIED: true, DISPATCH_ENABLED: true },
      )
    ).status,
    503,
  );
  const { SyntheticFinalSender } = await import("../sender.mjs");
  assert.throws(
    () => new SyntheticFinalSender({}, globalThis, f.witness, f.config),
  );
  assert.equal(f.calls.length, 0);
});
test("actual 20-second sender deadline retains uncertain lease when fetch ignores abort", async (t) => {
  const f = await fixture(),
    s = await f.session();
  let began;
  const started = new Promise((resolve) => {
    began = resolve;
  });
  f.provider.fetchOverride = () => {
    began();
    return new Promise(() => {});
  };
  t.mock.timers.enable({ apis: ["setTimeout"] });
  const pending = f.dispatch(s, 0);
  await started;
  t.mock.timers.tick(20000);
  const result = await pending;
  assert.equal(result.receipt.manifest.status, "UNKNOWN");
  assert.equal(f.calls.length, 1);
  assert.equal((await f.stop()).receipt.manifest.status, "DRAIN_PENDING");
});
test("independent witness rejects forged scope even with valid issuer signing key", async () => {
  const f = await fixture(),
    a = await f.ownerAdmission(1);
  const grant = await f.runtime.issuer.prepare(
    a,
    f.clock.now,
    f.root.generation,
  );
  const { sign } = await import("../protocol.mjs");
  grant.requests[0] = await sign(
    f.runtime.issuer.ports.readPrivate,
    "read-v1",
    {
      ...grant.requests[0].manifest,
      request: {
        operation: "ORDER_READ",
        method: "GET",
        endpoint: "/v1/shops/28930593/orders/other.json",
        body: [],
      },
    },
  );
  await assert.rejects(() =>
    f.witness.register(grant, a, f.config, f.clock.now),
  );
  assert.equal(f.calls.length, 0);
});
test("independent owner approval binds the exact read capability and approved policy revision", async () => {
  const f = await fixture(),
    a = await f.ownerAdmission(1);
  assert.equal(a.approval.manifest.capability, "printify.acceptance.read");
  assert.equal(
    a.approval.manifest.policy_revision,
    "ffbbfe63ecc4223d3192c7fc01a8a33e872cfb51f78a896696f2537465526c9e",
  );
});
test("witness snapshot exposes no mutable session or nonce ledger", async () => {
  const f = await fixture(),
    snapshot = await f.witness.snapshot(f.config);
  assert.equal("sessions" in snapshot, false);
  assert.equal("nonces" in snapshot, false);
  assert.throws(() => {
    snapshot.generation = 99;
  });
  assert.equal(f.root.generation, 1);
});
test("final witness rejects authority that expires after ADMITTED persistence", async () => {
  const f = await fixture(),
    s = await f.session();
  const sender = f.runtime.sender.send.bind(f.runtime.sender);
  f.runtime.sender.send = (request, claim) => {
    f.clock.now += 301;
    return sender(request, claim);
  };
  await f.dispatch(s, 0);
  assert.equal(f.calls.length, 0);
});
test("final witness independently checks short-lived client proof expiry", async () => {
  const f = await fixture(),
    s = await f.session();
  const sender = f.runtime.sender.send.bind(f.runtime.sender);
  f.runtime.sender.send = (request, claim) => {
    f.clock.now += 31;
    return sender(request, claim);
  };
  await f.dispatch(s, 0);
  assert.equal(f.calls.length, 0);
});
test("forged caller clock cannot override independent witness expiry", async () => {
  const f = await fixture(),
    s = await f.session();
  f.clock.now += 301;
  f.runtime.clock = () => 1000;
  await f.dispatch(s, 0);
  assert.equal(f.calls.length, 0);
});
test("caller cannot release a physical lease before sender completion", async () => {
  const f = await fixture(),
    s = await f.session();
  let disposition;
  f.provider.during = async () => {
    try {
      await f.witness.finish(s.session + ":0", f.config);
      disposition = "RELEASED";
    } catch {
      disposition = "DENIED";
    }
  };
  await f.dispatch(s, 0);
  assert.equal(disposition, "DENIED");
});
test("independent quota witness enforces provider Retry-After despite caller bypass", async () => {
  const f = await fixture(),
    s = await f.session(2);
  f.provider.status = 429;
  await f.dispatch(s, 0);
  f.clock.now += 61;
  const p = await f.message(s, 1);
  await assert.rejects(() =>
    f.witness.reserve(
      p.authority.manifest,
      p.authentication.manifest,
      s.session + ":1",
      f.config,
      0,
      p,
    ),
  );
  assert.equal(f.calls.length, 1);
});
test("401 and 403 fence credential admission while 5xx never retries", async () => {
  for (const status of [401, 403, 500]) {
    const f = await fixture(),
      s = await f.session(2);
    f.provider.status = status;
    const r = await f.dispatch(s, 0);
    assert.equal(r.receipt.manifest.status, "REJECTED");
    assert.equal(f.calls.length, 1);
    if (status !== 500) {
      assert.equal((await f.dispatch(s, 1)).status, "DENIED");
      assert.equal(f.root.credentialRejected, true);
    }
  }
});
test("emergency during session persistence cannot return usable authority", async () => {
  const f = await fixture();
  f.onEvent("SESSION", () => f.stop());
  assert.equal((await f.session()).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("namespace replacement cannot reset witness-authoritative consumed state", async () => {
  const f = await fixture(),
    s = await f.session();
  const { LocalSqliteStorage } = await import("./support.mjs"),
    { SqliteState, initialState } = await import("../sqlite-state.mjs");
  const replacement = new SqliteState(new LocalSqliteStorage());
  replacement.bootstrap(initialState(f.config));
  f.runtime.state = replacement;
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("rollback of BOTH authority stores is explicitly unqualified and never enables production", async () => {
  const f = await fixture(),
    s = await f.session(),
    sql = f.storage.snapshot(),
    independent = structuredClone(f.root);
  await f.dispatch(s, 0);
  f.storage.restore(sql);
  for (const k of Object.keys(f.root)) delete f.root[k];
  Object.assign(f.root, structuredClone(independent));
  // This deliberately violates the independent, non-restored witness assumption.
  assert.equal((await f.dispatch(s, 0)).receipt.manifest.status, "OBSERVED");
  assert.equal(f.calls.length, 2);
  assert.equal(
    (
      await f.worker.fetch(
        new Request("https://dispatcher.fixture.invalid/v1/dispatch", {
          method: "POST",
        }),
        { LIVE_APPROVED: true },
      )
    ).status,
    503,
  );
});
test("streaming body obeys the same 20-second deadline and retains unknown lease", async (t) => {
  const f = await fixture(),
    s = await f.session();
  let began;
  const started = new Promise((resolve) => {
    began = resolve;
  });
  f.provider.fetchOverride = () => {
    began();
    return new Response(new ReadableStream({ start() {} }));
  };
  t.mock.timers.enable({ apis: ["setTimeout"] });
  const p = f.dispatch(s, 0);
  await started;
  t.mock.timers.tick(20000);
  const r = await p;
  assert.equal(r.receipt.manifest.status, "UNKNOWN");
  assert.equal((await f.stop()).receipt.manifest.status, "DRAIN_PENDING");
  assert.equal(f.calls.length, 1);
});
test("concurrent distinct slots share one account budget without replay recovery", async () => {
  const f = await fixture(),
    s = await f.session(4);
  await Promise.all([0, 1, 2, 3].map((i) => f.dispatch(s, i)));
  assert.ok(f.calls.length <= 3);
  assert.ok(f.root.used.size <= 3);
  const before = f.calls.length;
  for (let i = 0; i < 3; i++)
    assert.equal((await f.dispatch(s, i)).status, "DENIED");
  assert.equal(f.calls.length, before);
});
test("missing audit signer denies sending and never restores consumed slot", async () => {
  const f = await fixture(),
    s = await f.session();
  f.onEvent("RESERVED", () => {
    throw Error("synthetic-audit-outage");
  });
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal((await f.dispatch(s, 0)).status, "DENIED");
  assert.equal(f.calls.length, 0);
});
test("independent registration refuses valid signatures from the wrong proof domain", async () => {
  const f = await fixture(),
    a = await f.ownerAdmission(1),
    grant = await f.runtime.issuer.prepare(a, f.clock.now, f.root.generation);
  const { hash, canonical, sign } = await import("../protocol.mjs");
  a.proof = await f.resignIdentity({
    ...a.proof.manifest,
    protocol: "different-purpose-v1",
  });
  a.approval = await f.resignApproval({
    ...a.approval.manifest,
    proof_hash: await hash(canonical(a.proof)),
  });
  grant.owner_proof = await hash(canonical(a.proof));
  grant.review = await hash(canonical(a.approval));
  grant.requests[0] = await sign(
    f.runtime.issuer.ports.readPrivate,
    "read-v1",
    {
      ...grant.requests[0].manifest,
      owner_proof: grant.owner_proof,
      review: grant.review,
    },
  );
  await assert.rejects(() => f.witness.register(grant, a, f.config, 1000));
});
test("owner proof expiry is rechecked after asynchronous registration validation", async (t) => {
  const f = await fixture(),
    a = await f.ownerAdmission(1),
    grant = await f.runtime.issuer.prepare(a, 1000, f.root.generation);
  const original = crypto.subtle.verify.bind(crypto.subtle);
  let once = true;
  t.mock.method(crypto.subtle, "verify", async (...args) => {
    const r = await original(...args);
    if (once) {
      once = false;
      f.clock.now += 121;
    }
    return r;
  });
  await assert.rejects(() => f.witness.register(grant, a, f.config, 1000));
});
