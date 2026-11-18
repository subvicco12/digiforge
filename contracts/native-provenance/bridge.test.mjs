import assert from 'node:assert/strict';
import { createHash, generateKeyPairSync, sign } from 'node:crypto';
import { test } from 'node:test';
import { NativeProvenanceBridgeV1, NATIVE_PROVENANCE_PURPOSE } from './bridge.mjs';

const { publicKey, privateKey } = generateKeyPairSync('ed25519');
const sourceSha = 'a'.repeat(40);
const snapshot = {
  business_id: 1, shop_id: 2, shop_key: 'personalized_pod', etsy_shop_id: 'shop',
  listing_id: 'listing', order_id: 'order', line_id: 'line', product_id: 'product',
  product_version: 1, artwork_source_hash: 'b'.repeat(64), snapshot_revision: 1,
  producer: 'fixture', source_sha: sourceSha, issued_at: 100, expires_at: 140,
};
const options = {
  producer: 'fixture', sourceSha, publicKey, businessId: 1, etsyShopId: 'shop',
  publicKeySha256: createHash('sha256').update(publicKey.export({ type: 'spki', format: 'der' })).digest('hex'),
};
const bridge = new NativeProvenanceBridgeV1(options);
const bytes = value => JSON.stringify(value);
const signature = (value, purpose = NATIVE_PROVENANCE_PURPOSE) =>
  sign(null, Buffer.from(purpose + '\n' + bytes(value)), privateKey);
const inspect = (value = snapshot, proof = signature(value), now = 110) =>
  bridge.inspect(bytes(value), proof, now);

test('authenticated input remains held and replay inspection grants no authority', () => {
  const result = inspect();
  assert.equal(result.status, 'AUTHENTICATED_WRITER_FENCE_REQUIRED');
  assert.equal(result.native_write_authorized, false);
  assert.equal(result.external_execution_authorized, false);
  assert.equal(result.receipt, null);
  assert.equal(Object.isFrozen(result), true);
  assert.deepEqual(inspect(), result);
});

for (const field of ['listing_id', 'order_id', 'line_id', 'product_id', 'product_version', 'artwork_source_hash']) {
  test('signature denies substituted ' + field, () => {
    assert.equal(inspect({ ...snapshot, [field]: 'substituted' }, signature(snapshot)).status, 'UNAUTHENTICATED');
  });
}

test('rejects signed wrong scope and source pin', () => {
  for (const change of [{ business_id: 2 }, { shop_id: 3 }, { etsy_shop_id: 'other' }, { source_sha: 'c'.repeat(40) }]) {
    assert.equal(inspect({ ...snapshot, ...change }).status, 'SCOPE_DENIED');
  }
});

test('denies expired, future, excessively long and wrong-purpose proofs', () => {
  assert.equal(inspect(snapshot, signature(snapshot), 140).status, 'EXPIRED_OR_NOT_CURRENT');
  assert.equal(inspect(snapshot, signature(snapshot), 99).status, 'EXPIRED_OR_NOT_CURRENT');
  assert.equal(inspect({ ...snapshot, expires_at: 161 }).status, 'EXPIRED_OR_NOT_CURRENT');
  assert.equal(inspect(snapshot, signature(snapshot, 'other')).status, 'UNAUTHENTICATED');
});

test('fails closed on pin mismatch and unknown fields', () => {
  assert.throws(() => new NativeProvenanceBridgeV1({ ...options, publicKeySha256: '0'.repeat(64) }));
  assert.equal(inspect({ ...snapshot, buyer_verified: true }).status, 'INVALID_INPUT');
});
