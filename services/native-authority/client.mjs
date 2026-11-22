import { randomBytes, createPrivateKey, createPublicKey } from 'node:crypto';
import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { AuthorityError, requireAuthority as need } from './errors.mjs';
import { VERSION, PURPOSE, canonical, parseCanonical, sign, verify, verifyWitness } from './protocol.mjs';
import { exactFields, validateBinding, validateRequest, reference, digestValue, integer } from './schema.mjs';

const LIMIT = 65536;
const RESPONSE_FIELDS = Object.freeze(['version', 'request_bindings', 'authority_id', 'key_id', 'root_set_revision', 'recovery_epoch', 'checkpoint_epoch', 'generation', 'recovery_revision', 'issued_at', 'expires_at', 'disposition', 'evidence_reference', 'witness_id', 'witness_head', 'witness_sequence', 'result']);
const KEY_ID = /^[A-Za-z0-9_-]{1,80}$/;
const STATES = new Set(['CURRENT', 'SUCCEEDED', 'FAILED', 'UNKNOWN', 'ADMITTED', 'SENDING', 'HELD', 'DENIED', 'PENDING', 'DRAINED', 'RECOVERED_HELD', 'ROOT_ROTATED_HELD']);
const RESULT_FIELDS = ['schema', 'method', 'state', 'operation', 'drain', 'reason', 'external_execution_authorized'];
const OPERATION_FIELDS = ['operation_id', 'allocation_id', 'allocation_revision', 'product_identity', 'product_version', 'order_identity', 'order_version', 'approval_reference', 'approval_revision', 'policy_revision', 'payload_fingerprint', 'generation', 'checkpoint_epoch', 'state', 'provider_reference'];

function pinnedKeys(keys) {
  need(keys instanceof Map && keys.size > 0, 'pinned_keys_required');
  const copy = new Map();
  for (const [id, configured] of keys) {
    need(typeof id === 'string' && KEY_ID.test(id), 'key_id');
    let key;
    try { key = configured?.type === 'public' ? configured : createPublicKey(configured); }
    catch { throw new AuthorityError('pinned_key_invalid'); }
    need(key.asymmetricKeyType === 'ed25519', 'fixed_algorithm');
    copy.set(id, key);
  }
  return copy;
}

function destination(value) {
  let url;
  try { url = new URL(value); } catch { throw new AuthorityError('authority_url'); }
  need(['http:', 'https:'].includes(url.protocol) && !url.username && !url.password && !url.search && !url.hash && url.pathname === '/', 'authority_url');
  need(url.protocol === 'https:' || ['127.0.0.1', '[::1]'].includes(url.hostname), 'authority_cleartext_scope');
  return new URL('/rpc', url);
}

function post(url, body, timeoutMs) {
  return new Promise((resolve, reject) => {
    let settled = false;
    let response;
    let timer;
    const finish = (error, value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      if (error) {
        response?.destroy();
        req.destroy();
        reject(error instanceof AuthorityError ? error : new AuthorityError('authority_unreachable'));
      } else resolve(value);
    };
    const req = (url.protocol === 'https:' ? httpsRequest : httpRequest)(url, {
      method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json', 'content-length': Buffer.byteLength(body), connection: 'close' },
    }, res => {
      response = res;
      if (res.statusCode !== 200) return finish(new AuthorityError('authority_http_status'));
      if (res.headers['content-encoding'] && res.headers['content-encoding'] !== 'identity') return finish(new AuthorityError('authority_content_encoding'));
      if (!/^application\/json(?:\s*;|$)/i.test(res.headers['content-type'] ?? '')) return finish(new AuthorityError('authority_content_type'));
      const declared = res.headers['content-length'];
      if (declared !== undefined && (!/^[0-9]+$/.test(declared) || Number(declared) > LIMIT)) return finish(new AuthorityError('message_bound'));
      let size = 0;
      const chunks = [];
      res.on('data', chunk => {
        size += chunk.length;
        if (size > LIMIT) return finish(new AuthorityError('message_bound'));
        chunks.push(chunk);
      });
      res.on('end', () => {
        try { finish(null, new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(Buffer.concat(chunks))); }
        catch { finish(new AuthorityError('invalid_utf8')); }
      });
      res.on('aborted', () => finish(new AuthorityError('authority_response_aborted')));
      res.on('error', error => finish(error));
    });
    req.on('error', error => finish(error));
    timer = setTimeout(() => finish(new AuthorityError('authority_timeout')), timeoutMs);
    req.end(body);
  });
}

/** Verifies two independently pinned signatures; never converts evidence into an execution permit. */
export class AuthorityClient {
  #url; #binding; #keyId; #privateKey; #issuerKeys; #witnessKeys; #rootRevision; #timeout;
  constructor({ url, binding, keyId, privateKey, issuerKeys, witnessKeys, rootRevision = 1, timeoutMs = 2000 }) {
    this.#url = destination(url);
    this.#binding = parseCanonical(canonical(validateBinding(binding)));
    need(typeof keyId === 'string' && KEY_ID.test(keyId), 'key_id');
    this.#keyId = keyId;
    try { this.#privateKey = privateKey?.type === 'private' ? privateKey : createPrivateKey(privateKey); }
    catch { throw new AuthorityError('workload_key_invalid'); }
    need(this.#privateKey.asymmetricKeyType === 'ed25519', 'fixed_algorithm');
    this.#issuerKeys = pinnedKeys(issuerKeys);
    this.#witnessKeys = pinnedKeys(witnessKeys);
    const issuerMaterial = new Set([...this.#issuerKeys.values()].map(k => k.export({ type: 'spki', format: 'der' }).toString('hex')));
    need([...this.#witnessKeys.values()].every(k => !issuerMaterial.has(k.export({ type: 'spki', format: 'der' }).toString('hex'))), 'independent_witness_key_required');
    need(integer(rootRevision) && integer(timeoutMs) && timeoutMs <= 30000, 'client_configuration');
    this.#rootRevision = rootRevision;
    this.#timeout = timeoutMs;
  }

  /** Core can supply correlation tokens it persisted before a one-shot admission attempt. */
  async call(method, methodBody, correlation = null) {
    return (await this.#call(method,methodBody,correlation)).message;
  }

  async readOperationEnvelope(methodBody) {
    return this.#call('readOperation',methodBody,null);
  }

  async #call(method, methodBody, correlation) {
    need(Object.hasOwn(PURPOSE, method), 'method_purpose');
    if (correlation !== null) {
      need(['admitOperation','reserveOperation'].includes(method), 'admission_correlation_only');
      exactFields(correlation, ['request_id','challenge'], 'admission_correlation_fields');
    }
    const requestId = correlation === null ? randomBytes(32).toString('base64url') : correlation.request_id;
    let challenge = correlation?.challenge;
    if (correlation === null) do { challenge = randomBytes(32).toString('base64url'); } while (challenge === requestId);
    const request = validateRequest({ ...this.#binding, version: VERSION, method, message_purpose: PURPOSE[method], request_id: requestId, challenge, method_body: parseCanonical(canonical(methodBody)) });
    const body = canonical(sign(PURPOSE[method], request, this.#privateKey, this.#keyId));
    need(Buffer.byteLength(body) <= LIMIT, 'message_bound');
    const envelope = parseCanonical(await post(this.#url, body, this.#timeout));
    const outer = verify('issuer.response.v1', envelope, this.#issuerKeys);
    exactFields(outer, [...RESPONSE_FIELDS, 'witness_attestation'], 'response_fields');
    this.#validateMessage(outer, request);
    need(outer.authority_id === this.#binding.authority_id && outer.key_id === envelope.key_id, 'issuer_identity');
    const witness = verifyWitness('witness.response.v1', outer.witness_attestation, this.#witnessKeys);
    exactFields(witness, RESPONSE_FIELDS, 'witness_fields');
    this.#validateMessage(witness, request);
    need(witness.authority_id === this.#binding.authority_id && witness.key_id === outer.witness_attestation.key_id, 'witness_identity');
    for (const field of RESPONSE_FIELDS.filter(k => k !== 'key_id')) {
      need(canonical(outer[field]) === canonical(witness[field]), 'witness_response_mismatch');
    }
    return envelope;
  }

  #validateMessage(message, request) {
    need(message.version === VERSION && canonical(message.request_bindings) === canonical(request), 'response_request_binding');
    need(reference(message.authority_id) && reference(message.key_id) && reference(message.witness_id) && reference(message.evidence_reference), 'response_identity');
    const revision = request.method === 'rotateRoot' && message.disposition === 'ROOT_ROTATED_HELD' ? request.method_body.new_root_set_revision : this.#rootRevision;
    need(message.root_set_revision === revision && integer(message.generation) && integer(message.recovery_revision) && integer(message.witness_sequence), 'response_revision');
    need(digestValue(message.recovery_epoch) && digestValue(message.checkpoint_epoch) && digestValue(message.witness_head), 'response_digest');
    const now = Math.floor(Date.now() / 1000);
    need(integer(message.issued_at) && integer(message.expires_at) && message.issued_at <= now && now < message.expires_at && message.expires_at > message.issued_at && message.expires_at - message.issued_at <= 300, 'response_expired');
    need(STATES.has(message.disposition), 'response_disposition');
    const result = message.result;
    exactFields(result, RESULT_FIELDS, 'response_result');
    need(result.schema === 'DF_NATIVE_AUTHORITY_RESULT_1' && result.method === request.method && result.state === message.disposition && result.external_execution_authorized === false, 'result_binding');
    need(result.reason === null || reference(result.reason), 'result_reason');
    if (result.operation !== null) {
      const operation = result.operation;
      exactFields(operation, request.method === 'readOperation' ? [...OPERATION_FIELDS,'admission_event','reservation_only'] : OPERATION_FIELDS, 'operation_fields');
      for (const field of ['allocation_id', 'product_identity', 'order_identity', 'approval_reference']) need(reference(operation[field]), 'operation_reference');
      for (const field of ['allocation_revision', 'product_version', 'order_version', 'approval_revision', 'policy_revision', 'generation']) need(integer(operation[field]), 'operation_revision');
      for (const field of ['operation_id', 'payload_fingerprint', 'checkpoint_epoch']) need(digestValue(operation[field]), 'operation_digest');
      need(STATES.has(operation.state) && (operation.provider_reference === null || reference(operation.provider_reference)), 'operation_state');
      if (request.method === 'readOperation') {
        const body=request.method_body;
        need(typeof operation.reservation_only==='boolean','operation_readback_reservation');
        need(operation.operation_id===body.operation_id && operation.payload_fingerprint===body.payload_fingerprint
          && operation.generation===body.expected_generation && operation.checkpoint_epoch===body.expected_epoch
          && operation.state===message.disposition && ['ADMITTED','SENDING','SUCCEEDED','FAILED','UNKNOWN'].includes(operation.state)
          && message.generation>=operation.generation, 'operation_readback_binding');
        exactFields(operation.admission_event,['sequence','head'],'admission_event_fields');
        need(integer(operation.admission_event.sequence)&&digestValue(operation.admission_event.head)
          && message.witness_sequence>=Math.max(operation.admission_event.sequence,body.retained_witness_sequence)
          && (message.witness_sequence!==body.retained_witness_sequence||message.witness_head===body.retained_witness_head), 'operation_readback_frontier');
      }
      if (['admitOperation','reserveOperation'].includes(request.method)) {
        for (const field of ['operation_id', 'payload_fingerprint', 'product_version', 'approval_reference', 'approval_revision', 'policy_revision']) need(operation[field] === request.method_body[field], 'operation_binding');
        need(operation.generation === request.method_body.expected_generation && operation.checkpoint_epoch === request.method_body.expected_epoch, 'operation_binding');
      }
    }
    if (request.method === 'reserveOperation') {
      need(['ADMITTED','HELD','DENIED'].includes(message.disposition)
        && (message.disposition==='ADMITTED')===(result.operation!==null)
        && (result.operation===null||result.operation.state==='ADMITTED') && result.drain===null, 'reservation_disposition');
    }
    if (request.method === 'readOperation') {
      need((message.disposition === 'DENIED') === (result.operation === null) && result.drain === null, 'operation_readback_disposition');
    }
    if (result.drain !== null) {
      exactFields(result.drain, ['state', 'pending_operations'], 'drain_fields');
      need(['PENDING', 'DRAINED'].includes(result.drain.state) && integer(result.drain.pending_operations, 0) && (result.drain.state === 'DRAINED') === (result.drain.pending_operations === 0), 'drain_state');
    }
  }
}
