import test from "node:test";
import assert from "node:assert/strict";
import { existsSync } from "node:fs";

test("independent security boundary is implemented outside the existing rollbackable witness", async () => {
  assert.ok(
    existsSync(new URL("../authority-service.mjs", import.meta.url)),
    "independent signed authority service required",
  );
  const { authorityFixture } = await import("./authority-support.mjs");
  const f = await authorityFixture();
  const s = await f.client.call("SESSION", await f.base.ownerAdmission());
  assert.equal(s.status, "ISSUED");
  const r = await f.client.call("EXECUTE", await f.base.message(s, 0));
  assert.equal(r.receipt.manifest.status, "OBSERVED");
  assert.equal(f.base.calls.length, 1);
  assert.equal(f.root.read().leases.length, 0);
  assert.equal(f.root.read().nonce_count, 2);
});

async function fresh() {
  return (await import("./authority-support.mjs")).authorityFixture();
}
async function deny(f, operation, payload, options = {}) {
  await assert.rejects(f.makeClient(options).call(operation, payload));
  assert.equal(f.base.calls.length, 0);
}

test("signed workload admission rejects a key restored from WordPress", async () => {
  const f = await fresh();
  await deny(f, "SESSION", await f.base.ownerAdmission(), {
    certificate: await f.untrusted(f.certificate.manifest),
  });
});
for (const [field, value] of [
  ["epoch", "f".repeat(64)],
  ["namespace", "f".repeat(64)],
  ["code", "f".repeat(64)],
  ["account", "wrong-account"],
  ["credential_binding", "f".repeat(64)],
  ["version", 0],
])
  test("workload rejects signed mismatched " + field, async () => {
    const f = await fresh();
    await deny(f, "SESSION", await f.base.ownerAdmission(), {
      certificate: await f.resignCertificate({
        ...f.certificate.manifest,
        [field]: value,
      }),
    });
  });
for (const field of [
  "exclusive",
  "host_copies_invalidated",
  "backup_copies_unusable",
  "direct_fallback_disabled",
])
  test("custody denial for " + field, async () => {
    const f = await fresh();
    await deny(f, "SESSION", await f.base.ownerAdmission(), {
      custody: await f.resignCustody({ ...f.custody.manifest, [field]: false }),
    });
  });
test("minimum deployment version and workload public key are independent journal policy", async () => {
  const f = await fresh();
  f.root.change((d) => (d.minimum_version = 2));
  await deny(f, "SESSION", await f.base.ownerAdmission());
});
test("revoked workload key cannot claim a newer deployment certificate", async () => {
  const f = await fresh();
  f.root.change((d) => (d.workload_key = "A".repeat(44)));
  await deny(f, "SESSION", await f.base.ownerAdmission());
});
test("expired workload identity cannot be silently renewed", async () => {
  const f = await fresh();
  f.base.clock.now += 61;
  await deny(f, "SESSION", await f.base.ownerAdmission());
});
test("independent client rejects arbitrary RPC operations and cannot proxy requests", async () => {
  const f = await fresh();
  await deny(f, "POST", { url: "https://example.invalid", headers: {} });
});
test("mutated operation payload cannot reuse workload authentication", async () => {
  const f = await fresh();
  const m = await f.client.message("SESSION", await f.base.ownerAdmission());
  m.payload.scope.ttl = 301;
  assert.equal((await f.service.handle(m)).status, "AUTHORITY_DENIED");
  assert.equal(f.root.read().nonce_count, 0);
});
test("durable workload nonce prevents duplicate session admission", async () => {
  const f = await fresh();
  const m = await f.client.message("SESSION", await f.base.ownerAdmission());
  assert.ok((await f.service.handle(m)).signature);
  assert.equal((await f.service.handle(m)).status, "AUTHORITY_DENIED");
  assert.equal(f.root.read().nonce_count, 1);
});
test("concurrent identical workload messages consume a single durable nonce", async () => {
  const f = await fresh();
  const m = await f.client.message("SESSION", await f.base.ownerAdmission());
  const rs = await Promise.all([f.service.handle(m), f.service.handle(m)]);
  assert.equal(rs.filter((x) => x.signature).length, 1);
  assert.equal(f.root.read().nonce_count, 1);
});
test("signed response cannot be substituted from another client request", async () => {
  const f = await fresh();
  const old = await f.service.handle(
    await f.client.message("SESSION", await f.base.ownerAdmission()),
  );
  await deny(f, "SESSION", await f.base.ownerAdmission(), {
    port: { synthetic: true, send: async () => old },
  });
});
test("network partition never causes direct provider fallback or retry", async () => {
  const f = await fresh();
  let calls = 0;
  await deny(f, "SESSION", await f.base.ownerAdmission(), {
    port: {
      synthetic: true,
      send: async () => {
        calls++;
        throw Error("private network detail");
      },
    },
  });
  assert.equal(calls, 1);
});
test("coordinated DO and witness rollback cannot revive authority after independent stop", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  const sql = f.base.storage.snapshot(),
    old = structuredClone(f.base.root);
  const r = await f.client.call("EMERGENCY", await f.base.stopMessage());
  assert.equal(r.receipt.manifest.status, "DRAIN_ACKNOWLEDGED");
  assert.equal(f.root.read().stopped, true);
  f.base.storage.restore(sql);
  Object.assign(f.base.root, old);
  await deny(f, "EXECUTE", await f.base.message(s, 0));
});
test("coordinated rollback before stop is detected by independent state anchor", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission(2));
  const sql = f.base.storage.snapshot(),
    old = structuredClone(f.base.root);
  await f.client.call("EXECUTE", await f.base.message(s, 0));
  f.base.storage.restore(sql);
  Object.assign(f.base.root, old);
  const r = await f.client.call("EXECUTE", await f.base.message(s, 1));
  assert.equal(r.status, "DENIED");
  assert.equal(f.base.calls.length, 1);
});
test("state namespace replacement remains denied by independent binding", async () => {
  const f = await fresh();
  f.base.config.namespace = "f".repeat(64);
  const r = await f.client.call("SESSION", await f.base.ownerAdmission());
  assert.notEqual(r.status, "ISSUED");
  assert.equal(f.base.calls.length, 0);
});
test("restart never automatically rearms a previously booted authority", async () => {
  const f = await fresh();
  f.makeService();
  assert.equal(f.root.read().recovery, true);
  await deny(f, "SESSION", await f.base.ownerAdmission());
});
test("unavailable independent storage fails closed", async () => {
  const f = await fresh();
  f.storage.available = false;
  await deny(f, "SESSION", await f.base.ownerAdmission());
});
test("forged emergency authority cannot revoke an otherwise valid owner session", async () => {
  const f = await fresh(),
    m = await f.base.stopMessage();
  m.authority.signature = "A".repeat(88);
  await assert.rejects(f.client.call("EMERGENCY", m));
  assert.equal(f.root.read().stopped, false);
  assert.equal(
    (await f.client.call("SESSION", await f.base.ownerAdmission())).status,
    "ISSUED",
  );
});
test("lost emergency write acknowledgement fences old authority after recovery", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  f.storage.loseAck = true;
  await assert.rejects(f.client.call("EMERGENCY", await f.base.stopMessage()));
  await deny(f, "EXECUTE", await f.base.message(s, 0));
});
test("valid emergency during root outage remains fenced when storage recovers", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  f.storage.available = false;
  await assert.rejects(f.client.call("EMERGENCY", await f.base.stopMessage()));
  f.storage.available = true;
  await deny(f, "EXECUTE", await f.base.message(s, 0));
});
test("emergency during physical dispatch reports pending drain and removes observations", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  let stop;
  f.base.provider.during = async () => {
    stop = await f.client.call("EMERGENCY", await f.base.stopMessage());
  };
  const r = await f.client.call("EXECUTE", await f.base.message(s, 0));
  assert.equal(stop.receipt.manifest.status, "DRAIN_PENDING");
  assert.equal(r.receipt.manifest.status, "UNKNOWN");
  assert.deepEqual(r.receipt.manifest.observations, {
    values_disclosed: false,
  });
  assert.equal(f.root.read().leases.length, 0);
});
test("late custody revocation before physical request cannot leak a provider call", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  const perform = f.base.custody.perform;
  f.base.custody.perform = (fn) => {
    f.root.stop();
    return perform(fn);
  };
  await f.client.call("EXECUTE", await f.base.message(s, 0));
  assert.equal(f.base.calls.length, 0);
});
test("unknown physical timeout keeps independent lease and cannot acknowledge drain", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  f.base.provider.failure = true;
  const r = await f.client.call("EXECUTE", await f.base.message(s, 0));
  assert.equal(r.receipt.manifest.status, "UNKNOWN");
  assert.equal(f.root.read().leases.length, 1);
  const stop = await f.client.call("EMERGENCY", await f.base.stopMessage());
  assert.equal(stop.receipt.manifest.status, "DRAIN_PENDING");
});
test("independent journal capacity fails closed without deleting old nonces", async () => {
  const f = await fresh();
  f.root.change(
    (d) => (d.nonces = Array.from({ length: 2048 }, (_, i) => "spent-" + i)),
  );
  await deny(f, "SESSION", await f.base.ownerAdmission());
  assert.equal(f.root.read().nonces.length, 2048);
});

test(
  "authority client partition has a bounded deadline and no fallback",
  { timeout: 5000 },
  async (t) => {
    const f = await fresh();
    t.mock.timers.enable({ apis: ["setTimeout"] });
    let entered;
    const started = new Promise((r) => (entered = r));
    const client = f.makeClient({
      port: {
        synthetic: true,
        send: async () => {
          entered();
          return new Promise(() => {});
        },
      },
    });
    const p = client.call("SESSION", await f.base.ownerAdmission());
    await started;
    t.mock.timers.tick(20000);
    await assert.rejects(p, /AUTHORITY_TIMEOUT/);
    assert.equal(f.base.calls.length, 0);
  },
);

test("three-store administrator rollback remains an explicit unsupported qualification failure", async () => {
  const f = await fresh(),
    sql = f.base.storage.snapshot(),
    old = structuredClone(f.base.root),
    initial = f.initialRoot;
  const admission = await f.base.ownerAdmission(),
    s = await f.client.call("SESSION", admission);
  await f.client.call("EXECUTE", await f.base.message(s, 0));
  await f.client.call("EMERGENCY", await f.base.stopMessage());
  f.base.storage.restore(sql);
  Object.assign(f.base.root, old);
  // A privileged restore of ALL roots back to before the first boot is not independently fenced.
  f.storage.db
    .prepare(
      "UPDATE df_security_authority SET revision=?,document=? WHERE id=1",
    )
    .run(initial.revision, JSON.stringify(initial));
  f.base.runtime.witness = f.base.witness;
  const restarted = f.makeService();
  const client = f.makeClient({
    port: { synthetic: true, send: (m) => restarted.handle(m) },
  });
  const revived = await client.call("SESSION", admission);
  assert.equal(revived.status, "ISSUED");
  await client.call("EXECUTE", await f.base.message(revived, 0));
  assert.equal(
    f.base.calls.length,
    2,
    "restoring every trusted root revives authority; release MUST remain blocked",
  );
  const r = await f.base.worker.fetch(
    new Request("https://dispatcher.fixture.invalid/v1/dispatch"),
    {},
  );
  assert.equal(r.status, 503);
});
test("expired inner owner authority is rejected despite fresh workload identity", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission()),
    message = await f.base.message(s, 0);
  f.base.clock.now += 301;
  const certificate = await f.resignCertificate({
      ...f.certificate.manifest,
      issued_at: f.base.clock.now,
      expires_at: f.base.clock.now + 60,
    }),
    custody = await f.resignCustody({
      ...f.custody.manifest,
      issued_at: f.base.clock.now,
      expires_at: f.base.clock.now + 60,
    });
  const r = await f
    .makeClient({ certificate, custody })
    .call("EXECUTE", message);
  assert.equal(r.status, "DENIED");
  assert.equal(f.base.calls.length, 0);
});
test("concurrent distinct requests retain account-wide provider quota", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission(4));
  await Promise.all(
    Array.from({ length: 4 }, async (_, i) =>
      f.client.call("EXECUTE", await f.base.message(s, i)).catch(() => {}),
    ),
  );
  assert.ok(f.base.calls.length <= 3);
  assert.equal(f.root.read().nonce_count, 5);
});
test("independent epoch change invalidates current signed workload and owner authority", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  f.root.change((d) => (d.epoch = "0".repeat(64)));
  await deny(f, "EXECUTE", await f.base.message(s, 0));
});
test("unknown outcomes cannot restore consumed slots through fresh workload messages", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  f.base.provider.failure = true;
  await f.client.call("EXECUTE", await f.base.message(s, 0));
  await assert.rejects(f.client.call("EXECUTE", await f.base.message(s, 0)));
  assert.equal(f.base.calls.length, 1);
  assert.equal(f.root.read().leases.length, 1);
});

test("client never discloses transport exception contents", async () => {
  const f = await fresh();
  let error;
  try {
    await f
      .makeClient({
        port: {
          synthetic: true,
          send: () => {
            throw Error("private-transport-sentinel");
          },
        },
      })
      .call("SESSION", await f.base.ownerAdmission());
  } catch (e) {
    error = e;
  }
  assert.equal(error.message, "AUTHORITY_UNAVAILABLE_UNKNOWN");
});
test("workload proof requires possession of the certificate key", async () => {
  const f = await fresh(),
    other = await crypto.subtle.generateKey("Ed25519", false, [
      "sign",
      "verify",
    ]);
  await deny(f, "SESSION", await f.base.ownerAdmission(), {
    keys: { ...f.keys, workloadPrivate: other.privateKey },
  });
});
test("version floor advancement between reservation and final dispatch denies the request", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  f.base.onEvent("ADMITTED", () =>
    f.root.change((d) => (d.minimum_version = 2)),
  );
  await f.client.call("EXECUTE", await f.base.message(s, 0));
  assert.equal(f.base.calls.length, 0);
});
test("custody credential identity change before final dispatch denies the request", async () => {
  const f = await fresh(),
    s = await f.client.call("SESSION", await f.base.ownerAdmission());
  f.base.onEvent("ADMITTED", () =>
    f.root.change((d) => (d.credential_binding = "f".repeat(64))),
  );
  await f.client.call("EXECUTE", await f.base.message(s, 0));
  assert.equal(f.base.calls.length, 0);
});

test("service preserves all ten owner request slots without enlarging the quota", async () => {
  const f = await fresh();
  const s = await f.client.call("SESSION", await f.base.ownerAdmission(10));
  assert.equal(s.requests.length, 10);
  assert.equal(s.expires_at, f.base.clock.now + 300);
  assert.equal(f.base.calls.length, 0);
});

test("client refuses a signed lower emergency generation even at a newer root revision", async () => {
  const f = await fresh();
  await f.client.call("EMERGENCY", await f.base.stopMessage());
  f.port.send = async (input) => {
    const { canonical, hash } = await import("../protocol.mjs");
    return f.signResponse({
      protocol: "dg-authority-response-v1",
      nonce: input.authentication.manifest.nonce,
      request_hash: await hash(canonical(input)),
      epoch: f.base.config.epoch,
      generation: 1,
      root_revision: 1000,
      issued_at: f.base.clock.now,
      expires_at: f.base.clock.now + 30,
      result: { status: "ISSUED" },
    });
  };
  await assert.rejects(
    f.client.call("SESSION", await f.base.ownerAdmission()),
    /AUTHORITY_RESPONSE/,
  );
  assert.equal(f.base.calls.length, 0);
});
