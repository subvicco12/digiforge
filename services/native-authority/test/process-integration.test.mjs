import test from 'node:test';
import assert from 'node:assert/strict';
import { createProcessFixture, offlineReleasedHolds, witnessBarrier, jsonPost } from './process-fixtures.mjs';
import { generateKeyPairSync, randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { DatabaseSync, backup } from 'node:sqlite';
import { digest, sign, PURPOSE } from '../protocol.mjs';

const emergency = f => f.client('emergency').call('requestEmergency', { emergency_authority_reference: 'emergency', emergency_event_id: 'offline-emergency', scope_of_revocation: 'installation', expected_generation: f.state().generation });

test('N01 actual issuer and independent witness processes sign one exact current checkpoint', async t => {
  const f = await createProcessFixture(t);
  assert.notEqual(f.processes.get('issuer').child.pid, f.processes.get('witness').child.pid);
  assert.notEqual(f.processes.get('issuer').child.pid, process.pid);
  assert.equal(f.configs.witness.credentials_path, null);
  assert.equal(f.configs.witness.payloads_path, null);
  assert.equal(f.configs.issuer.database_path, null);
  assert.equal(f.assertPrivatePermissions(), true);
  const response = await f.client().call('currentCheckpoint', f.checkpointBody());
  assert.equal(response.disposition, 'CURRENT');
  assert.equal(response.authority_id, f.binding.authority_id);
  assert.equal(response.witness_attestation.key_id, 'witness');
  assert.equal(response.checkpoint_epoch, f.state().epoch);
  assert.equal(response.witness_head, f.state().witness_head);
  assert.equal(response.result.external_execution_authorized, false);
  assert.equal(f.requests.length, 0);
});

test('N03 two actual witness SQLite connections contend for one allocation and only one sends', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds });
  await f.launch('witness', 'witness-second');
  f.configs.issuer.witness_url = f.processes.get('witness-second').address;
  await f.launch('issuer', 'issuer-second');
  const body = f.admissionBody();
  const results = await Promise.all([f.client().call('admitOperation', body), f.client('workload', { url: f.processes.get('issuer-second').address }).call('admitOperation', body)]);
  assert.deepEqual(results.map(r => r.disposition).sort(), ['DENIED', 'SUCCEEDED']);
  assert.equal(f.requests.filter(r => r.method === 'POST').length, 1);
  assert.equal(f.operation().state, 'SUCCEEDED');
});

test('N04 duplicate operation and stale actual native product version cannot redispatch', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds });
  const body = f.admissionBody();
  assert.equal((await f.client().call('admitOperation', body)).disposition, 'SUCCEEDED');
  assert.equal((await f.client().call('admitOperation', body)).disposition, 'DENIED');
  const stale = await createProcessFixture(t, { holds: offlineReleasedHolds });
  assert.equal((await stale.control('ADVANCE_PRODUCT', { product_identity: stale.productId, expected_version: 1, core_reference: 'offline-revised-product' })).response.status, 200);
  assert.equal((await stale.client().call('admitOperation', stale.admissionBody())).disposition, 'DENIED');
  assert.equal(stale.requests.length, 0);
  assert.equal(f.requests.length, 1);
  await assert.rejects(f.client('owner').call('currentCheckpoint', f.checkpointBody()));
});

test('N05 actual emergency before custody admission prevents credentialed provider HTTP', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds });
  const proxy = await witnessBarrier(t, f.processes.get('witness').address, (url, e) => url === '/dispatch' && e.message.action === 'operation');
  await f.stop('issuer'); f.configs.issuer.witness_url = proxy.url; await f.launch('issuer');
  const admitted = f.client('workload', { timeoutMs: 5000 }).call('admitOperation', f.admissionBody());
  await proxy.reached;
  const revoked = await emergency(f);
  assert.equal(revoked.disposition, 'PENDING');
  proxy.release();
  await assert.rejects(admitted, { code: 'authority_http_status' });
  assert.equal(f.operation().state, 'ADMITTED');
  assert.equal(f.requests.length, 0);
  assert.equal(f.state().paused, true);
});

test('Core authenticates the original committed allocation after lost acknowledgement and STOP', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds });
  const retained = f.state();
  const body = f.admissionBody();
  const correlation = { request_id: randomBytes(32).toString('base64url'), challenge: randomBytes(32).toString('base64url') };
  const proxy = await witnessBarrier(t, f.processes.get('witness').address, (url, e) => url === '/dispatch' && e.message.action === 'operation');
  await f.stop('issuer');
  f.configs.issuer.witness_url = proxy.url;
  await f.launch('issuer');
  const started = f.client('workload', { timeoutMs: 5000 }).call('admitOperation', body, correlation);
  await proxy.reached;
  const rollback = join(f.dirs.witness,'pre-stop-snapshot.sqlite');
  const source = new DatabaseSync(f.databasePath, { readOnly: true });
  try { await backup(source,rollback); } finally { source.close(); }
  const stopped = await emergency(f);
  assert.equal(stopped.disposition, 'PENDING');
  proxy.release();
  await assert.rejects(started, { code: 'authority_http_status' });
  assert.equal(f.requests.length, 0);

  await f.crash('witness');
  await f.stop('issuer');
  await f.launch('witness');
  f.configs.issuer.witness_url = f.processes.get('witness').address;
  await f.launch('issuer');

  const read = { operation_id: body.operation_id, payload_fingerprint: body.payload_fingerprint,
    original_request_id: correlation.request_id, original_challenge: correlation.challenge,
    expected_epoch: body.expected_epoch, expected_generation: body.expected_generation,
    retained_witness_head: retained.witness_head, retained_witness_sequence: retained.witness_sequence };
  const observed = await f.client().call('readOperation', read);
  assert.equal(observed.disposition, 'ADMITTED');
  assert.equal(observed.result.external_execution_authorized, false);
  assert.equal(observed.generation, stopped.generation);
  assert.equal(observed.result.operation.allocation_id, f.operation().allocation_id);
  assert.ok(observed.result.operation.admission_event.sequence <= observed.witness_sequence);
  assert.match(observed.result.operation.admission_event.head, /^[a-f0-9]{64}$/);
  for (const patch of [
    { original_challenge: randomBytes(32).toString('base64url') },
    { payload_fingerprint: digest('foreign payload') },
    { expected_generation: stopped.generation },
    { retained_witness_head: digest('stale frontier') },
    { retained_witness_sequence: observed.witness_sequence + 1 },
  ]) {
    const denied = await f.client().call('readOperation', { ...read, ...patch });
    assert.equal(denied.disposition, 'DENIED');
    assert.equal(denied.result.operation, null);
  }
  await assert.rejects(f.client('owner').call('readOperation',read));
  await f.stop('issuer');
  await f.stop('witness');
  f.configs.witness.database_path = rollback;
  await f.launch('witness');
  f.configs.issuer.witness_url = f.processes.get('witness').address;
  await f.launch('issuer');
  const restored = await f.client().call('readOperation', {
    ...read, retained_witness_head: observed.witness_head, retained_witness_sequence: observed.witness_sequence,
  });
  assert.equal(restored.disposition, 'DENIED');
  assert.equal(restored.result.reason, 'witness_frontier_mismatch');
  assert.equal(restored.result.operation, null);
  assert.equal(f.requests.length, 0);
});

test('N06 emergency durably changes generation while in-flight bytes keep drain pending', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds, providerBehavior: 'hold' });
  const generation = f.state().generation;
  const sent = f.client('workload', { timeoutMs: 5000 }).call('admitOperation', f.admissionBody());
  await f.firstRequest;
  const ack = await emergency(f);
  assert.equal(ack.disposition, 'PENDING');
  assert.equal(f.state().generation, generation + 1);
  assert.equal(f.operation().state, 'SENDING');
  f.releaseProvider();
  assert.equal((await sent).disposition, 'UNKNOWN');
  assert.equal((await f.client('emergency').call('readDrain', { emergency_event_id: 'offline-emergency', generation: f.state().generation })).disposition, 'PENDING');
  assert.equal(f.requests.length, 1);
});

test('N07 actual issuer death resolves only authenticated unsent journal and recovery remains held without rearm', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds });
  const proxy = await witnessBarrier(t, f.processes.get('witness').address, (url, e) => url === '/witness' && e.message.request_envelope.message.method === 'admitOperation', 'after');
  await f.stop('issuer'); f.configs.issuer.witness_url = proxy.url; await f.launch('issuer');
  const pending = f.client().call('admitOperation', f.admissionBody()).catch(e => e);
  await proxy.reached;
  assert.equal(f.operation().state, 'ADMITTED');
  await f.crash('issuer'); proxy.release(); await pending;
  assert.equal(f.requests.length, 0);
  f.configs.issuer.witness_url = f.processes.get('witness').address; await f.launch('issuer');
  assert.equal((await f.client().call('admitOperation', f.admissionBody())).disposition, 'DENIED');
  assert.equal(f.operation().state, 'ADMITTED');
  const state = f.state();
  const data = { previous_epoch: state.epoch, expected_witness_head: state.witness_head, outstanding_lease_inventory_hash: state.inventory_hash };
  const recovery = await f.control('RECOVERY_APPROVAL', data);
  assert.equal(recovery.response.status, 200);
  assert.equal((await f.client('recovery').call('recoverInstallation', { independent_recovery_approval: recovery.record.reference, ...data })).disposition, 'DENIED');
  const original = f.operation();
  assert.equal((await emergency(f)).disposition, 'PENDING');
  assert.equal((await f.client().call('admitOperation', f.admissionBody())).disposition, 'DENIED');
  const abandon = { operation_id: f.operationId, allocation_id: original.allocation_id, allocation_revision: original.allocation_revision, generation: original.generation, expected_witness_head: f.state().witness_head };
  const wrong = await f.control('ABANDON_UNSENT', { ...abandon, allocation_revision: original.allocation_revision + 1 });
  assert.notEqual(wrong.response.status, 200);
  assert.equal(f.operation().state, 'ADMITTED');
  const resolved = await f.control('ABANDON_UNSENT', abandon);
  assert.equal(resolved.response.status, 200);
  assert.equal(resolved.response.body.state, 'FAILED');
  assert.equal(resolved.response.body.external_execution_authorized, false);
  assert.equal(f.operation().state, 'FAILED');
  assert.deepEqual(f.operation().evidence, { failure_category: 'revoked_before_send' });
  assert.equal(f.operation().allocation_id, original.allocation_id);
  assert.equal(f.operation().allocation_revision, original.allocation_revision);
  assert.equal(f.records().approvals[0].consumed, 1);
  assert.notEqual((await jsonPost(new URL('/control', f.processes.get('witness').address), resolved.envelope)).status, 200);
  const after = f.state();
  assert.equal(after.unresolved_operations, 0);
  assert.equal(after.inventory_hash, digest([]));
  assert.equal((await f.client('emergency').call('readDrain', { emergency_event_id: 'offline-emergency', generation: after.generation })).disposition, 'DRAINED');
  const nextData = { previous_epoch: after.epoch, expected_witness_head: after.witness_head, outstanding_lease_inventory_hash: after.inventory_hash };
  const approved = await f.control('RECOVERY_APPROVAL', nextData);
  assert.equal(approved.response.status, 200);
  const recovered = await f.client('recovery').call('recoverInstallation', { independent_recovery_approval: approved.record.reference, ...nextData });
  assert.equal(recovered.disposition, 'RECOVERED_HELD');
  assert.equal(f.state().paused, true);
  assert.equal((await f.client().call('admitOperation', f.admissionBody())).disposition, 'DENIED');
  assert.equal(f.operation().state, 'FAILED');
  assert.equal(f.records().approvals[0].consumed, 1);
  assert.equal(f.requests.length, 0);
});

test('N08 lost provider acknowledgement consumes UNKNOWN until authenticated readback without retry', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds, providerBehavior: 'disconnect' });
  assert.equal((await f.client().call('admitOperation', f.admissionBody())).disposition, 'UNKNOWN');
  assert.equal(f.operation().state, 'UNKNOWN');
  assert.equal((await f.client().call('admitOperation', f.admissionBody())).disposition, 'DENIED');
  const reconciled = await f.control('RECONCILE_OPERATION', { operation_id: f.operationId }, { role: 'issuer' });
  assert.equal(reconciled.response.status, 200);
  assert.equal(f.operation().state, 'SUCCEEDED');
  assert.equal(f.requests.filter(r => r.method === 'POST').length, 1);
  assert.equal(f.requests.filter(r => r.method === 'GET').length, 1);
  assert.equal(f.requests.every(r => r.authenticated), true);
});

test('N09 restarting old sender configuration cannot undo independently persisted emergency generation', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds });
  const old = f.admissionBody();
  assert.equal((await emergency(f)).disposition, 'DRAINED');
  await f.stop('issuer'); await f.launch('issuer');
  assert.equal((await f.client().call('currentCheckpoint', f.checkpointBody())).disposition, 'HELD');
  assert.equal((await f.client().call('admitOperation', old)).disposition, 'DENIED');
  assert.equal(f.requests.length, 0);
});

test('N10 actual dual-approved root rotation and reprovision restart remain held and reject old issuer pin', async t => {
  const f = await createProcessFixture(t);
  const next = generateKeyPairSync('ed25519');
  const digestNext = digest({ 'issuer-next': next.publicKey.export({ type: 'spki', format: 'pem' }) });
  const data = { previous_root_set_revision: 1, new_root_set_revision: 2, new_root_set_digest: digestNext, expected_witness_head: f.state().witness_head };
  const approved = await f.control('ROTATION_APPROVAL', data);
  assert.equal(approved.response.status, 200);
  const oldPins = new Map(Object.entries(f.publicMaps.issuer));
  const rotated = await f.client('recovery').call('rotateRoot', { independent_rotation_approval: approved.record.reference, ...data });
  assert.equal(rotated.disposition, 'ROOT_ROTATED_HELD');
  assert.equal(rotated.root_set_revision, 2);
  await f.reprovisionIssuerRoot(next);
  const current = await f.client().call('currentCheckpoint', f.checkpointBody());
  assert.equal(current.disposition, 'HELD');
  assert.equal(current.root_set_revision, 2);
  await assert.rejects(f.client('workload', { issuerKeys: oldPins }).call('currentCheckpoint', f.checkpointBody()));
  assert.equal(f.requests.length, 0);
});

test('N11 actual signed cross-account and cross-shop requests cannot allocate or produce provider evidence', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds });
  for (const altered of [{ provider_account_reference: 'foreign-account' }, { integration_id: 38, credential_reference: 38 }]) {
    await assert.rejects(f.client('workload', { binding: { ...f.binding, ...altered } }).call('admitOperation', f.admissionBody()));
  }
  const foreignShop = { ...f.binding, shop_id: 3, version: '1.0.0', method: 'admitOperation', message_purpose: PURPOSE.admitOperation, request_id: randomBytes(32).toString('hex'), challenge: randomBytes(32).toString('hex'), method_body: f.admissionBody() };
  assert.notEqual((await jsonPost(new URL('/rpc', f.processes.get('issuer').address), sign(PURPOSE.admitOperation, foreignShop, readFileSync(f.signingPaths.workload), 'workload'))).status, 200);
  assert.throws(() => f.operation(), { code: 'operation_missing' });
  assert.equal(f.requests.length, 0);
});

test('N12 unsupported wire version and unavailable actual witness fail closed without cached success', async t => {
  const f = await createProcessFixture(t);
  assert.equal((await f.client().call('currentCheckpoint', f.checkpointBody())).disposition, 'CURRENT');
  const request = { ...f.binding, version: '9.0.0', method: 'currentCheckpoint', message_purpose: PURPOSE.currentCheckpoint, request_id: randomBytes(32).toString('hex'), challenge: randomBytes(32).toString('hex'), method_body: f.checkpointBody() };
  assert.notEqual((await jsonPost(new URL('/rpc', f.processes.get('issuer').address), sign(PURPOSE.currentCheckpoint, request, readFileSync(f.signingPaths.workload), 'workload'))).status, 200);
  await f.stop('witness');
  await assert.rejects(f.client().call('currentCheckpoint', f.checkpointBody()));
  assert.equal(f.requests.length, 0);
});

test('N02 actual native registry allocation and credential custody send once to the isolated offline provider', async t => {
  const f = await createProcessFixture(t, { holds: offlineReleasedHolds });
  const response = await f.client().call('admitOperation', f.admissionBody());
  assert.equal(response.disposition, 'SUCCEEDED');
  assert.equal(response.result.operation.product_identity, f.productId);
  assert.equal(response.result.operation.product_version, 1);
  assert.equal(response.result.operation.order_identity, f.orderId);
  assert.equal(response.result.operation.order_version, 1);
  assert.equal(response.result.operation.operation_id, f.operationId);
  assert.equal(response.result.operation.allocation_id, f.operation().allocation_id);
  assert.equal(f.operation().state, 'SUCCEEDED');
  assert.equal(f.requests.length, 1);
  assert.equal(f.requests[0].authenticated, true);
  assert.equal(response.result.external_execution_authorized, false);
  assert.equal(JSON.stringify(response).includes(f.secret), false);
});
