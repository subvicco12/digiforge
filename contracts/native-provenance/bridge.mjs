import { createHash, verify } from 'node:crypto';

export const NATIVE_PROVENANCE_VERSION = '1.0.0';
export const NATIVE_PROVENANCE_PURPOSE = 'DF_ETSY_NATIVE_PROVENANCE_1';
export const NATIVE_CORE_SOURCE = '50fc20ccb7ca33b4b1267a5cd00f65f1fb75e0c3';

const fields = [
  'business_id', 'shop_id', 'shop_key', 'etsy_shop_id', 'listing_id', 'order_id',
  'line_id', 'product_id', 'product_version', 'artwork_source_hash', 'snapshot_revision',
  'producer', 'source_sha', 'issued_at', 'expires_at',
];
const digest = value => createHash('sha256').update(value).digest('hex');
const hex = (value, size) => typeof value === 'string' && value.length === size && /^[a-f0-9]+$/.test(value);
const positive = value => Number.isSafeInteger(value) && value > 0;
const reference = value => typeof value === 'string' && /^[A-Za-z0-9._:-]{1,128}$/.test(value);

export class NativeProvenanceBridgeV1 {
  #pin;

  constructor({ producer, sourceSha, publicKey, publicKeySha256, businessId, etsyShopId }) {
    if (!reference(producer) || !hex(sourceSha, 40) || !positive(businessId) || !reference(etsyShopId)
      || publicKey?.type !== 'public' || publicKey.asymmetricKeyType !== 'ed25519'
      || digest(publicKey.export({ type: 'spki', format: 'der' })) !== publicKeySha256) {
      throw new TypeError('Invalid trusted producer configuration');
    }
    this.#pin = { producer, sourceSha, publicKey, businessId, etsyShopId };
  }

  inspect(snapshotJson, signature, now) {
    const result = (status, snapshotHash = null) => Object.freeze({
      version: NATIVE_PROVENANCE_VERSION, status, snapshot_hash: snapshotHash,
      native_source_sha: NATIVE_CORE_SOURCE, release_hold: true,
      native_write_authorized: false, external_execution_authorized: false,
      receipt: null,
    });
    if (typeof snapshotJson !== 'string' || Buffer.byteLength(snapshotJson) > 4096
      || !Buffer.isBuffer(signature) || signature.length !== 64 || !positive(now)) {
      return result('INVALID_INPUT');
    }
    let snapshot;
    try { snapshot = JSON.parse(snapshotJson); } catch { return result('INVALID_INPUT'); }
    if (!snapshot || Object.keys(snapshot).length !== fields.length
      || !fields.every(field => Object.hasOwn(snapshot, field) && ['number', 'string'].includes(typeof snapshot[field]))) return result('INVALID_INPUT');
    const canonical = JSON.stringify(Object.fromEntries(fields.map(field => [field, snapshot[field]])));
    if (canonical !== snapshotJson || !verify(null, Buffer.from(
      NATIVE_PROVENANCE_PURPOSE + '\n' + canonical,
    ), this.#pin.publicKey, signature)) return result('UNAUTHENTICATED');
    const pin = this.#pin;
    if (snapshot.producer !== pin.producer || snapshot.source_sha !== pin.sourceSha
      || snapshot.business_id !== pin.businessId || snapshot.etsy_shop_id !== pin.etsyShopId
      || snapshot.shop_id !== 2 || snapshot.shop_key !== 'personalized_pod') {
      return result('SCOPE_DENIED');
    }
    if (!['listing_id', 'order_id', 'line_id', 'product_id'].every(field => reference(snapshot[field]))
      || !positive(snapshot.product_version) || !positive(snapshot.snapshot_revision)
      || !hex(snapshot.artwork_source_hash, 64)) return result('INVALID_INPUT');
    if (!positive(snapshot.issued_at) || !positive(snapshot.expires_at)
      || snapshot.issued_at > now || snapshot.expires_at <= now
      || snapshot.expires_at - snapshot.issued_at > 60) return result('EXPIRED_OR_NOT_CURRENT');
    return result('AUTHENTICATED_WRITER_FENCE_REQUIRED', digest(canonical));
  }
}
