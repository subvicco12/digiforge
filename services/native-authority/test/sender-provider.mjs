import http from 'node:http';
import { digest } from '../protocol.mjs';

/** Disposable real HTTP endpoint; never a provider SDK or authority stand-in. */
export async function startProvider(t, secret) {
  const operations = new Map(), receipts = new Map(), requests = [], reads = [];
  const fixture = { operations, receipts, requests, reads, behavior: 'success', onConnect: null, onPost: null, onRead: null, recoveryPatch: null, delayMs: 0 };
  const server = http.createServer(async (req, res) => {
    if (req.headers.authorization !== `Bearer ${secret}`) { res.writeHead(401).end('{}'); return; }
    if (req.method === 'GET' && req.url.startsWith('/recovery/')) {
      const id = decodeURIComponent(req.url.slice('/recovery/'.length)); reads.push(id);
      const receipt = receipts.get(id); if (!receipt) { res.writeHead(404).end('{}'); return; }
      fixture.onRead?.();
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ ...receipt, ...fixture.recoveryPatch })); return;
    }
    const chunks = []; for await (const chunk of req) chunks.push(chunk);
    let body; try { body = JSON.parse(Buffer.concat(chunks).toString()); } catch { res.writeHead(400).end('{}'); return; }
    const entry = [...operations.values()].find(v => v.payload.body.external_id === body.external_id);
    requests.push({ method: req.method, path: req.url, authenticated: true, body });
    if (!entry || req.method !== 'POST' || req.url !== entry.payload.path || digest(body) !== entry.op.payload_fingerprint) { res.writeHead(400).end('{}'); return; }
    const op = entry.op;
    receipts.set(op.operation_id, { ...op.binding, operation_id: op.operation_id, allocation_id: op.allocation_id, allocation_revision: op.allocation_revision, payload_fingerprint: op.payload_fingerprint, generation: op.generation, outcome: 'SUCCEEDED', provider_reference: 'offline-provider-order-1' });
    fixture.onPost?.();
    if (fixture.delayMs) await new Promise(resolve => setTimeout(resolve, fixture.delayMs));
    if (fixture.behavior === 'timeout') return;
    if (fixture.behavior === 'redirect') { res.writeHead(302, { Location: '/unsafe-next' }).end('{}'); return; }
    if (fixture.behavior === 'oversized') { res.writeHead(200).end('x'.repeat(70000)); return; }
    if (fixture.behavior === 'invalid-json') { res.writeHead(200).end('PRIVATE PROVIDER RESPONSE MUST NOT ESCAPE'); return; }
    if (fixture.behavior === 'wrong-id') { res.writeHead(200).end(JSON.stringify({ id: 'bad/reference', external_id: body.external_id })); return; }
    if (fixture.behavior === 'wrong-external-id') { res.writeHead(200).end(JSON.stringify({ id: 'offline-provider-order-1', external_id: 'foreign-external-order' })); return; }
    res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ id: 'offline-provider-order-1', external_id: body.external_id }));
  });
  server.on('connection', socket => fixture.onConnect?.(socket));
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  fixture.url = `http://127.0.0.1:${server.address().port}`;
  t.after(async () => { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); });
  return fixture;
}
