import http from 'node:http';
import https from 'node:https';
import { AuthorityStore } from './store.mjs';
import { WitnessStoreGateway } from './gateway.mjs';
import { AuthorityError, requireAuthority as need } from './errors.mjs';
import { canonical, digest } from './protocol.mjs';
import { BINDING_FIELDS, bindingOf, exactFields, reference } from './schema.mjs';

const now = () => Math.floor(Date.now() / 1000);
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const numericShop = value => typeof value === 'string' && /^[1-9][0-9]{0,19}$/.test(value);
const DEFAULT_HOLDS = Object.freeze({ STOP: true, externalLock: true, releaseHold: true, automation: false });
const CONTEXT_FIELDS = [...BINDING_FIELDS, 'operation_id', 'allocation_id', 'allocation_revision', 'payload_fingerprint', 'generation'];

function endpoint(base, mode) {
  // No LIVE credential use or guessed provider recovery interface is qualified here.
  need(mode === 'OFFLINE_TEST', 'live_not_qualified');
  let url; try { url = new URL(base); } catch { throw new AuthorityError('provider_url'); }
  need(['http:', 'https:'].includes(url.protocol) && ['127.0.0.1', '[::1]', 'localhost'].includes(url.hostname) && !url.username && !url.password && !url.search && !url.hash && url.pathname === '/', 'offline_loopback_required');
  return url;
}

function jsonObject(raw, code) {
  let value; try { value = JSON.parse(raw); } catch { throw new AuthorityError(code); }
  need(value !== null && typeof value === 'object' && !Array.isArray(value), code);
  // All admitted response fields are ASCII references, digests or integers. Escaped
  // keys/duplicate keys cannot silently select a different acknowledgement identity.
  need(!raw.includes('\\'), code);
  for (const key of Object.keys(value)) need((raw.match(new RegExp('"' + key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '"\\s*:', 'g')) ?? []).length === 1, code);
  return value;
}

/** Actual custody boundary. It consumes real registered operations; it never grants authority. */
export class CustodySender {
  #store; #credentials; #payloads; #url; #holds; #timeout; #maxResponse;
  constructor({ store, credentials, payloads, providerBaseUrl, mode = 'OFFLINE_TEST', holds = DEFAULT_HOLDS, timeoutMs = 1000, maxResponseBytes = 65536 }) {
    need((store instanceof AuthorityStore || store instanceof WitnessStoreGateway) && credentials instanceof Map && payloads instanceof Map, 'actual_custody_store_required');
    need(Number.isSafeInteger(timeoutMs) && timeoutMs > 0 && timeoutMs <= 30000 && Number.isSafeInteger(maxResponseBytes) && maxResponseBytes > 0 && maxResponseBytes <= 65536, 'transport_bound');
    this.#url = endpoint(providerBaseUrl, mode); this.#store = store; this.#credentials = credentials; this.#payloads = payloads; this.#holds = holds; this.#timeout = timeoutMs; this.#maxResponse = maxResponseBytes;
  }

  #released() { const h = this.#holds; return h?.STOP === false && h.externalLock === false && h.releaseHold === false && h.automation === true; }

  #controls(op) {
    const time = now();
    need(Number.isSafeInteger(op.issued_at) && Number.isSafeInteger(op.expires_at) && op.issued_at <= time && time < op.expires_at, 'lease_expired');
    need(this.#released(), 'holds_active');
  }

  async #current(op, expectedState) {
    const current = await this.#store.operation(op.operation_id), state = await this.#store.state();
    need(!state.paused && state.generation === op.generation && state.epoch === op.epoch && current.state === expectedState && current.generation === op.generation && current.allocation_id === op.allocation_id && current.allocation_revision === op.allocation_revision && current.payload_fingerprint === op.payload_fingerprint && digest(bindingOf(current)) === digest(bindingOf(op)), 'generation_revoked');
    this.#controls(current);
  }

  #credential(op) {
    const credential = this.#credentials.get(op.credential_reference);
    need(credential && typeof credential.secret === 'string' && /^offline-test-[A-Za-z0-9_-]{32,128}$/.test(credential.secret), 'offline_credential_required');
    exactFields(credential.binding, BINDING_FIELDS, 'credential_binding');
    need(op.environment === 'test' && digest(credential.binding) === digest(bindingOf(op)) && numericShop(credential.provider_shop_id), 'credential_binding');
    return credential;
  }

  #material(op) {
    const credential = this.#credential(op), payload = this.#payloads.get(op.operation_id);
    need(payload && payload.method === 'POST' && numericShop(payload.provider_shop_id) && payload.provider_shop_id === credential.provider_shop_id && payload.path === `/v1/shops/${payload.provider_shop_id}/orders.json` && payload.order_identity === op.order_identity, 'payload_scope');
    need(payload.body !== null && typeof payload.body === 'object' && !Array.isArray(payload.body) && reference(payload.body.external_id), 'external_order_identity');
    need(hash(payload.payload_fingerprint) && payload.payload_fingerprint === op.payload_fingerprint && digest(payload.body) === op.payload_fingerprint, 'payload_fingerprint');
    const body = canonical(payload.body); need(Buffer.byteLength(body) <= 65536, 'payload_bound');
    return { secret: credential.secret, providerShop: credential.provider_shop_id, path: payload.path, body, externalId: payload.body.external_id };
  }

  #view(op) {
    const fields = [...CONTEXT_FIELDS, 'request_id', 'request_hash', 'epoch', 'state'];
    const result = Object.fromEntries(fields.filter(k => Object.hasOwn(op, k)).map(k => [k, op[k]]));
    result.external_execution_authorized = false;
    if (op.evidence) result.evidence = { ...op.evidence };
    return result;
  }

  async #finish(op, outcome, evidence, possibleBytes, material = null) {
    if (possibleBytes) {
      try {
        await this.#current(op, 'SENDING');
        if (material !== null) { const fresh = this.#material(op); need(fresh.secret === material.secret && fresh.body === material.body && fresh.path === material.path && fresh.providerShop === material.providerShop, 'custody_changed'); }
        this.#controls(op);
      } catch { outcome = 'UNKNOWN'; }
    }
    return this.#view(await this.#store.finish(op.operation_id, outcome, evidence, now()));
  }

  async send(operationId) {
    const admitted = await this.#store.operation(operationId);
    need(admitted.state === 'ADMITTED', 'operation_not_admitted');
    // The actual store (direct test or verified witness) transitions before any provider socket or credential use.
    const op = await this.#store.markSending(operationId, admitted.generation, now());
    let material;
    try { await this.#current(op, 'SENDING'); material = this.#material(op); this.#controls(op); }
    catch (error) { return this.#finish(op, 'FAILED', { failure_category: error instanceof AuthorityError ? error.code : 'custody_denied' }, false); }
    const sent = { possibleBytes: false }; let outcome, evidence;
    try {
      const reply = await this.#request('POST', material.path, material.secret, material.body, sent, () => this.#current(op, 'SENDING'), () => {
        const fresh = this.#material(op);
        need(fresh.secret === material.secret && fresh.body === material.body && fresh.path === material.path && fresh.providerShop === material.providerShop, 'custody_changed');
        this.#controls(op);
      });
      if (reply.status >= 400 && reply.status < 500) { outcome = 'FAILED'; evidence = { failure_category: 'provider_denied' }; }
      else {
        need(reply.status === 200 || reply.status === 201, 'provider_status_unknown');
        const ack = jsonObject(reply.body, 'provider_ack_invalid');
        need(Object.keys(ack).every(k => ['id', 'external_id'].includes(k)) && reference(ack.id) && !ack.id.includes(material.secret), 'provider_ack_invalid');
        if (Object.hasOwn(ack, 'external_id')) need(ack.external_id === material.externalId, 'provider_ack_identity');
        outcome = 'SUCCEEDED'; evidence = { provider_reference: ack.id, observation_sha256: digest(ack) };
      }
    } catch (error) {
      outcome = sent.possibleBytes ? 'UNKNOWN' : 'FAILED'; evidence = { failure_category: error instanceof AuthorityError ? error.code : 'transport_unavailable' };
    }
    // A lost local write acknowledgement must fail closed, never retry a terminal write.
    return this.#finish(op, outcome, evidence, sent.possibleBytes, material);
  }

  async reconcile(operationId) {
    const op = await this.#store.operation(operationId); need(op.state === 'UNKNOWN' || op.state === 'SENDING', 'operation_not_unresolved');
    const credential = this.#credential(op), secret = credential.secret, providerShop = credential.provider_shop_id, sent = { possibleBytes: false };
    const reply = await this.#request('GET', '/recovery/' + encodeURIComponent(op.operation_id), secret, null, sent, null, () => {
      const fresh = this.#credential(op); need(fresh.secret === secret && fresh.provider_shop_id === providerShop, 'custody_changed');
    });
    need(reply.status === 200, 'readback_unavailable'); const record = jsonObject(reply.body, 'readback_invalid');
    exactFields(record, [...CONTEXT_FIELDS, 'outcome', 'provider_reference'], 'readback_fields');
    for (const key of CONTEXT_FIELDS) need(record[key] === op[key], 'readback_binding');
    need(['SUCCEEDED', 'FAILED'].includes(record.outcome) && reference(record.provider_reference) && !record.provider_reference.includes(secret), 'readback_outcome');
    if (op.evidence?.provider_reference) need(record.provider_reference === op.evidence.provider_reference, 'readback_provider_reference');
    const fresh = this.#credential(op); need(fresh.secret === secret && fresh.provider_shop_id === providerShop, 'custody_changed');
    return { ...record, observation_sha256: digest(record), observed_at: now() };
  }

  #request(method, path, secret, body, sent, remoteGuard, finalGuard) {
    return new Promise((resolve, reject) => {
      let settled = false, request;
      const finish = (error, value) => { if (settled) return; settled = true; clearTimeout(timer); if (error) { request?.destroy(); reject(error); } else resolve(value); };
      const timer = setTimeout(() => finish(new AuthorityError('transport_timeout')), this.#timeout);
      try {
        const transport = this.#url.protocol === 'https:' ? https : http;
        request = transport.request(this.#url, { method, path, agent: false, maxHeaderSize: 8192,
          // localhost is a fixed loopback address here, never arbitrary DNS resolution.
          lookup: (_host, options, callback) => options?.all ? callback(null, [{ address: '127.0.0.1', family: 4 }]) : callback(null, '127.0.0.1', 4),
          headers: { Authorization: `Bearer ${secret}`, Accept: 'application/json', ...(body === null ? {} : { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) }) },
        }, response => {
          const chunks = []; let length = 0;
          response.on('data', chunk => { length += chunk.length; if (length > this.#maxResponse) { finish(new AuthorityError('response_bound')); response.destroy(); } else chunks.push(chunk); });
          response.once('error', () => finish(new AuthorityError('transport_response_error')));
          response.once('aborted', () => finish(new AuthorityError('transport_response_error')));
          response.once('end', () => finish(null, { status: response.statusCode, body: Buffer.concat(chunks).toString('utf8') }));
        });
        request.once('error', () => finish(new AuthorityError('transport_unavailable')));
        request.once('socket', socket => {
          const write = async () => {
            if (settled) return;
            try {
              need(['127.0.0.1', '::1', '::ffff:127.0.0.1'].includes(socket.remoteAddress), 'offline_loopback_required');
              if (remoteGuard !== null) await remoteGuard();
              if (settled) return;
              finalGuard();
              // No await or event-loop handoff may separate this final guard and write.
              sent.possibleBytes = true; request.end(body ?? undefined);
            } catch (error) { finish(error instanceof AuthorityError ? error : new AuthorityError('custody_denied')); }
          };
          socket.once(this.#url.protocol === 'https:' ? 'secureConnect' : 'connect', write);
          if (!socket.connecting && this.#url.protocol === 'http:') write();
        });
      } catch { finish(new AuthorityError('transport_unavailable')); }
    });
  }
}
