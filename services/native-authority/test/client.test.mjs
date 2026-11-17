import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { generateKeyPairSync } from 'node:crypto';
import { AuthorityClient } from '../client.mjs';
import { canonical, parseCanonical, sign, signWitness, verify, PURPOSE } from '../protocol.mjs';
import { BINDING_FIELDS, validateRequest } from '../schema.mjs';

const binding = Object.fromEntries(BINDING_FIELDS.map(k => [k, 'component-reference']));
Object.assign(binding, { authority_id: 'component-authority', deployment_digest: 'd'.repeat(64), compatibility_floor: '1.0.0', provider: 'printify', environment: 'test', integration_id: 12, credential_reference: 12, shop_id: 2, shop_key: 'personalized_pod' });
const bodies = {
  currentCheckpoint: { grant_reference: 'component-grant', expected_epoch: 'e'.repeat(64), expected_generation: 1 },
  admitOperation: { grant_reference: 'component-grant', operation_id: 'a'.repeat(64), payload_fingerprint: 'b'.repeat(64), product_version: 1, expected_product_version: 1, approval_reference: 'component-approval', approval_revision: 1, policy_revision: 1, expected_epoch: 'e'.repeat(64), expected_generation: 1 },
  requestEmergency: { emergency_authority_reference: 'component-emergency-authority', emergency_event_id: 'component-event', scope_of_revocation: 'installation', expected_generation: 1 },
  readDrain: { emergency_event_id: 'component-event', generation: 1 },
  recoverInstallation: { independent_recovery_approval: 'component-recovery', previous_epoch: 'e'.repeat(64), expected_witness_head: 'c'.repeat(64), outstanding_lease_inventory_hash: 'f'.repeat(64) },
  rotateRoot: { independent_rotation_approval: 'component-rotation', previous_root_set_revision: 1, new_root_set_revision: 2, new_root_set_digest: 'f'.repeat(64), expected_witness_head: 'c'.repeat(64) },
};

/** Actual independently signed HTTP responses, not a native service/state/grant acceptance fixture. */
async function fixture(t, mutate = () => {}) {
  const workload = generateKeyPairSync('ed25519');
  const issuer = generateKeyPairSync('ed25519');
  const witness = generateKeyPairSync('ed25519');
  const attacker = generateKeyPairSync('ed25519');
  const seen = [];
  const server = createServer(async (req, res) => {
    try {
      let raw = '';
      for await (const chunk of req) raw += chunk;
      const envelope = parseCanonical(raw);
      const request = validateRequest(verify(PURPOSE[envelope.message.method], envelope, new Map([['component-workload-key', workload.publicKey]])));
      seen.push(request);
      assert.equal(req.method, 'POST'); assert.equal(req.url, '/rpc');
      const now = Math.floor(Date.now() / 1000);
      const state = request.method === 'rotateRoot' ? 'ROOT_ROTATED_HELD' : 'CURRENT';
      const base = { version: '1.0.0', request_bindings: request, authority_id: binding.authority_id, key_id: 'component-issuer-key', root_set_revision: request.method === 'rotateRoot' ? 2 : 1, recovery_epoch: 'f'.repeat(64), checkpoint_epoch: 'e'.repeat(64), generation: 1, recovery_revision: 1, issued_at: now, expires_at: now + 60, disposition: state, evidence_reference: 'component-response-evidence', witness_id: 'component-witness', witness_head: 'c'.repeat(64), witness_sequence: 1, result: { schema: 'DF_NATIVE_AUTHORITY_RESULT_1', method: request.method, state, operation: null, drain: null, reason: null, external_execution_authorized: false } };
      const nested = structuredClone({ ...base, key_id: 'component-witness-key' });
      const context = { base, nested, request, issuer, witness, attacker, status: 200, headers: { 'content-type': 'application/json' }, raw: null, hang: false };
      await mutate(context);
      if (context.hang) return;
      const inner = signWitness('witness.response.v1', nested, context.witnessSigner ?? witness.privateKey, context.witnessKeyId ?? 'component-witness-key');
      if (context.innerMutation) context.innerMutation(inner);
      const outer = sign('issuer.response.v1', { ...base, witness_attestation: inner }, context.issuerSigner ?? issuer.privateKey, context.issuerKeyId ?? 'component-issuer-key');
      if (context.outerMutation) context.outerMutation(outer);
      res.writeHead(context.status, context.headers);
      if (context.chunked) { for (const chunk of context.chunked) res.write(chunk); res.end(); }
      else res.end(context.raw ?? canonical(outer));
    } catch (error) {
      res.writeHead(500, { 'content-type': 'application/json' }); res.end(canonical({ component_error: error.code ?? error.message }));
    }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  t.after(() => new Promise(resolve => { server.closeAllConnections(); server.close(resolve); }));
  const issuerKeys = new Map([['component-issuer-key', issuer.publicKey]]);
  const witnessKeys = new Map([['component-witness-key', witness.publicKey]]);
  const config = { url: `http://127.0.0.1:${server.address().port}`, binding: structuredClone(binding), keyId: 'component-workload-key', privateKey: workload.privateKey, issuerKeys, witnessKeys };
  return { config, client: new AuthorityClient(config), seen, server };
}

function mirror(context, change) { change(context.base); change(context.nested); }

test('client sends all six actual signed method purposes and verifies two independent signatures', async t => {
  const f = await fixture(t);
  for (const [method, body] of Object.entries(bodies)) {
    const result = await f.client.call(method, body);
    assert.equal(result.request_bindings.method, method);
    assert.equal(result.result.external_execution_authorized, false);
    assert.equal(result.witness_attestation.key_id, 'component-witness-key');
  }
  assert.equal(f.seen.length, 6);
});

test('each HTTP call has new independent cryptographic request and challenge tokens', async t => {
  const f = await fixture(t);
  await f.client.call('currentCheckpoint', bodies.currentCheckpoint);
  await f.client.call('currentCheckpoint', bodies.currentCheckpoint);
  assert.equal(new Set(f.seen.flatMap(x => [x.request_id, x.challenge])).size, 4);
  let recorded;
  const replay = await fixture(t, c => {
    c.outerMutation = envelope => { if (recorded) c.raw = recorded; else recorded = canonical(envelope); };
  });
  await replay.client.call('currentCheckpoint', bodies.currentCheckpoint);
  await assert.rejects(replay.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'response_request_binding' });
});

test('forged issuer signature is denied', async t => {
  const f = await fixture(t, c => { c.issuerSigner = c.attacker.privateKey; });
  await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'signature_invalid' });
});

test('a valid issuer cannot certify a forged witness signature', async t => {
  const f = await fixture(t, c => { c.witnessSigner = c.attacker.privateKey; });
  await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'signature_invalid' });
});

test('issuer signature cannot be substituted for the independent witness', async t => {
  const f = await fixture(t, c => { c.witnessSigner = c.issuer.privateKey; c.witnessKeyId = 'component-issuer-key'; });
  await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'untrusted_key' });
  const wrongDomain = await fixture(t, c => {
    c.innerMutation = envelope => { envelope.signature = sign('witness.response.v1', envelope.message, c.witness.privateKey, 'component-witness-key').signature; };
  });
  await assert.rejects(wrongDomain.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'signature_invalid' });
});

test('signed wrong challenge or method cannot bind the original HTTP request', async t => {
  for (const field of ['challenge', 'method']) {
    const f = await fixture(t, c => mirror(c, m => { m.request_bindings[field] = field === 'method' ? 'admitOperation' : 'x'.repeat(43); }));
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'response_request_binding' });
  }
});

test('all fourteen signed physical and canonical identity bindings are exact', async t => {
  for (const field of BINDING_FIELDS) {
    const f = await fixture(t, c => mirror(c, m => { m.request_bindings[field] = typeof m.request_bindings[field] === 'number' ? m.request_bindings[field] + 1 : m.request_bindings[field] + '-foreign'; }));
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'response_request_binding' });
  }
});

test('expired future-issued and overlong lifetimes deny even correctly signed evidence', async t => {
  const now = Math.floor(Date.now() / 1000);
  for (const times of [{ issued_at: now - 60, expires_at: now - 1 }, { issued_at: now + 10, expires_at: now + 20 }, { issued_at: now, expires_at: now + 301 }]) {
    const f = await fixture(t, c => mirror(c, m => Object.assign(m, times)));
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'response_expired' });
  }
});

test('stale or unconfigured root revision is denied', async t => {
  const f = await fixture(t, c => mirror(c, m => { m.root_set_revision = 2; }));
  await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'response_revision' });
  const deniedRotation = await fixture(t, c => mirror(c, m => { m.root_set_revision = 1; m.disposition = 'DENIED'; m.result.state = 'DENIED'; m.result.reason = 'control_consumed'; }));
  assert.equal((await deniedRotation.client.call('rotateRoot', bodies.rotateRoot)).disposition, 'DENIED');
});

test('signed issuer and signed witness cannot disagree on results epochs or witness head', async t => {
  for (const field of ['result', 'checkpoint_epoch', 'generation', 'witness_head', 'witness_sequence']) {
    const f = await fixture(t, c => { c.nested[field] = field === 'result' ? { ...c.nested.result, reason: 'component-other-reason' } : typeof c.nested[field] === 'number' ? 2 : 'a'.repeat(64); });
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'witness_response_mismatch' });
  }
});

test('issuer and witness identities must match configured signers and authority', async t => {
  for (const mutate of [c => { c.base.key_id = 'another-issuer'; }, c => { c.nested.key_id = 'another-witness'; }, c => mirror(c, m => { m.authority_id = 'foreign-authority'; })]) {
    const f = await fixture(t, mutate);
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint));
  }
});

test('unknown envelope message nested and result fields cannot establish new authority', async t => {
  for (const mutate of [c => { c.outerMutation = e => { e.public_key = 'untrusted'; }; }, c => { c.base.unrecognized = true; }, c => { c.nested.unrecognized = true; }, c => mirror(c, m => { m.result.authorization = true; })]) {
    const f = await fixture(t, mutate);
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint));
  }
});

test('noncanonical or invalid UTF8 wire bytes deny otherwise valid signed content', async t => {
  for (const [prefix, code] of [[Buffer.from([239,187,191]), 'invalid_json'], [Buffer.from(' '), 'noncanonical_message'], [Buffer.from([255]), 'invalid_utf8']]) {
    const f = await fixture(t, c => { c.outerMutation = envelope => { c.raw = Buffer.concat([prefix, Buffer.from(canonical(envelope))]); }; });
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code });
  }
});

test('redirect responses are never followed', async t => {
  const f = await fixture(t, c => { c.status = 302; c.headers.location = 'http://127.0.0.1:1/never'; });
  await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'authority_http_status' });
  assert.equal(f.seen.length, 1);
});

test('declared and streamed responses exceeding64KiB deny', async t => {
  for (const mutate of [c => { c.headers['content-length'] = '65537'; c.raw = 'x'.repeat(65537); }, c => { c.chunked = ['x'.repeat(40000), 'x'.repeat(40000)]; }]) {
    const f = await fixture(t, mutate);
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'message_bound' });
  }
});

test('unavailable authority never falls back to a previously verified response', async t => {
  const f = await fixture(t);
  await f.client.call('currentCheckpoint', bodies.currentCheckpoint);
  await new Promise(resolve => f.server.close(resolve));
  await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'authority_unreachable' });
});

test('total response timeout denies without a fallback', async t => {
  const f = await fixture(t, c => { c.hang = true; });
  const client = new AuthorityClient({ ...f.config, timeoutMs: 50 });
  await assert.rejects(client.call('currentCheckpoint', bodies.currentCheckpoint), { code: 'authority_timeout' });
});

test('untyped body and unknown method fail before any HTTP call', async t => {
  const f = await fixture(t);
  await assert.rejects(f.client.call('currentCheckpoint', { ...bodies.currentCheckpoint, caller_extra: true }));
  await assert.rejects(f.client.call('unknown', {}));
  assert.equal(f.seen.length, 0);
});

test('caller mutations cannot replace the configured binding or pinned keys', async t => {
  const f = await fixture(t);
  f.config.binding.shop_id = 99;
  f.config.issuerKeys.clear(); f.config.witnessKeys.clear();
  const result = await f.client.call('currentCheckpoint', bodies.currentCheckpoint);
  assert.equal(result.request_bindings.shop_id, 2);
});

test('one public key cannot stand in for independent issuer and witness configuration', async t => {
  const f = await fixture(t);
  assert.throws(() => new AuthorityClient({ ...f.config, witnessKeys: new Map([['component-witness-key', f.config.issuerKeys.get('component-issuer-key')]]) }), { code: 'independent_witness_key_required' });
});

test('result method state execution flag and operation/drain field contracts remain strict', async t => {
  for (const mutate of [m => { m.result.method = 'admitOperation'; }, m => { m.result.state = 'SUCCEEDED'; }, m => { m.result.external_execution_authorized = true; }, m => { m.result.operation = { operation_id: 'a'.repeat(64) }; }, m => { m.result.drain = { state: 'DRAINED', pending_operations: 1 }; }]) {
    const f = await fixture(t, c => mirror(c, mutate));
    await assert.rejects(f.client.call('currentCheckpoint', bodies.currentCheckpoint));
  }
});
