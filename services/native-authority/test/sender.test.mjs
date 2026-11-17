import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';
import { CustodySender } from '../sender.mjs';
import { AuthorityStore } from '../store.mjs';
import { digest } from '../protocol.mjs';
import { startProvider } from './sender-provider.mjs';

async function fixture(t, behavior = 'success', bodyPatch = {}, leaseSeconds = 120) {
  const dir = mkdtempSync(join(tmpdir(), 'df-custody-sender-')), dbPath = join(dir, 'authority.sqlite'), store = new AuthorityStore(dbPath);
  t.after(() => { store.close(); rmSync(dir, { recursive: true, force: true }); });
  const now = Math.floor(Date.now() / 1000);
  const binding = { authority_id: 'test-authority', installation_id: 'test-installation', owner_id: 'test-owner', owner_proof_reference: 'test-owner-proof', workload_id: 'test-workload', deployment_digest: digest('test-deployment'), compatibility_floor: '1.0.0', provider: 'printify', provider_account_reference: 'test-provider-account-not-shop-id', environment: 'test', integration_id: 123, credential_reference: 123, shop_id: 2, shop_key: 'personalized_pod' };
  store.bootstrap({ ...binding, witness_id: 'test-witness', root_set_digest: digest('test-root-set') }, now);
  const product = store.registerProduct({ product_identity: 'product-1', binding, core_reference: 'test-core-product' });
  const order = store.registerOrder({ order_identity: 'native-order-1', product_identity: product.product_identity, lines: [{ line_identity: 'line-1', quantity: 2 }], binding, core_reference: 'test-core-order' });
  store.registerGrant({ grant_reference: 'grant-1', binding, issued_at: now - 1, expires_at: now + leaseSeconds, revoked: false });
  const body = { external_id: 'external-provider-order-1', line_items: [{ product_id: 'saved-product-1', variant_id: 202, quantity: 2 }], ...bodyPatch };
  const operation_id = digest(randomBytes(32).toString('hex')), fingerprint = digest(body);
  store.registerApproval({ approval_reference: 'approval-1', approval_revision: 1, policy_revision: 1, operation_id, order_identity: order.order_identity, order_version: order.order_version, listing_identity: 'listing-1', product_identity: product.product_identity, product_version: product.product_version, expected_product_version: product.product_version, payload_fingerprint: fingerprint, lines: order.lines, binding });
  const s = store.state();
  const op = store.admit({ ...binding, version: '1.0.0', method: 'admitOperation', message_purpose: 'operation.request.v1', request_id: randomBytes(32).toString('base64url'), challenge: randomBytes(32).toString('base64url'), method_body: { grant_reference: 'grant-1', operation_id, payload_fingerprint: fingerprint, product_version: 1, expected_product_version: 1, approval_reference: 'approval-1', approval_revision: 1, policy_revision: 1, expected_epoch: s.epoch, expected_generation: s.generation } }, now);
  const secret = 'offline-test-' + randomBytes(32).toString('base64url');
  const provider = await startProvider(t, secret); provider.behavior = behavior;
  const payload = { method: 'POST', path: '/v1/shops/456/orders.json', provider_shop_id: '456', order_identity: op.order_identity, body, payload_fingerprint: fingerprint };
  provider.operations.set(operation_id, { op, payload });
  const credentials = new Map([[123, { secret, binding, provider_shop_id: '456' }]]), payloads = new Map([[operation_id, payload]]);
  const holds = { STOP: false, externalLock: false, releaseHold: false, automation: true };
  const options = { store, credentials, payloads, providerBaseUrl: provider.url, holds, timeoutMs: 250 };
  return { store, dbPath, op, provider, credentials, payloads, payload, holds, options, secret };
}

test('actual admitted allocation is durably SENDING before one authenticated HTTP request; replay never sends twice', async t => {
  const f = await fixture(t); let durableState;
  f.provider.onPost = () => { const independent = new AuthorityStore(f.dbPath); try { durableState = independent.operation(f.op.operation_id).state; } finally { independent.close(); } };
  const sender = new CustodySender(f.options), result = await sender.send(f.op.operation_id);
  assert.equal(durableState, 'SENDING'); assert.equal(result.state, 'SUCCEEDED'); assert.equal(result.operation_id, f.op.operation_id); assert.equal(result.allocation_id, f.op.allocation_id); assert.equal(result.generation, f.op.generation); assert.equal(result.external_execution_authorized, false);
  assert.equal(f.provider.requests.length, 1); assert.equal(f.provider.requests[0].authenticated, true);
  assert.ok(!JSON.stringify(result).includes(f.secret)); await assert.rejects(sender.send(f.op.operation_id)); assert.equal(f.provider.requests.length, 1);
});

test('default holds consume a local denial without opening provider HTTP', async t => {
  const f = await fixture(t); const { holds, ...options } = f.options;
  const result = await new CustodySender(options).send(f.op.operation_id);
  assert.equal(result.state, 'FAILED'); assert.equal(f.provider.requests.length, 0); await assert.rejects(new CustodySender(f.options).send(f.op.operation_id));
});

for (const hold of ['STOP', 'externalLock', 'releaseHold', 'automation']) test(`independent ${hold} hold denies despite the other three released conditions`, async t => {
  const f = await fixture(t); f.holds[hold] = hold === 'automation' ? false : true;
  assert.equal((await new CustodySender(f.options).send(f.op.operation_id)).state, 'FAILED'); assert.equal(f.provider.requests.length, 0);
});

test('holds and private credential changes at actual socket connect are checked before any request bytes', async t => {
  for (const change of ['hold', 'credential']) {
    const f = await fixture(t); f.provider.onConnect = () => { if (change === 'hold') f.holds.releaseHold = true; else f.credentials.get(123).provider_shop_id = '999'; };
    assert.equal((await new CustodySender(f.options).send(f.op.operation_id)).state, 'FAILED'); assert.equal(f.provider.requests.length, 0);
  }
});

for (const change of ['binding', 'payload', 'path', 'order', 'live-credential']) test(`private custody ${change} mismatch denies before any HTTP bytes`, async t => {
  const f = await fixture(t);
  if (change === 'binding') f.credentials.get(123).binding = { ...f.credentials.get(123).binding, owner_id: 'foreign-owner' };
  if (change === 'payload') f.payload.body.line_items[0].quantity = 3;
  if (change === 'path') f.payload.path = '/v1/shops/999/orders.json';
  if (change === 'order') f.payload.order_identity = 'foreign-native-order';
  if (change === 'live-credential') f.credentials.get(123).secret = 'production-looking-token';
  const result = await new CustodySender(f.options).send(f.op.operation_id);
  assert.equal(result.state, 'FAILED'); assert.equal(f.provider.requests.length, 0); assert.ok(!JSON.stringify(result).includes('production-looking-token'));
});

test('actual socket-connect revocation blocks request bytes; post-reply revocation makes HTTP200 UNKNOWN', async t => {
  const before = await fixture(t); let revoked = false;
  before.provider.onConnect = () => { if (!revoked) { revoked = true; before.store.emergency('before-bytes', 1, Math.floor(Date.now()/1000)); } };
  const denied = await new CustodySender(before.options).send(before.op.operation_id);
  assert.equal(revoked, true); assert.equal(denied.state, 'UNKNOWN'); assert.equal(before.provider.requests.length, 0);
  const after = await fixture(t); after.provider.onPost = () => after.store.emergency('after-bytes', 1, Math.floor(Date.now()/1000));
  const unknown = await new CustodySender(after.options).send(after.op.operation_id);
  assert.equal(unknown.state, 'UNKNOWN'); assert.equal(after.provider.requests.length, 1); await assert.rejects(new CustodySender(after.options).send(after.op.operation_id));
});

for (const behavior of ['redirect', 'oversized', 'invalid-json', 'wrong-id', 'wrong-external-id', 'timeout']) test(`provider ${behavior} is bounded/redacted UNKNOWN without a second request`, async t => {
  const f = await fixture(t, behavior); const result = await new CustodySender({ ...f.options, timeoutMs: behavior === 'timeout' ? 60 : 1000 }).send(f.op.operation_id);
  assert.equal(result.state, 'UNKNOWN'); assert.equal(f.provider.requests.length, 1); assert.ok(!JSON.stringify(result).includes('PRIVATE PROVIDER RESPONSE')); assert.ok(!JSON.stringify(result).includes(f.secret));
  assert.equal(result.evidence.failure_category, { redirect: 'provider_status_unknown', oversized: 'response_bound', 'invalid-json': 'provider_ack_invalid', 'wrong-id': 'provider_ack_invalid', 'wrong-external-id': 'provider_ack_identity', timeout: 'transport_timeout' }[behavior]);
});

test('approved but oversized payload is denied without transmitting it', async t => {
  const f = await fixture(t, 'success', { note: 'x'.repeat(70000) }); const result = await new CustodySender(f.options).send(f.op.operation_id);
  assert.equal(result.state, 'FAILED'); assert.equal(f.provider.requests.length, 0);
});

test('UNKNOWN readback requires authenticated exact operation/allocation/generation binding and does not free allocation', async t => {
  const f = await fixture(t, 'timeout'); const sender = new CustodySender({ ...f.options, timeoutMs: 60 });
  assert.equal((await sender.send(f.op.operation_id)).state, 'UNKNOWN');
  const record = await sender.reconcile(f.op.operation_id);
  assert.equal(record.outcome, 'SUCCEEDED'); assert.equal(record.allocation_id, f.op.allocation_id); assert.equal(record.generation, f.op.generation); assert.equal(record.owner_id, f.op.owner_id); assert.match(record.observation_sha256, /^[a-f0-9]{64}$/); assert.ok(Number.isSafeInteger(record.observed_at)); assert.equal(f.store.operation(f.op.operation_id).state, 'UNKNOWN');
  for (const patch of [{ allocation_id: 'foreign-allocation' }, { allocation_revision: 999 }, { operation_id: digest('foreign-operation') }, { generation: 999 }, { owner_id: 'foreign-owner' }, { provider_account_reference: 'foreign-provider-account' }, { payload_fingerprint: digest('foreign-payload') }, { outcome: 'PENDING' }, { extra: 'PRIVATE_PROVIDER_DATA' }]) { f.provider.recoveryPatch = patch; await assert.rejects(sender.reconcile(f.op.operation_id)); }
  assert.equal(f.store.operation(f.op.operation_id).state, 'UNKNOWN'); await assert.rejects(sender.send(f.op.operation_id)); assert.equal(f.provider.requests.length, 1);
});

test('external URLs and unqualified LIVE mode are rejected before networking', async t => {
  const f = await fixture(t);
  for (const url of ['http://example.com', 'http://127.0.0.1.evil.invalid', 'http://user:password@127.0.0.1', 'http://127.0.0.1/prefix']) assert.throws(() => new CustodySender({ ...f.options, providerBaseUrl: url }));
  assert.throws(() => new CustodySender({ ...f.options, mode: 'LIVE', providerBaseUrl: 'https://api.printify.com' })); assert.equal(f.provider.requests.length, 0);
});

test('a durable terminal write with a lost local acknowledgement is never automatically retried', async t => {
  const f = await fixture(t); let writes = 0; const finish = f.store.finish.bind(f.store);
  f.store.finish = (...args) => { writes++; finish(...args); throw new Error('synthetic terminal acknowledgement lost'); };
  await assert.rejects(new CustodySender(f.options).send(f.op.operation_id));
  assert.equal(writes, 1); assert.equal(f.store.operation(f.op.operation_id).state, 'SUCCEEDED'); assert.equal(f.provider.requests.length, 1);
});

test('an actual elapsed grant lease makes a bounded HTTP200 acknowledgement UNKNOWN', async t => {
  const f = await fixture(t, 'success', {}, 2); f.provider.delayMs = Math.max(1, f.op.expires_at * 1000 - Date.now() + 20);
  const result = await new CustodySender({ ...f.options, timeoutMs: 4000 }).send(f.op.operation_id);
  assert.equal(result.state, 'UNKNOWN'); assert.equal(f.provider.requests.length, 1); assert.equal(f.store.operation(f.op.operation_id).state, 'UNKNOWN');
});

test('private credential or payload withdrawal after request bytes makes HTTP200 UNKNOWN', async t => {
  for (const change of ['credential', 'payload']) {
    const f = await fixture(t); f.provider.onPost = () => { if (change === 'credential') f.credentials.delete(123); else f.payload.path = '/v1/shops/999/orders.json'; };
    const result = await new CustodySender(f.options).send(f.op.operation_id);
    assert.equal(result.state, 'UNKNOWN'); assert.equal(f.provider.requests.length, 1); assert.equal(f.store.operation(f.op.operation_id).state, 'UNKNOWN');
  }
});

test('in-place credential rotation during actual authenticated readback invalidates the observation', async t => {
  const f = await fixture(t, 'timeout'); const sender = new CustodySender({ ...f.options, timeoutMs: 60 });
  assert.equal((await sender.send(f.op.operation_id)).state, 'UNKNOWN');
  f.provider.onRead = () => { f.credentials.get(123).secret = 'offline-test-' + randomBytes(32).toString('base64url'); };
  await assert.rejects(sender.reconcile(f.op.operation_id)); assert.equal(f.store.operation(f.op.operation_id).state, 'UNKNOWN'); assert.equal(f.provider.requests.length, 1);
});

test('readback cannot replace a provider reference already observed in a bound acknowledgement', async t => {
  const f = await fixture(t); f.provider.onPost = () => f.store.emergency('after-observed-ack', 1, Math.floor(Date.now()/1000));
  const sender = new CustodySender(f.options); assert.equal((await sender.send(f.op.operation_id)).state, 'UNKNOWN');
  f.provider.recoveryPatch = { provider_reference: 'foreign-provider-order' };
  await assert.rejects(sender.reconcile(f.op.operation_id)); assert.equal(f.store.operation(f.op.operation_id).state, 'UNKNOWN');
});

test('an asynchronous facade over the actual durable authority store completes send and bound readback', async t => {
  for (const behavior of ['success', 'timeout']) {
    const f = await fixture(t, behavior); const methods = new Set(['state', 'operation', 'markSending', 'finish']);
    const asynchronousStore = new Proxy(f.store, { get(target, key) { return methods.has(key) ? async (...args) => { await Promise.resolve(); return target[key](...args); } : Reflect.get(target, key); } });
    const sender = new CustodySender({ ...f.options, store: asynchronousStore, timeoutMs: behavior === 'timeout' ? 60 : 1000 });
    const result = await sender.send(f.op.operation_id), expected = behavior === 'timeout' ? 'UNKNOWN' : 'SUCCEEDED';
    assert.equal(result.state, expected); assert.equal(f.store.operation(f.op.operation_id).state, expected);
    if (behavior === 'timeout') { const record = await sender.reconcile(f.op.operation_id); assert.equal(record.outcome, 'SUCCEEDED'); assert.equal(record.allocation_id, f.op.allocation_id); }
    assert.equal(f.provider.requests.length, 1);
  }
});
