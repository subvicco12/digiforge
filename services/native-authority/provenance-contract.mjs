import { createHash, verify } from 'node:crypto';
import { requireAuthority as need } from './errors.mjs';

export const HELD_PROVENANCE_VERSION = '1.0.0';
const purpose = 'DF_ETSY_NATIVE_PROVENANCE_1';
const fields = Object.freeze([
  'business_id', 'shop_id', 'shop_key', 'etsy_shop_id', 'listing_id', 'order_id',
  'line_id', 'product_id', 'product_version', 'artwork_source_hash', 'snapshot_revision',
  'producer', 'source_sha', 'issued_at', 'expires_at',
]);
const positive = value => Number.isSafeInteger(value) && value > 0;
const reference = value => typeof value === 'string' && /^[A-Za-z0-9._:-]{1,128}$/.test(value);
const hex = (value, length) => typeof value === 'string' && value.length === length
  && /^[a-f0-9]+$/.test(value);
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');

/** Offline evidence validation. The producer pin must come from trusted configuration.
 * The result has no currentness, CAS, receipt or dispatch authority.
 */
export function authenticateProvenanceV1(json, signature, pin, now) {
  need(typeof json === 'string' && Buffer.byteLength(json) <= 4096, 'provenance_size');
  need(typeof signature === 'string' && /^[A-Za-z0-9_-]{86}$/.test(signature), 'provenance_signature');
  const proof = Buffer.from(signature, 'base64url');
  need(proof.length === 64 && proof.toString('base64url') === signature, 'provenance_signature');
  need(pin?.key?.type === 'public' && pin.key.asymmetricKeyType === 'ed25519'
    && hex(pin.sourceSha, 40) && reference(pin.producer)
    && sha256(pin.key.export({ type: 'spki', format: 'der' })) === pin.keySha256,
  'provenance_pin');
  let snapshot;
  try { snapshot = JSON.parse(json); } catch { need(false, 'provenance_shape'); }
  need(snapshot && Object.getPrototypeOf(snapshot) === Object.prototype
    && Object.keys(snapshot).length === fields.length
    && fields.every(k => Object.hasOwn(snapshot, k) && ['string', 'number'].includes(typeof snapshot[k])),
  'provenance_shape');
  const bytes = JSON.stringify(Object.fromEntries(fields.map(k => [k, snapshot[k]])));
  need(bytes === json && verify(null, Buffer.from(purpose + '\n' + bytes), pin.key, proof),
    'provenance_signature');
  need(snapshot.producer === pin.producer && snapshot.source_sha === pin.sourceSha
    && positive(pin.businessId) && snapshot.business_id === pin.businessId
    && reference(pin.etsyShopId) && snapshot.etsy_shop_id === pin.etsyShopId
    && snapshot.shop_id === 2 && snapshot.shop_key === 'personalized_pod', 'provenance_scope');
  need(['listing_id', 'order_id', 'line_id', 'product_id'].every(k => reference(snapshot[k]))
    && positive(snapshot.product_version) && positive(snapshot.snapshot_revision)
    && hex(snapshot.artwork_source_hash, 64), 'provenance_shape');
  need(positive(now) && positive(snapshot.issued_at) && positive(snapshot.expires_at)
    && snapshot.issued_at <= now && now < snapshot.expires_at
    && snapshot.expires_at - snapshot.issued_at <= 60, 'provenance_time');
  return Object.freeze({
    version: HELD_PROVENANCE_VERSION,
    snapshot: Object.freeze(snapshot),
    snapshot_hash: sha256(bytes),
    currentness_verified: false,
    receipt: null,
    external_execution_authorized: false,
    release_hold: true,
  });
}
