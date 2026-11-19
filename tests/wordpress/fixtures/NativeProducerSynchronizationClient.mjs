// Disposable tests only. Reuse the pinned native wire implementation.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { realpathSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import assert from 'node:assert/strict';

export const NATIVE_SOURCE = '805f545ed23cc1c786943d59b4fb08def01a4e96';

export async function pinnedNative(root) {
  root = realpathSync(root);
  const git = (...args) => execFileSync('git', ['-C', root, ...args], {encoding:'utf8'}).trim();
  assert.equal(git('rev-parse','HEAD'), NATIVE_SOURCE);
  assert.equal(git('status','--porcelain','--untracked-files=all','--ignored','--','services/native-authority'), '');
  const load = file => import(pathToFileURL(join(root,'services/native-authority',file)).href);
  return {load, ...(await load('protocol.mjs')), ...(await load('client.mjs'))};
}

export async function disposablePublicationClient(root, clientOptions) {
  const {AuthorityClient, digest, canonical} = await pinnedNative(root);
  const url = new URL(clientOptions.url);
  assert.equal(url.protocol, 'http:');
  assert.equal(url.hostname, '127.0.0.1');
  const client = new AuthorityClient(clientOptions);

  function original(control, type, checkpoint) {
    const m = control?.owner?.message;
    assert.equal(canonical(m), canonical(control?.recovery?.message));
    assert.equal(m.schema, 'DF_NATIVE_PROVENANCE_CONTROL_1');
    assert.equal(m.version, '1.0.0');
    assert.equal(m.type, type);
    assert.equal(canonical(m.binding), canonical(clientOptions.binding));
    assert.equal(m.data.generation, checkpoint.generation);
    if (type === 'PREPARE_MAP_V1') assert.equal(m.data.checkpoint_epoch, checkpoint.epoch);
    return {
      mapping_id:m.data.mapping_id, control_reference:m.reference, control_digest:digest(m),
      expected_generation:checkpoint.generation, expected_epoch:checkpoint.epoch,
    };
  }

  return Object.freeze({
    async readBlock(control, checkpoint) {
      const fields = original(control, 'BLOCK', checkpoint);
      const reply = await client.call('readBlockedProvenanceV1', {
        ...fields, blocked_revision:control.owner.message.data.expected_revision+1,
      });
      assert.equal(reply.disposition, 'HELD');
      assert.equal(reply.result.operation.state, 'BLOCKED');
      return reply.result.operation;
    },
    async readPrepare(control, checkpoint) {
      const fields = original(control, 'PREPARE_MAP_V1', checkpoint);
      const data = control.owner.message.data;
      const revision = data.expected_revision+1;
      const reply = await client.call('readMapPublicationV1', {
        ...fields, phase:'PREPARE', committed_revision:revision,
        snapshot_hash:createHash('sha256').update(data.snapshot_json).digest('hex'),
      });
      assert.equal(reply.disposition, 'HELD');
      assert.equal(reply.result.operation.mapping_state, 'BLOCKED');
      assert.equal(reply.result.operation.mapping_revision, revision);
      return reply.result.operation;
    },
  });
}

// Run explicitly with DF_PINNED_NATIVE_ROOT set to the independent checkout.
if (process.env.DF_PINNED_NATIVE_ROOT && process.argv[1] &&
    import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) {
  const {test} = await import('node:test');
  const root = process.env.DF_PINNED_NATIVE_ROOT;
  test('pinned issuer and witness attest a blocked prepared snapshot', async t => {
    const {load, sign, digest} = await pinnedNative(root);
    const {witnessFixture,post} = await load('test/server-fixtures.mjs');
    const {startAuthorityServer} = await load('server.mjs');
    const {generateKeyPairSync,sign:signBytes}=await import('node:crypto');
    const fixture=witnessFixture(t,{native:true});
    const witness=await startAuthorityServer(fixture.path,'witness');
    t.after(()=>witness.close());
    const issuerPath=fixture.write('issuer.json',{
      ...fixture.config,role:'issuer',database_path:null,witness_url:witness.address,
      credentials_path:fixture.write('credentials.json',[]),payloads_path:fixture.write('payloads.json',[]),
      keys:{...fixture.config.keys,signing_key_id:'issuer',signing_private_key_path:fixture.write('issuer.pem',
        fixture.pairs.issuer.privateKey.export({type:'pkcs8',format:'pem'}))},
    });
    const issuer=await startAuthorityServer(issuerPath,'issuer');
    t.after(()=>issuer.close());
    const options={url:issuer.address,binding:fixture.binding,keyId:'workload',
      privateKey:fixture.pairs.workload.privateKey,
      issuerKeys:new Map([['issuer',fixture.pairs.issuer.publicKey]]),
      witnessKeys:new Map([['witness',fixture.pairs.witness.publicKey]])};
    const port=await disposablePublicationClient(root,options);
    const producer=generateKeyPairSync('ed25519'), time=Math.floor(Date.now()/1000);
    const checkpoint={generation:fixture.state.generation,epoch:fixture.state.epoch};
    async function submit(type,reference,data){
      const message={schema:'DF_NATIVE_PROVENANCE_CONTROL_1',version:'1.0.0',reference,
        binding:fixture.binding,issued_at:time-1,expires_at:time+60,type,
        data:{producer:'fixture-producer',generation:checkpoint.generation,...data}};
      const control={
        owner:sign('held.provenance.control.owner.v1',message,fixture.pairs.owner.privateKey,'owner'),
        recovery:sign('held.provenance.control.recovery.v1',message,fixture.pairs.recovery.privateKey,'recovery'),
      };
      assert.equal((await post(witness.address,'/control',control)).status,200);
      return control;
    }
    const source_sha='a'.repeat(40), artwork_source_hash='b'.repeat(64);
    const der=producer.publicKey.export({type:'spki',format:'der'});
    await submit('ENROLL','fixture-enroll',{source_sha,key_der:der.toString('base64url'),
      key_pin:createHash('sha256').update(der).digest('hex'),business_id:1,etsy_shop_id:'etsy-shop'});
    const mapping_id=digest(['fixture-producer','etsy-order','etsy-line']);
    const snapshot={business_id:1,shop_id:2,shop_key:'personalized_pod',etsy_shop_id:'etsy-shop',
      listing_id:'etsy-listing',order_id:'etsy-order',line_id:'etsy-line',product_id:'source-product',
      product_version:1,artwork_source_hash,snapshot_revision:2,producer:'fixture-producer',source_sha,
      issued_at:time-1,expires_at:time+40};
    const native={native_order_id:'order',native_line_id:'line',native_listing_id:'listing',
      native_product_id:'product',order_version:1};
    await submit('MAP','fixture-initial-map',{...native,mapping_id,expected_revision:0,snapshot_revision:1,
      snapshot_hash:digest('initial'),order_id:snapshot.order_id,line_id:snapshot.line_id,
      listing_id:snapshot.listing_id,product_id:snapshot.product_id,product_version:1,
      artwork_source_hash,expires_at:snapshot.expires_at});
    const block=await submit('BLOCK','fixture-block',{mapping_id,expected_revision:1});
    const blocked=await port.readBlock(block,checkpoint);
    const snapshot_json=JSON.stringify(snapshot);
    const preparedControl=await submit('PREPARE_MAP_V1','fixture-prepare',{...native,mapping_id,
      expected_revision:blocked.blocked_revision,checkpoint_epoch:checkpoint.epoch,
      block_control_digest:blocked.control_digest,snapshot_json,
      producer_signature:signBytes(null,Buffer.from('DF_ETSY_NATIVE_PROVENANCE_1\n'+snapshot_json),
        producer.privateKey).toString('base64url')});
    const prepared=await port.readPrepare(preparedControl,checkpoint);
    assert.equal(prepared.mapping_state,'BLOCKED');
    assert.equal(prepared.external_execution_authorized,false);
    await assert.rejects(port.readPrepare(preparedControl,{...checkpoint,epoch:'c'.repeat(64)}));
    const wrongWitness=await disposablePublicationClient(root,{...options,
      witnessKeys:new Map([['witness',producer.publicKey]])});
    await assert.rejects(wrongWitness.readPrepare(preparedControl,checkpoint));
  });
}
