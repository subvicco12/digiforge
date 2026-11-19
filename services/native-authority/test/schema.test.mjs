import test from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { PURPOSE } from '../protocol.mjs';
import { BINDING_FIELDS, bindingOf, validateRequest } from '../schema.mjs';

function request() {
  const b = Object.fromEntries(BINDING_FIELDS.map(k => [k, 'test-reference']));
  Object.assign(b, { deployment_digest: 'd'.repeat(64), compatibility_floor: '1.0.0', provider: 'printify', environment: 'test', integration_id: 123, credential_reference: 123, shop_id: 2, shop_key: 'personalized_pod' });
  return { ...b, version: '1.0.0', method: 'currentCheckpoint', message_purpose: PURPOSE.currentCheckpoint,
    request_id: randomBytes(32).toString('base64url'), challenge: randomBytes(32).toString('base64url'),
    method_body: { grant_reference: 'grant-1', expected_epoch: 'e'.repeat(64), expected_generation: 1 } };
}
test('the executable request schema binds all identities, exact method purpose and typed body', () => {
  const r = request(); assert.equal(validateRequest(r), r); assert.deepEqual(bindingOf(r), Object.fromEntries(BINDING_FIELDS.map(k => [k, r[k]])));
  for (const patch of [{ provider: 'other' }, { environment: 'sandbox' }, { shop_id: '2' }, { integration_id: '123' }, { credential_reference: '123' }, { credential_reference: 999 }, { shop_key: 'standard_pod' }, { algorithm: 'none' }, { request_id: 'caller-value' }, { message_purpose: PURPOSE.admitOperation }, { deployment_digest: 'x' }, { compatibility_floor: '0.0.0' }]) {
    assert.throws(() => validateRequest({ ...r, ...patch }));
  }
  for (const body of [{ ...r.method_body, expected_generation: '1' }, { ...r.method_body, unused: 1 }, { expected_generation: 1 }]) {
    assert.throws(() => validateRequest({ ...r, method_body: body }));
  }
});
