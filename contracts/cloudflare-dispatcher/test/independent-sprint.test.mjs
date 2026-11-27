import test from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { authorityFixture } from "./authority-support.mjs";
import { IndependentAuthorityService } from "../authority-service.mjs";

const probe = fileURLToPath(new URL("./independent-sprint-process.mjs", import.meta.url));

async function processFixture(t) {
  const dir = mkdtempSync(join(tmpdir(), "df-independent-sprint-"));
  t.after(() => rmSync(dir, { recursive: true, force: true }));
  const path = join(dir, "authority.sqlite");
  let external, partition = false;
  const f = await authorityFixture({
    serviceFactory({ runtime, root, ports, clock, synthetic, base }) {
      const invoke = (operation, ...args) => {
        if (partition) throw new Error("INDEPENDENT_PORT_UNAVAILABLE");
        const child = spawnSync(process.execPath, [probe, path], {
          input: JSON.stringify({ operation, args, clock: clock() }),
          encoding: "utf8", timeout: 3000, maxBuffer: 1048576,
        });
        if (child.error || child.status !== 0) throw new Error("INDEPENDENT_PORT_UNAVAILABLE");
        const reply = JSON.parse(child.stdout);
        if (!reply.ok) throw new Error(reply.code);
        return reply.value;
      };
      external = Object.fromEntries(
        ["initialize", "boot", "active", "read", "claim", "anchor", "lease", "complete", "stop", "fence"]
          .map(operation => [operation, (...args) => invoke(operation, ...args)]),
      );
      external.stopAndCrash = () => {
        const child = spawnSync(process.execPath, [probe, path], {
          input: JSON.stringify({ operation: "stop", args: [], clock: clock(), crashAfterCommit: true }),
          encoding: "utf8", timeout: 3000, maxBuffer: 1048576,
        });
        assert.equal(child.error, undefined);
        assert.equal(child.signal, "SIGKILL");
        assert.equal(child.stdout, "");
      };
      const local = root.read();
      external.initialize(base.config, local.anchor, local.workload_key);
      return new IndependentAuthorityService({ runtime, root: external, ports, clock, synthetic });
    },
  });
  t.after(() => { f.storage.db.close(); f.base.storage.db.close(); });
  return { ...f, external, partition: value => { partition = value; } };
}

test("external authority process survives local restore and keeps the consumed GET spent", async t => {
  const { base, client, external } = await processFixture(t);
  const s = await client.call("SESSION", await base.ownerAdmission(2));
  const older = base.storage.snapshot();
  const oldWitness = structuredClone(base.root);
  const request = await base.message(s, 0);
  const read = await client.call("EXECUTE", request);
  assert.equal(read.receipt?.manifest.status, "OBSERVED");
  assert.equal(base.calls.length, 1);
  const anchor = external.read().anchor;
  assert.ok(anchor.revision > 0);
  base.storage.restore(older);
  Object.assign(base.root, oldWitness);
  const denied = await client.call("EXECUTE", await base.message(s, 1));
  assert.equal(denied.status, "DENIED");
  assert.equal(denied.retry_permitted, false);
  await assert.rejects(client.call("EXECUTE", request), { code: "SCHEMA" });
  assert.equal(base.calls.length, 1);
  assert.deepEqual(external.read().anchor, anchor);
});

test("revocation committed by the external journal before claim stops the copied workload", async t => {
  const { base, client, external } = await processFixture(t);
  const s = await client.call("SESSION", await base.ownerAdmission());
  const next = await base.message(s, 0);
  const before = external.read();
  const stopped = external.stop();
  assert.equal(stopped.generation, before.generation + 1);
  assert.ok(stopped.stopped);
  await assert.rejects(client.call("EXECUTE", next), { code: "SCHEMA" });
  assert.equal(base.calls.length, 0);
  assert.deepEqual(external.read().leases, []);
});

test("external witness partition denies dispatch with no automatic retry", async t => {
  const { base, client, partition } = await processFixture(t);
  const s = await client.call("SESSION", await base.ownerAdmission());
  partition(true);
  await assert.rejects(client.call("EXECUTE", await base.message(s, 0)), { code: "SCHEMA" });
  assert.equal(base.calls.length, 0);
});

test("external STOP sees the in-flight durable lease and cannot falsely acknowledge drain", async t => {
  const { base, client, external } = await processFixture(t);
  const s = await client.call("SESSION", await base.ownerAdmission());
  let drain;
  base.provider.during = async () => {
    assert.equal(external.read().leases.length, 1);
    drain = await client.call("EMERGENCY", await base.stopMessage());
    assert.ok(external.read().stopped);
    assert.notEqual(drain.receipt?.manifest.status, "DRAIN_ACKNOWLEDGED");
  };
  const result = await client.call("EXECUTE", await base.message(s, 0));
  assert.ok(drain, "emergency occurred during the synthetic request");
  assert.notEqual(result.receipt?.manifest.status, "OBSERVED");
  assert.equal(result.retry_permitted, false);
  assert.equal(base.calls.length, 1);
});


test("process-backed admission refuses signed evidence of usable historical copies", async t => {
  const f = await processFixture(t);
  const historical = await f.resignCustody({
    ...f.custody.manifest,
    host_copies_invalidated: false,
    backup_copies_unusable: false,
  });
  await assert.rejects(f.makeClient({ custody: historical }).call("SESSION", await f.base.ownerAdmission()),
    { code: "SCHEMA" });
  assert.equal(f.external.read().nonce_count, 0);
  assert.equal(f.base.calls.length, 0);
});

test("crash after the external STOP commit does not restore old authority", async t => {
  const { base, client, external } = await processFixture(t);
  const session = await client.call("SESSION", await base.ownerAdmission());
  const request = await base.message(session, 0);
  const generation = external.read().generation;
  external.stopAndCrash();
  const resumed = external.read();
  assert.equal(resumed.generation, generation + 1);
  assert.equal(resumed.stopped, true);
  await assert.rejects(client.call("EXECUTE", request), { code: "SCHEMA" });
  assert.equal(base.calls.length, 0);
});
