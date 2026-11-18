import assert from 'node:assert/strict';
import { createHash, generateKeyPairSync, sign } from 'node:crypto';
import { test } from 'node:test';
import { authenticateProvenanceV1 } from '../provenance-contract.mjs';

const { publicKey, privateKey } = generateKeyPairSync('ed25519');
const pin = {
  key: publicKey,
  keySha256: createHash('sha256').update(publicKey.export({ type: 'spki', format: 'der' })).digest('hex'),
  sourceSha: 'a'.repeat(40), producer: 'fixture', businessId: 1, etsyShopId: 'etsy-shop',
};
const fixture = {
  business_id: 1, shop_id: 2, shop_key: 'personalized_pod', etsy_shop_id: 'etsy-shop',
  listing_id: 'listing', order_id: 'order', line_id: 'line', product_id: 'product',
  product_version: 1, artwork_source_hash: 'b'.repeat(64), snapshot_revision: 1,
  producer: pin.producer, source_sha: pin.sourceSha, issued_at: 100, expires_at: 140,
};
const proof = json => sign(null, Buffer.from('DF_ETSY_NATIVE_PROVENANCE_1\n' + json), privateKey).toString('base64url');
const inspect = (snapshot, now = 120) => {
  const json = JSON.stringify(snapshot);
  return authenticateProvenanceV1(json, proof(json), pin, now);
};

test('authentic evidence is immutable and supplies no transaction receipt', () => {
  const result = inspect(fixture);
  assert.ok(Object.isFrozen(result) && Object.isFrozen(result.snapshot));
  assert.equal(result.currentness_verified, false);
  assert.equal(result.external_execution_authorized, false);
  assert.equal(result.release_hold, true);
  assert.equal(result.receipt, null);
});

for (const field of ['listing_id', 'order_id', 'line_id', 'product_id', 'product_version', 'artwork_source_hash']) {
  test('denies substitution of ' + field, () => {
    assert.throws(() => authenticateProvenanceV1(JSON.stringify({ ...fixture, [field]: 'different' }),
      proof(JSON.stringify(fixture)), pin, 120), { code: 'provenance_signature' });
  });
}

test('denies signed wrong business, shop, or source', () => {
  for (const change of [{ business_id: 3 }, { shop_id: 3 }, { etsy_shop_id: 'other' }, { source_sha: 'b'.repeat(40) }]) {
    assert.throws(() => inspect({ ...fixture, ...change }), { code: 'provenance_scope' });
  }
});

test('denies expired and future evidence', () => {
  for (const now of [99, 140]) assert.throws(() => inspect(fixture, now), { code: 'provenance_time' });
});

test('denies caller-supplied buyer assertions and duplicate JSON fields', () => {
  assert.throws(() => inspect({ ...fixture, buyer_verified: true }), { code: 'provenance_shape' });
  const duplicate = '{"shop_id":2,' + JSON.stringify(fixture).slice(1);
  assert.throws(() => authenticateProvenanceV1(duplicate, proof(duplicate), pin, 120),
    { code: 'provenance_signature' });
});
