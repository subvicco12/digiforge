import { spawn } from 'node:child_process';
import { createServer, request as httpRequest } from 'node:http';
import { mkdtempSync, mkdirSync, chmodSync, writeFileSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { generateKeyPairSync, randomBytes, randomUUID, createHash } from 'node:crypto';
import { canonical, digest, sign, parseCanonical } from '../protocol.mjs';
import { BINDING_FIELDS } from '../schema.mjs';
import { serviceBuildDigest } from '../config.mjs';
import { AuthorityStore } from '../store.mjs';
import { AuthorityClient } from '../client.mjs';
import { DatabaseSync } from 'node:sqlite';

const service = resolve(fileURLToPath(new URL('..', import.meta.url)));
const roles = ['workload', 'issuer', 'witness', 'owner', 'emergency', 'recovery'];
export const closedHolds = Object.freeze({ stop_all: true, external_lock: true, automation: false, release_hold: true });
export const offlineReleasedHolds = Object.freeze({ stop_all: false, external_lock: false, automation: true, release_hold: false });
const now = () => Math.floor(Date.now() / 1000);

export function jsonPost(url, value) {
  const body = canonical(value);
  return new Promise((resolveResult, reject) => {
    const req = httpRequest(new URL(url), { method: 'POST', agent: false, headers: { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body) } }, res => {
      const chunks = []; let bytes = 0;
      res.on('data', chunk => { bytes += chunk.length; if (bytes > 65536) req.destroy(new Error('fixture_response_bound')); else chunks.push(chunk); });
      res.on('end', () => { try { resolveResult({ status: res.statusCode, body: parseCanonical(Buffer.concat(chunks).toString('utf8')) }); } catch (e) { reject(e); } });
      res.on('error', reject);
    });
    req.setTimeout(5000, () => req.destroy(new Error('fixture_http_timeout'))); req.on('error', reject); req.end(body);
  });
}

/** Starts real role processes and a clearly synthetic local provider. No authority response is mocked. */
export async function createProcessFixture(t, { holds = closedHolds, providerBehavior = 'success', grantSeconds = 240, coreScope = null } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'df-native-process-')); chmodSync(dir, 0o700);
  const dirs = Object.fromEntries(['issuer', 'witness', 'administrator'].map(role => { const path = join(dir, role); mkdirSync(path, { mode: 0o700 }); return [role, path]; }));
  const processes = new Map(); let provider; let providerRelease; let disposed = false;
  const stop = async role => {
    const p = processes.get(role); if (!p) return;
    processes.delete(role); if (p.child.exitCode !== null || p.child.signalCode !== null) return;
    await new Promise(resolveExit => { const timer = setTimeout(() => p.child.kill('SIGKILL'), 1500); p.child.once('exit', () => { clearTimeout(timer); resolveExit(); }); p.child.kill('SIGTERM'); });
  };
  const dispose = async () => {
    if (disposed) return; disposed = true;
    providerRelease?.();
    for (const role of [...processes.keys()].filter(k => k.startsWith('issuer'))) await stop(role);
    for (const role of [...processes.keys()]) await stop(role);
    if (provider) await new Promise(r => { provider.closeAllConnections(); provider.close(r); });
    rmSync(dir, { recursive: true, force: true });
  };
  t.after(dispose);
  const write = (directory, name, value) => { const path = join(directory, name); writeFileSync(path, typeof value === 'string' ? value : canonical(value), { mode: 0o600 }); chmodSync(path, 0o600); return path; };
  const pairs = Object.fromEntries(roles.map(role => [role, generateKeyPairSync('ed25519')]));
  const publicMaps = Object.fromEntries(roles.map(role => [role, { [role]: pairs[role].publicKey.export({ type: 'spki', format: 'pem' }) }]));
  const signingPaths = Object.fromEntries(roles.map(role => [role, write(role === 'issuer' || role === 'witness' ? dirs[role] : dirs.administrator, role + '.pem', pairs[role].privateKey.export({ type: 'pkcs8', format: 'pem' }))]));
  const secret = 'offline-test-' + randomBytes(32).toString('base64url');
  const suffix = randomUUID();
  const binding = { authority_id: 'authority-' + suffix, installation_id: 'installation-' + suffix, owner_id: 'owner-' + suffix, owner_proof_reference: 'owner-proof-' + suffix, workload_id: 'workload-' + suffix, deployment_digest: serviceBuildDigest(), compatibility_floor: '1.0.0', provider: 'printify', provider_account_reference: coreScope?.account ?? ('offline-account-' + suffix), environment: 'test', integration_id: coreScope?.integration ?? 37, credential_reference: coreScope?.integration ?? 37, shop_id: 2, shop_key: 'personalized_pod' };
  const identity = { ...binding, witness_id: 'witness-' + suffix, root_set_digest: digest(publicMaps.issuer), root_set_revision: 1 };
  const productId = 'product-' + randomUUID(), orderId = 'order-' + randomUUID();
  const lines = [{ line_identity: 'line-' + randomUUID(), quantity: 2 }];
  const payloadBody = { external_id: 'offline-order-' + randomUUID(), line_items: [{ product_id: 'offline-provider-product', variant_id: 202, quantity: 2 }] };
  const operationId = digest(randomBytes(32).toString('hex'));
  const product = { binding, product_identity: productId, core_reference: 'offline-native-product-core-reference' };
  const order = { binding, order_identity: orderId, product_identity: productId, lines, core_reference: 'offline-native-order-core-reference' };
  const grant = { grant_reference: 'grant-' + suffix, binding, issued_at: now() - 1, expires_at: now() + grantSeconds, revoked: false };
  const approval = { approval_reference: coreScope ? digest('approval-' + suffix) : 'approval-' + suffix, approval_revision: 1, policy_revision: 1, operation_id: operationId, order_identity: orderId, order_version: 1, listing_identity: 'offline-listing-' + suffix, product_identity: productId, product_version: 1, expected_product_version: 1, payload_fingerprint: digest(payloadBody), lines, binding };
  const databasePath = join(dirs.witness, 'witness.sqlite');
  const inspect = fn => { const store = new AuthorityStore(databasePath); try { return fn(store); } finally { store.close(); } };
  const requests = []; let firstRequestResolve;
  const firstRequest = new Promise(r => { firstRequestResolve = r; });
  let behavior = providerBehavior;
  provider = createServer(async (req, res) => {
    const chunks = []; for await (const chunk of req) chunks.push(chunk);
    const authenticated = req.headers.authorization === 'Bearer ' + secret;
    requests.push({ method: req.method, path: req.url, authenticated, body: req.method === 'POST' ? JSON.parse(Buffer.concat(chunks).toString()) : null });
    if (!authenticated) { res.writeHead(401); res.end('{}'); return; }
    if (req.method === 'POST') {
      firstRequestResolve();
      if (behavior === 'hold') await new Promise(r => { providerRelease = r; });
      if (behavior === 'disconnect') { req.socket.destroy(); return; }
      if (behavior === 'failure') { res.writeHead(422, { 'content-type': 'application/json' }); res.end('{"error":"offline_rejected"}'); return; }
      res.writeHead(201, { 'content-type': 'application/json' }); res.end(canonical({ id: 'offline-provider-reference', external_id: payloadBody.external_id })); return;
    }
    if (req.method === 'GET' && req.url === '/recovery/' + operationId) {
      if (!requests.some(r => r.method === 'POST')) { res.writeHead(404); res.end('{}'); return; }
      const op = inspect(s => s.operation(operationId));
      const fields = [...BINDING_FIELDS, 'operation_id', 'allocation_id', 'allocation_revision', 'payload_fingerprint', 'generation'];
      const record = Object.fromEntries(fields.map(k => [k, op[k]]));
      res.writeHead(200, { 'content-type': 'application/json' }); res.end(canonical({ ...record, outcome: 'SUCCEEDED', provider_reference: 'offline-provider-reference' })); return;
    }
    res.writeHead(404); res.end('{}');
  });
  await new Promise(r => provider.listen(0, '127.0.0.1', r));
  const providerUrl = 'http://127.0.0.1:' + provider.address().port;
  const providerShop = coreScope?.providerShop ?? '12345';
  const credential = { credential_reference: binding.credential_reference, secret, binding, provider_shop_id: providerShop };
  const payload = { operation_id: operationId, order_identity: orderId, provider_shop_id: providerShop, method: 'POST', path: '/v1/shops/' + providerShop + '/orders.json', body: payloadBody, payload_fingerprint: digest(payloadBody) };
  const { body: omitted, ...payloadMetadata } = payload;
  const provisioning = { schema: 'DF_NATIVE_INSTALLATION_1', version: '1.0.0', identity, mode: 'OFFLINE_TEST', provider_base_url: providerUrl, principal_set_digest: digest(publicMaps), holds, products: [product], orders: [order], grants: [grant], approvals: [approval], credential_inventory: [{ credential_reference: binding.credential_reference, binding, provider_shop_id: providerShop, secret_sha256: createHash('sha256').update(secret).digest('hex') }], payload_inventory: [payloadMetadata], issued_at: now() - 1, expires_at: now() + 240 };
  const dual = (prefix, message) => ({ owner: sign(prefix + '.owner.v1', message, pairs.owner.privateKey, 'owner'), recovery: sign(prefix + '.recovery.v1', message, pairs.recovery.privateKey, 'recovery') });
  const configs = {};
  const configPaths = {};
  const saveConfig = role => { configPaths[role] = write(dirs[role], 'config.json', configs[role]); return configPaths[role]; };
  for (const role of ['witness', 'issuer']) configs[role] = { role, mode: 'OFFLINE_TEST', listen: { host: '127.0.0.1', port: 0 }, identity, keys: { signing_key_id: role, signing_private_key_path: signingPaths[role], ...publicMaps }, provisioning: dual('installation', provisioning), database_path: role === 'witness' ? databasePath : null, witness_url: null, credentials_path: role === 'issuer' ? write(dirs.issuer, 'credentials.json', [credential]) : null, payloads_path: role === 'issuer' ? write(dirs.issuer, 'payloads.json', [payload]) : null };
  const launch = async (role, alias = role) => {
    const path = saveConfig(role);
    const child = spawn(process.execPath, [join(service, 'server.mjs'), role, path], { stdio: ['ignore', 'pipe', 'pipe'], env: { PATH: process.env.PATH, TZ: 'UTC', NODE_NO_WARNINGS: '1' } });
    const record = { child, address: null, stdout: '', stderr: '' }; processes.set(alias, record);
    return await new Promise((resolveStart, reject) => {
      const timer = setTimeout(() => { child.kill('SIGKILL'); reject(new Error('actual_' + role + '_startup_timeout')); }, 5000);
      const fail = () => { clearTimeout(timer); reject(new Error('actual_' + role + '_startup_failed:' + record.stderr.slice(0, 80))); };
      child.once('error', fail); child.once('exit', fail);
      child.stderr.on('data', chunk => { if (record.stderr.length < 8192) record.stderr += chunk.toString(); });
      child.stdout.on('data', chunk => {
        record.stdout += chunk.toString();
        if (!record.address && record.stdout.includes('\n')) {
          try {
            const line = record.stdout.slice(0, record.stdout.indexOf('\n')); const started = JSON.parse(line);
            if (started.role !== role || !/^http:\/\/127\.0\.0\.1:[1-9][0-9]*$/.test(started.address)) throw new Error('actual_startup_identity');
            record.address = started.address; clearTimeout(timer); child.removeListener('exit', fail); child.removeListener('error', fail); resolveStart(record);
          } catch { clearTimeout(timer); child.kill('SIGKILL'); reject(new Error('actual_startup_line')); }
        }
      });
    });
  };
  try { await launch('witness'); configs.issuer.witness_url = processes.get('witness').address; await launch('issuer'); }
  catch (error) { await dispose(); throw error; }
  const client = (role = 'workload', options = {}) => new AuthorityClient({ url: processes.get('issuer').address, binding, keyId: role, privateKey: readFileSync(signingPaths[role]), issuerKeys: new Map(Object.entries(publicMaps.issuer)), witnessKeys: new Map(Object.entries(publicMaps.witness)), rootRevision: identity.root_set_revision, ...options });
  const checkpointBody = () => { const s = inspect(store => store.state()); return { grant_reference: grant.grant_reference, expected_epoch: s.epoch, expected_generation: s.generation }; };
  const admissionBody = () => ({ ...checkpointBody(), operation_id: operationId, payload_fingerprint: digest(payloadBody), product_version: 1, expected_product_version: 1, approval_reference: approval.approval_reference, approval_revision: 1, policy_revision: 1 });
  const control = async (type, data, { role = 'witness', reference = 'control-' + randomUUID(), mutate } = {}) => {
    const record = { schema: 'DF_NATIVE_CONTROL_1', version: '1.0.0', reference, binding, issued_at: now() - 1, expires_at: now() + 60, data, type };
    const envelope = dual('control', record); if (mutate) mutate(envelope);
    return { response: await jsonPost(new URL('/control', processes.get(role).address), envelope), record, envelope };
  };
  const crash = async alias => {
    const p = processes.get(alias); if (!p) throw new Error('actual_process_missing');
    await new Promise(r => { p.child.once('exit', r); p.child.kill('SIGKILL'); }); processes.delete(alias);
  };
  const records = () => { const db = new DatabaseSync(databasePath, { readOnly: true }); try { return { products: db.prepare('SELECT payload FROM products').all().map(r => JSON.parse(r.payload)), orders: db.prepare('SELECT payload FROM orders').all().map(r => JSON.parse(r.payload)), approvals: db.prepare('SELECT payload,allocation_id,allocation_revision,consumed FROM approvals').all() }; } finally { db.close(); } };
  const reprovisionIssuerRoot = async newPair => {
    await stop('issuer'); await stop('witness');
    publicMaps.issuer = { 'issuer-next': newPair.publicKey.export({ type: 'spki', format: 'pem' }) };
    identity.root_set_digest = digest(publicMaps.issuer); identity.root_set_revision = 2;
    configs.witness.keys.issuer = publicMaps.issuer;
    signingPaths.issuer_next = write(dirs.issuer, 'issuer-next.pem', newPair.privateKey.export({ type: 'pkcs8', format: 'pem' }));
    Object.assign(configs.issuer.keys, { issuer: publicMaps.issuer, signing_key_id: 'issuer-next', signing_private_key_path: signingPaths.issuer_next });
    provisioning.principal_set_digest = digest(publicMaps); provisioning.issued_at = now() - 1; provisioning.expires_at = now() + 240;
    for (const role of ['witness', 'issuer']) configs[role].provisioning = dual('installation', provisioning);
    await launch('witness'); configs.issuer.witness_url = processes.get('witness').address; await launch('issuer');
  };
  return { dir, dirs, identity, binding, grant, approval, productId, orderId, operationId, lines, databasePath, configPaths, configs, publicMaps, signingPaths, secret, requests, firstRequest, client, checkpointBody, admissionBody, control, state: () => inspect(s => s.state()), operation: () => inspect(s => s.operation(operationId)), records, stop, crash, launch, processes, dispose, reprovisionIssuerRoot, releaseProvider: () => providerRelease?.(), setProviderBehavior: value => { behavior = value; }, assertPrivatePermissions: () => Object.values(signingPaths).every(p => (statSync(p).mode & 0o777) === 0o600) && Object.values(dirs).every(p => (statSync(p).mode & 0o777) === 0o700) };
}

/** Real transport barrier: forwards signed bytes unchanged, never signs an authority response. */
export async function witnessBarrier(t, witnessUrl, match, phase = 'before') {
  let release, reachedResolve, used = false;
  const held = new Promise(r => { release = r; });
  const reached = new Promise(r => { reachedResolve = r; });
  const server = createServer(async (req, res) => {
    try {
      const chunks = []; for await (const chunk of req) chunks.push(chunk);
      const body = Buffer.concat(chunks); const envelope = parseCanonical(body.toString('utf8'));
      const target = !used && match(req.url, envelope); if (target) used = true;
      if (target && phase === 'before') { reachedResolve({ envelope }); await held; }
      const response = await new Promise((resolveResponse, reject) => {
        const forward = httpRequest(new URL(req.url, witnessUrl), { method: 'POST', agent: false, headers: { 'content-type': 'application/json', 'content-length': body.length } }, incoming => {
          const bytes = []; incoming.on('data', c => bytes.push(c)); incoming.on('end', () => resolveResponse({ status: incoming.statusCode, body: Buffer.concat(bytes) })); incoming.on('error', reject);
        }); forward.on('error', reject); forward.end(body);
      });
      if (target && phase === 'after') { reachedResolve({ envelope, response: parseCanonical(response.body.toString('utf8')) }); await held; }
      if (!res.destroyed) { res.writeHead(response.status, { 'content-type': 'application/json' }); res.end(response.body); }
    } catch { if (!res.destroyed) { res.writeHead(503, { 'content-type': 'application/json' }); res.end('{"error":"fixture_transport_failure"}'); } }
  });
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  t.after(() => new Promise(r => { release(); server.closeAllConnections(); server.close(r); }));
  return { url: 'http://127.0.0.1:' + server.address().port, reached, release };
}
