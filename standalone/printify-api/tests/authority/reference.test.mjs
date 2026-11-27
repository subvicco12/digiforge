import assert from 'node:assert/strict';
import { createPrivateKey, createPublicKey } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { canonical, sign } from '../../../../contracts/cloudflare-dispatcher/protocol.mjs';
import { FixtureSequencer, RecoveryAnchor, sendSynthetic, verifyEnvelope } from './reference.mjs';

const now = 1700000000;
const key = createPrivateKey({format:'der',type:'pkcs8',
  key:Buffer.from('302e020100300506032b657004220420'+'42'.repeat(32),'hex')});
const signer = await crypto.subtle.importKey('pkcs8', key.export({type:'pkcs8',format:'der'}), 'Ed25519', false, ['sign']);
const pinnedKey = await crypto.subtle.importKey('spki', createPublicKey(key).export({type:'spki',format:'der'}),
  'Ed25519', false, ['verify']);
const pin = {keyId:'fixture-v1',key:pinnedKey,issuer:'fixture-witness',audience:'fixture-sender'};
const id = {
  operation_id:'a'.repeat(64),correlation_id:'b'.repeat(64),idempotency_id:'c'.repeat(64),
  shop:'12',account:'fixture-account',integration:'3',actor:1,session_generation:2,authorization_revision:3,
  policy_revision:1,payload_digest:'d'.repeat(64),journal_revision:3,event_hash:'e'.repeat(64),
};

function fixture(t, identity = id) {
  const dir = mkdtempSync(join(tmpdir(),'df-authority-'));
  const path = join(dir, 'witness.sqlite');
  const anchor = new RecoveryAnchor(join(dir, 'anchor.sqlite'));
  let seq = new FixtureSequencer(path, anchor);
  const competitors = [];
  t.after(() => { competitors.forEach(other => other.close()); seq.close(); anchor.close(); rmSync(dir,{recursive:true,force:true}); });
  seq.initialize('f'.repeat(64));
  seq.hold(identity);
  return {
    get seq() { return seq; }, anchor,
    competitor() { const other = new FixtureSequencer(path, anchor); competitors.push(other); return other; },
    restart() { seq.close(); seq = new FixtureSequencer(path, anchor); },
    async envelope(changes = {}, keyId = pin.keyId) {
      const {epoch,sequence} = seq.stamp();
      return canonical(await sign(signer,keyId,{...identity,epoch,sequence,version:1,issuer:pin.issuer,
        audience:pin.audience,phase:'SEND_ADMISSION',issued:now,expires:now+15,...changes}));
    },
  };
}

test('signed wire rejects malformed, ambiguous, unknown and expired fields', async t => {
  const f = fixture(t);
  const wire = await f.envelope();
  assert.equal((await verifyEnvelope(wire,pin,now)).operation_id,id.operation_id);
  for (const bad of [' '+wire,wire.replace('"version":1','"version":1,"version":1'),
    wire.replace('"actor":1','"actor":1.0'),wire.replace('"actor":1','"actor":2')]) {
    await assert.rejects(verifyEnvelope(bad,pin,now));
  }
  for (const changes of [{issuer:'foreign'},{audience:'other'},{phase:'HOLD'},{policy_revision:0},{extra:'forbidden'},{expires:now}]) {
    await assert.rejects(verifyEnvelope(await f.envelope(changes),pin,now));
  }
  await assert.rejects(verifyEnvelope(await f.envelope({},'unknown-key'),pin,now));
  await assert.rejects(verifyEnvelope(wire,{...pin,revoked:true},now));
  await assert.rejects(verifyEnvelope(wire,pin,now+30));
});

test('concurrent sender attempts consume once, and STOP drains the admitted attempt', async t => {
  const f = fixture(t);
  const wire = await f.envelope();
  let calls = 0;
  const sink = async () => {
    ++calls;
    f.seq.stop();
    assert.equal(f.seq.state().outcome,'IN_FLIGHT');
  };
  const results = await Promise.allSettled([f.seq,f.competitor()].map(seq => sendSynthetic(wire,pin,()=>now,seq,sink)));
  assert.equal(calls,1);
  assert.equal(results.filter(r=>r.status==='fulfilled').length,1);
  assert.equal(f.seq.state().outcome,'UNKNOWN');
  f.restart();
  await assert.rejects(sendSynthetic(await f.envelope(),pin,()=>now,f.seq,sink));
  assert.equal(calls,1);
});

test('STOP committed first denies a fresh otherwise valid envelope', async t => {
  const f = fixture(t);
  f.seq.stop();
  await assert.rejects(sendSynthetic(await f.envelope(),pin,()=>now,f.seq,
    () => assert.fail('STOP must prevent effect')), /ADMISSION_DENIED/);
  assert.equal(f.seq.state().outcome,null);
});

test('STOP, session revocation and stale/cross-scope bindings prevent any effect', async t => {
  const f = fixture(t);
  const sink = () => assert.fail('sink must remain unreachable');
  for (const changes of [{journal_revision:2},{event_hash:'0'.repeat(64)},{account:'wrong'},
    {operation_id:'1'.repeat(64)},{session_generation:1},{epoch:'2'.repeat(64)},{sequence:1}]) {
    await assert.rejects(sendSynthetic(await f.envelope(changes),pin,()=>now,f.seq,sink));
  }
  const valid = await f.envelope();
  f.seq.revoke();
  await assert.rejects(sendSynthetic(valid,pin,()=>now,f.seq,sink));
  f.seq.stop();
  await assert.rejects(sendSynthetic(await f.envelope(),pin,()=>now,f.seq,sink));
});

test('lost anchor acknowledgment and restored witness never cause a second send', async t => {
  const f = fixture(t);
  const old = canonical(f.seq.state());
  const sink = () => assert.fail('sink must remain unreachable');
  f.anchor.loseAck = true;
  await assert.rejects(sendSynthetic(await f.envelope(),pin,()=>now,f.seq,sink), /ANCHOR_ACK_UNKNOWN/);
  assert.equal(f.seq.state().outcome,'IN_FLIGHT');
  f.restart();
  f.anchor.loseAck = false;
  const receipt = f.seq.state().admission;
  f.seq.quarantine(receipt);
  assert.equal(f.seq.state().outcome,'UNKNOWN');
  await assert.rejects(sendSynthetic(await f.envelope(),pin,()=>now,f.seq,sink));
  f.seq.db.prepare('UPDATE witness SET state=? WHERE id=1').run(old);
  await assert.rejects(sendSynthetic(await f.envelope(),pin,()=>now,f.seq,sink), /RECOVERY_HOLD/);
});

test('anchor outage, missing floor and downstream acknowledgment loss fail closed', async t => {
  const f = fixture(t);
  const wire = await f.envelope();
  const sink = () => assert.fail('sink must remain unreachable');
  f.anchor.offline = true;
  await assert.rejects(sendSynthetic(wire,pin,()=>now,f.seq,sink), /ANCHOR_OFFLINE/);
  f.anchor.offline = false;
  await assert.rejects(sendSynthetic(wire,pin,()=>now,f.seq,()=>{throw Error('ACK_LOST');}), /ACK_LOST/);
  assert.equal(f.seq.state().outcome,'UNKNOWN');
  f.anchor.db.exec('DELETE FROM floor');
  await assert.rejects(sendSynthetic(await f.envelope(),pin,()=>now,f.seq,sink), /ANCHOR_MISSING/);
});

if (process.env.DF_OFFLINE_AUTHORITY_RECEIPT) {
  test('consumes exact post-commit identity exported by the extracted WordPress fixture', async t => {
    const identity = JSON.parse(readFileSync(process.env.DF_OFFLINE_AUTHORITY_RECEIPT,'utf8'));
    const f = fixture(t, identity);
    assert.equal(f.seq.state().outcome,null);
    assert.equal((await sendSynthetic(await f.envelope(),pin,()=>now,f.seq,()=>{})).outcome,'UNKNOWN');
    assert.equal(f.seq.state().admission.operation_id,identity.operation_id);
  });
}
