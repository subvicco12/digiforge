import { createHash, createPrivateKey, createPublicKey, sign as cryptoSign, verify as cryptoVerify } from 'node:crypto';
import { AuthorityError, requireAuthority } from './errors.mjs';

export const DOMAIN = 'DF_INDEPENDENT_CHECKPOINT_AUTHORITY_1';
export const WITNESS_DOMAIN = 'DF_INDEPENDENT_RECOVERY_WITNESS_1';
export const VERSION = '1.0.0';
export const PURPOSE = Object.freeze({
  readMapPublicationV1: 'held.provenance.map.read.v1',
  readBlockedProvenanceV1: 'held.provenance.block.read.v1',
  commitHeldProvenanceV1: 'held.provenance.commit.v1', readHeldProvenanceV1: 'held.provenance.read.v1',
  currentCheckpoint: 'checkpoint.request.v1', admitOperation: 'operation.request.v1',
  requestEmergency: 'emergency.request.v1', readDrain: 'drain.request.v1',
  recoverInstallation: 'recovery.request.v1', rotateRoot: 'rotation.request.v1',
});

export function canonical(value, depth = 0) {
  requireAuthority(depth < 24, 'message_depth');
  if (value === null || typeof value === 'boolean') return JSON.stringify(value);
  if (typeof value === 'number') {
    requireAuthority(Number.isSafeInteger(value) && !Object.is(value, -0), 'integer_required');
    return String(value);
  }
  if (typeof value === 'string') {
    requireAuthority(!/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/u.test(value), 'invalid_unicode');
    return JSON.stringify(value);
  }
  if (Array.isArray(value)) return '[' + value.map(v => canonical(v, depth + 1)).join(',') + ']';
  requireAuthority(typeof value === 'object' && Object.getPrototypeOf(value) === Object.prototype, 'json_object_required');
  return '{' + Object.keys(value).sort().map(k => JSON.stringify(k) + ':' + canonical(value[k], depth + 1)).join(',') + '}';
}

export function parseCanonical(raw) {
  requireAuthority(typeof raw === 'string' && Buffer.byteLength(raw) <= 65536, 'message_bound');
  let value;
  try { value = JSON.parse(raw); } catch { throw new AuthorityError('invalid_json'); }
  requireAuthority(canonical(value) === raw, 'noncanonical_message');
  return value;
}

export function digest(value) {
  return createHash('sha256').update(canonical(value)).digest('hex');
}

function bytes(domain, purpose, message) {
  requireAuthority(typeof purpose === 'string' && /^[a-z][a-z0-9.]{1,80}$/.test(purpose), 'message_purpose');
  return Buffer.from(domain + '\n' + purpose + '\n' + canonical(message), 'utf8');
}

export function sign(purpose, message, privateKey, keyId) {
  return signDomain(DOMAIN, purpose, message, privateKey, keyId);
}
export function signWitness(purpose, message, privateKey, keyId) {
  return signDomain(WITNESS_DOMAIN, purpose, message, privateKey, keyId);
}
function signDomain(domain, purpose, message, privateKey, keyId) {
  const key = privateKey?.type === 'private' ? privateKey : createPrivateKey(privateKey);
  requireAuthority(key.asymmetricKeyType === 'ed25519', 'fixed_algorithm');
  requireAuthority(typeof keyId === 'string' && /^[A-Za-z0-9_-]{1,80}$/.test(keyId), 'key_id');
  return { key_id: keyId, message, signature: cryptoSign(null, bytes(domain, purpose, message), key).toString('base64url') };
}

export function verify(purpose, envelope, trustedKeys) {
  return verifyDomain(DOMAIN, purpose, envelope, trustedKeys);
}
export function verifyWitness(purpose, envelope, trustedKeys) {
  return verifyDomain(WITNESS_DOMAIN, purpose, envelope, trustedKeys);
}
function verifyDomain(domain, purpose, envelope, trustedKeys) {
  requireAuthority(envelope && Object.keys(envelope).sort().join(',') === 'key_id,message,signature', 'signed_envelope');
  requireAuthority(typeof envelope.key_id === 'string' && trustedKeys instanceof Map && trustedKeys.has(envelope.key_id), 'untrusted_key');
  requireAuthority(typeof envelope.signature === 'string' && /^[A-Za-z0-9_-]{86}$/.test(envelope.signature), 'signature_encoding');
  const signature = Buffer.from(envelope.signature, 'base64url');
  requireAuthority(signature.length === 64 && signature.toString('base64url') === envelope.signature, 'signature_encoding');
  const configured = trustedKeys.get(envelope.key_id);
  const key = configured?.type === 'public' ? configured : createPublicKey(configured);
  requireAuthority(key.asymmetricKeyType === 'ed25519', 'fixed_algorithm');
  requireAuthority(cryptoVerify(null, bytes(domain, purpose, envelope.message), key, signature), 'signature_invalid');
  return envelope.message;
}
