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

import { createServer } from 'node:http';
import { DatabaseSync } from 'node:sqlite';
import { AuthorityClient } from '../client.mjs';
import { AuthorityStore } from '../store.mjs';
import { startAuthorityServer } from '../server.mjs';
import { digest, sign as signEnvelope } from '../protocol.mjs';
import { post, witnessFixture } from './server-fixtures.mjs';

async function nativeFixture(t) {
  const f = witnessFixture(t, { native: true });
  const witness = await startAuthorityServer(f.path, 'witness');
  t.after(() => witness.close());
  const issuerPath = f.write('issuer.json', {
    ...f.config, role: 'issuer', database_path: null, witness_url: witness.address,
    credentials_path: f.write('credentials.json', []), payloads_path: f.write('payloads.json', []),
    keys: {...f.config.keys, signing_key_id: 'issuer', signing_private_key_path: f.write('issuer.pem',
      f.pairs.issuer.privateKey.export({type:'pkcs8',format:'pem'}))},
  });
  const issuer = await startAuthorityServer(issuerPath, 'issuer');
  t.after(() => issuer.close());
  const clientFor = url => new AuthorityClient({url,binding:f.binding,keyId:'workload',
    privateKey:f.pairs.workload.privateKey,issuerKeys:new Map([['issuer',f.pairs.issuer.publicKey]]),
    witnessKeys:new Map([['witness',f.pairs.witness.publicKey]])});
  const time = Math.floor(Date.now()/1000);
  const snapshot = {...fixture,order_id:'etsy-order',line_id:'etsy-line',listing_id:'etsy-listing',product_id:'source-product',issued_at:time-1,expires_at:time+45};
  const snapshot_json = JSON.stringify(snapshot);
  const snapshot_hash = createHash('sha256').update(snapshot_json).digest('hex');
  const generation = f.state.generation;
  const mapping_id = digest([pin.producer,snapshot.order_id,snapshot.line_id]);
  const control = async (type, reference, data) => {
    const message = {schema:'DF_NATIVE_PROVENANCE_CONTROL_1',version:'1.0.0',reference,
      binding:f.binding,issued_at:time-1,expires_at:time+60,type,data:{producer:pin.producer,generation,...data}};
    const response=await post(witness.address,'/control',{
      owner:signEnvelope('held.provenance.control.owner.v1',message,f.pairs.owner.privateKey,'owner'),
      recovery:signEnvelope('held.provenance.control.recovery.v1',message,f.pairs.recovery.privateKey,'recovery'),
    });
    return {...response, controlDigest:digest(message)};
  };
  assert.equal((await control('ENROLL','enroll',{source_sha:pin.sourceSha,
    key_der:publicKey.export({type:'spki',format:'der'}).toString('base64url'),
    key_pin:pin.keySha256,business_id:1,etsy_shop_id:pin.etsyShopId})).status,200);
  assert.equal((await control('MAP','map',{mapping_id,expected_revision:0,snapshot_hash,
    snapshot_revision:1,order_id:snapshot.order_id,order_version:1,line_id:snapshot.line_id,
    listing_id:snapshot.listing_id,product_id:snapshot.product_id,native_order_id:'order',
    native_line_id:'line',native_listing_id:'listing',native_product_id:'product',product_version:1,artwork_source_hash:snapshot.artwork_source_hash,
    expires_at:snapshot.expires_at})).status,200);
  const body = {snapshot_json,producer_signature:proof(snapshot_json),allocation_revision:1,
    order_version:1,grant_reference:'grant',operation_id:digest('operation'),payload_fingerprint:digest('payload'),
    product_version:1,expected_product_version:1,approval_reference:'approval',approval_revision:1,
    policy_revision:1,expected_epoch:f.state.epoch,expected_generation:generation};
  return {f,client:clientFor(issuer.address),clientFor,issuer,body,snapshot_hash,control,mapping_id,time};
}

test('concurrent held commit yields one signed receipt, durable readback and no sender eligibility', async t=>{
  const {f,client,body,snapshot_hash,time}=await nativeFixture(t);
  const results=await Promise.all([client.call('commitHeldProvenanceV1',body),client.call('commitHeldProvenanceV1',body)]);
  assert.deepEqual(results.map(x=>x.disposition).sort(),['DENIED','HELD']);
  const receipt=results.find(x=>x.disposition==='HELD').result.operation;
  assert.equal(receipt.schema,'DF_NATIVE_PROVENANCE_RECEIPT_1');
  assert.equal(receipt.allocation_revision,2);
  assert.equal(receipt.external_execution_authorized,false);
  const store=new AuthorityStore(f.config.database_path);
  try {
    assert.throws(()=>store.markSending(body.operation_id,body.expected_generation,time),{code:'operation_not_admitted'});
    assert.equal(store.operation(body.operation_id).state,'HELD');
    const state=store.state();
    store.recover(state.witness_head,state.inventory_hash,time);
  } finally { store.close(); }
  const read=await client.call('readHeldProvenanceV1',{operation_id:body.operation_id,snapshot_hash});
  assert.deepEqual(read.result.operation,receipt);
  assert.equal((await client.call('commitHeldProvenanceV1',body)).disposition,'DENIED');
});

test('blocked mapping and revoked enrollment deny inside the witness transaction', async t=>{
  const {client,body,control,mapping_id}=await nativeFixture(t);
  assert.equal((await client.call('commitHeldProvenanceV1',{...body,order_version:2})).disposition,'DENIED');
  assert.equal((await control('BLOCK','block',{mapping_id,expected_revision:1})).status,200);
  assert.equal((await control('BLOCK','block',{mapping_id,expected_revision:1})).status,503);
  assert.equal((await client.call('commitHeldProvenanceV1',body)).disposition,'DENIED');
  assert.equal((await control('REVOKE','revoke',{})).status,200);
  assert.equal((await client.call('commitHeldProvenanceV1',body)).disposition,'DENIED');
});

test('journal failure rolls back CAS; a lost committed reply resolves only through readback', async t=>{
  const {f,client,clientFor,issuer,body,snapshot_hash}=await nativeFixture(t);
  const db=new DatabaseSync(f.config.database_path);
  t.after(()=>db.close());
  db.exec("CREATE TRIGGER fail_held BEFORE INSERT ON witness_events WHEN json_extract(NEW.event,'$.type')='OPERATION_HELD_PROVENANCE' BEGIN SELECT RAISE(ABORT,'injected'); END");
  await assert.rejects(client.call('commitHeldProvenanceV1',body));
  assert.equal(db.prepare('SELECT consumed FROM approvals').get().consumed,0);
  assert.equal(db.prepare('SELECT COUNT(*) AS count FROM operations').get().count,0);
  db.exec('DROP TRIGGER fail_held');

  // Drop the transport only after the issuer returns the committed receipt.
  const relay = createServer(async (req,res)=>{
    try {
      const chunks=[];
      for await (const chunk of req) chunks.push(chunk);
      await post(issuer.address,'/rpc',JSON.parse(Buffer.concat(chunks).toString()));
    } finally { res.destroy(); }
  });
  await new Promise(resolve=>relay.listen(0,'127.0.0.1',resolve));
  t.after(()=>new Promise(resolve=>relay.close(resolve)));
  await assert.rejects(clientFor('http://127.0.0.1:'+relay.address().port)
    .call('commitHeldProvenanceV1',body));
  assert.equal(db.prepare('SELECT consumed FROM approvals').get().consumed,1);
  assert.equal((await client.call('commitHeldProvenanceV1',body)).disposition,'DENIED');
  const recovered=await client.call('readHeldProvenanceV1',{operation_id:body.operation_id,snapshot_hash});
  assert.equal(recovered.result.operation.state,'HELD');
  assert.equal(recovered.result.operation.retry_permitted,false);
  assert.equal((await client.call('readHeldProvenanceV1',{operation_id:body.operation_id,snapshot_hash:digest('wrong')})).disposition,'DENIED');
});

test('BLOCK readback binds the exact committed control and survives recovery', async t=>{
  const {f,client,control,mapping_id,body,time}=await nativeFixture(t);
  const data={mapping_id,expected_revision:1};
  // Ignore the unsigned body. Retain only the locally computed request digest.
  const block=await control('BLOCK','block-proof',data);
  assert.equal(block.status,200);
  const read={mapping_id,control_reference:'block-proof',control_digest:block.controlDigest,
    blocked_revision:2,expected_generation:f.state.generation,expected_epoch:f.state.epoch};
  for(const change of [{mapping_id:digest('wrong-order-line')},{control_reference:'other'},
    {control_digest:digest('wrong-control')},{blocked_revision:1},{expected_generation:99}]){
    assert.equal((await client.call('readBlockedProvenanceV1',{...read,...change})).disposition,'DENIED');
  }
  const verified=await client.call('readBlockedProvenanceV1',read);
  const proof=verified.result.operation;
  assert.equal(verified.disposition,'HELD');
  assert.equal(proof.schema,'DF_NATIVE_BLOCK_ATTESTATION_1');
  assert.equal(proof.state,'BLOCKED');
  assert.equal(proof.external_execution_authorized,false);
  assert.ok(Object.isFrozen(proof));
  assert.equal((await control('BLOCK','block-proof',data)).status,503);
  assert.equal((await client.call('commitHeldProvenanceV1',body)).disposition,'DENIED');
  const store=new AuthorityStore(f.config.database_path);
  let recovered;
  try {
    const state=store.state();
    recovered=store.recover(state.witness_head,state.inventory_hash,time);
  } finally { store.close(); }
  assert.equal((await client.call('readBlockedProvenanceV1',read)).disposition,'DENIED');
  const current=await client.call('readBlockedProvenanceV1',{...read,
    expected_generation:recovered.generation,expected_epoch:recovered.epoch});
  assert.deepEqual(current.result.operation,proof);
  assert.equal((await control('REVOKE','revoke-after-recovery',{generation:recovered.generation})).status,200);
  assert.equal((await client.call('readBlockedProvenanceV1',{...read,
    expected_generation:recovered.generation,expected_epoch:recovered.epoch})).disposition,'DENIED');
});

test('journal failure leaves no BLOCK proof; concurrent BLOCK serializes with allocation', async t=>{
  const {f,client,body,control,mapping_id}=await nativeFixture(t);
  const db=new DatabaseSync(f.config.database_path);
  t.after(()=>db.close());
  db.exec("CREATE TRIGGER fail_block BEFORE INSERT ON witness_events WHEN json_extract(NEW.event,'$.data.type')='BLOCK' BEGIN SELECT RAISE(ABORT,'injected'); END");
  const data={mapping_id,expected_revision:1};
  const failed=await control('BLOCK','block-fault',data);
  const read={mapping_id,control_reference:'block-fault',control_digest:failed.controlDigest,
    blocked_revision:2,expected_generation:f.state.generation,expected_epoch:f.state.epoch};
  assert.equal(failed.status,503);
  assert.equal((await client.call('readBlockedProvenanceV1',read)).disposition,'DENIED');
  db.exec('DROP TRIGGER fail_block');
  const [block,commit]=await Promise.all([control('BLOCK','block-fault',data),
    client.call('commitHeldProvenanceV1',body)]);
  assert.equal(block.status,200);
  assert.ok(['DENIED','HELD'].includes(commit.disposition));
  assert.equal((await client.call('readBlockedProvenanceV1',read)).disposition,'HELD');
  const events=db.prepare('SELECT event FROM witness_events ORDER BY sequence').all().map(r=>JSON.parse(r.event));
  const blockedAt=events.findIndex(e=>e.data.reference==='block-fault');
  const allocatedAt=events.findIndex(e=>e.type==='OPERATION_HELD_PROVENANCE');
  assert.ok(blockedAt>=0&&(allocatedAt===-1||allocatedAt<blockedAt));
  assert.equal((await client.call('commitHeldProvenanceV1',body)).disposition,'DENIED');
});
