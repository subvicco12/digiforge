import { requireAuthority } from './errors.mjs';
import { PURPOSE, VERSION } from './protocol.mjs';

export const BINDING_FIELDS = Object.freeze(['authority_id', 'installation_id', 'owner_id', 'owner_proof_reference', 'workload_id', 'deployment_digest', 'compatibility_floor', 'provider', 'provider_account_reference', 'environment', 'integration_id', 'credential_reference', 'shop_id', 'shop_key']);
export const BODY_FIELDS = Object.freeze({
  currentCheckpoint: ['grant_reference', 'expected_epoch', 'expected_generation'],
  admitOperation: ['grant_reference', 'operation_id', 'payload_fingerprint', 'product_version', 'expected_product_version', 'approval_reference', 'approval_revision', 'policy_revision', 'expected_epoch', 'expected_generation'],
  reserveOperation: ['grant_reference', 'operation_id', 'payload_fingerprint', 'product_version', 'expected_product_version', 'approval_reference', 'approval_revision', 'policy_revision', 'expected_epoch', 'expected_generation'],
  readOperation: ['operation_id', 'payload_fingerprint', 'original_request_id', 'original_challenge', 'expected_epoch', 'expected_generation', 'retained_witness_head', 'retained_witness_sequence'],
  requestEmergency: ['emergency_authority_reference', 'emergency_event_id', 'scope_of_revocation', 'expected_generation'],
  readDrain: ['emergency_event_id', 'generation'],
  recoverInstallation: ['independent_recovery_approval', 'previous_epoch', 'expected_witness_head', 'outstanding_lease_inventory_hash'],
  rotateRoot: ['independent_rotation_approval', 'previous_root_set_revision', 'new_root_set_revision', 'new_root_set_digest', 'expected_witness_head'],
});
const DIGEST = /^[a-f0-9]{64}$/;
const REF = /^[A-Za-z0-9_][A-Za-z0-9_.:-]{0,159}$/;
const TOKEN = /^[A-Za-z0-9_-]{43}$/;

export function exactFields(value, fields, code = 'unknown_or_missing_fields') {
  requireAuthority(value !== null && typeof value === 'object' && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype, code);
  requireAuthority(Object.keys(value).sort().join(',') === [...fields].sort().join(','), code);
}
export function reference(value) { return typeof value === 'string' && REF.test(value); }
export function digestValue(value) { return typeof value === 'string' && DIGEST.test(value); }
export function integer(value, minimum = 1) { return Number.isSafeInteger(value) && value >= minimum; }
export function token(value) { return typeof value === 'string' && TOKEN.test(value) && Buffer.from(value, 'base64url').length === 32 && Buffer.from(value, 'base64url').toString('base64url') === value; }
export function bindingOf(request) { return Object.fromEntries(BINDING_FIELDS.map(k => [k, request[k]])); }

export function validateBinding(binding) {
  exactFields(binding, BINDING_FIELDS, 'binding_fields');
  for (const k of BINDING_FIELDS.filter(k => !['deployment_digest', 'compatibility_floor', 'provider', 'environment', 'integration_id', 'credential_reference', 'shop_id', 'shop_key'].includes(k))) requireAuthority(reference(binding[k]), 'binding_reference');
  requireAuthority(integer(binding.integration_id) && integer(binding.credential_reference) && binding.integration_id === binding.credential_reference, 'credential_binding');
  requireAuthority(digestValue(binding.deployment_digest) && binding.compatibility_floor === VERSION, 'deployment_identity');
  requireAuthority(binding.provider === 'printify' && ['production', 'test'].includes(binding.environment) && binding.shop_id === 2 && binding.shop_key === 'personalized_pod', 'physical_scope');
  return binding;
}

export function validateRequest(request) {
  exactFields(request, [...BINDING_FIELDS, 'version', 'method', 'message_purpose', 'request_id', 'challenge', 'method_body']);
  validateBinding(bindingOf(request));
  requireAuthority(request.version === VERSION && Object.hasOwn(PURPOSE, request.method) && request.message_purpose === PURPOSE[request.method], 'method_purpose');
  requireAuthority(token(request.request_id) && token(request.challenge) && request.request_id !== request.challenge, 'fresh_challenge');
  exactFields(request.method_body, BODY_FIELDS[request.method], 'method_body');
  for (const [key, value] of Object.entries(request.method_body)) {
    if (['original_request_id','original_challenge'].includes(key)) requireAuthority(token(value), 'method_token');
    else if (['retained_witness_head','expected_epoch', 'previous_epoch', 'expected_witness_head', 'outstanding_lease_inventory_hash', 'payload_fingerprint', 'new_root_set_digest', 'operation_id'].includes(key)) requireAuthority(digestValue(value), 'method_digest');
    else if (['product_version', 'expected_product_version'].includes(key)) requireAuthority(integer(value, 0), 'product_version');
    else if (['retained_witness_sequence', 'expected_generation', 'generation', 'approval_revision', 'policy_revision', 'previous_root_set_revision', 'new_root_set_revision'].includes(key)) requireAuthority(integer(value), 'method_revision');
    else requireAuthority(reference(value), 'method_reference');
  }
  return request;
}
