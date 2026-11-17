import test from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync } from 'node:crypto';
import { canonical, parseCanonical, sign, verify, signWitness, verifyWitness, digest } from '../protocol.mjs';

test('canonical messages reject duplicate keys, non-integer numbers and noncanonical encodings', () => {
  assert.equal(canonical({ b: 2, a: ['scope', 1] }), '{"a":["scope",1],"b":2}');
  for (const raw of ['{"a":1,"a":1}', '{ "a":1}', '{"a":1.5}', '{"a":9007199254740992}']) {
    assert.throws(() => parseCanonical(raw));
  }
  assert.deepEqual(parseCanonical('{"a":1}'), { a: 1 });
});

test('witness signatures have a fixed independent domain, even for the same key and bytes', () => {
  const pair = generateKeyPairSync('ed25519'); const message = { state: 'CURRENT' };
  const keys = new Map([['witness-1', pair.publicKey]]);
  const envelope = signWitness('witness.response.v1', message, pair.privateKey, 'witness-1');
  assert.deepEqual(verifyWitness('witness.response.v1', envelope, keys), message);
  assert.throws(() => verify('witness.response.v1', envelope, keys));
  assert.throws(() => verifyWitness('witness.response.v1', sign('witness.response.v1', message, pair.privateKey, 'witness-1'), keys));
});

test('actual Ed25519 signatures bind the domain, purpose and exact message bytes', () => {
  const pair = generateKeyPairSync('ed25519');
  const other = generateKeyPairSync('ed25519');
  const message = { version: '1.0.0', method: 'currentCheckpoint', challenge: 'test-challenge' };
  const signed = sign('checkpoint.request.v1', message, pair.privateKey, 'workload-1');
  assert.deepEqual(verify('checkpoint.request.v1', signed, new Map([['workload-1', pair.publicKey]])), message);
  assert.throws(() => verify('operation.request.v1', signed, new Map([['workload-1', pair.publicKey]])));
  assert.throws(() => verify('checkpoint.request.v1', signed, new Map([['workload-1', other.publicKey]])));
  assert.throws(() => verify('checkpoint.request.v1', { ...signed, message: { ...message, challenge: 'replaced' } }, new Map([['workload-1', pair.publicKey]])));
  assert.throws(() => verify('checkpoint.request.v1', { ...signed, algorithm: 'none' }, new Map([['workload-1', pair.publicKey]])));
  assert.equal(digest(message).length, 64);
});
