import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DatabaseSync } from 'node:sqlite';
import { test } from 'node:test';
import { canonical, sign } from '../../../../contracts/cloudflare-dispatcher/protocol.mjs';
import { FixtureSequencer, RecoveryAnchor } from './reference.mjs';
import { fixtureKey, now } from './recovery-process.mjs';

const worker = fileURLToPath(new URL('./recovery-process.mjs', import.meta.url));
const fixtureIdentity = {
  operation_id:'a'.repeat(64),correlation_id:'b'.repeat(64),idempotency_id:'c'.repeat(64),
  shop:'12',account:'fixture-account',integration:'3',actor:1,session_generation:2,authorization_revision:3,
  policy_revision:1,payload_digest:'d'.repeat(64),journal_revision:3,event_hash:'e'.repeat(64),
};
const id = process.env.DF_OFFLINE_AUTHORITY_RECEIPT
  ? JSON.parse(readFileSync(process.env.DF_OFFLINE_AUTHORITY_RECEIPT, 'utf8')) : fixtureIdentity;
const epoch = 'f'.repeat(64);
const next = '1'.repeat(64);

async function setup(t) {
  const dir = mkdtempSync(join(tmpdir(),'df-recovery-process-'));
  const paths = {witness:join(dir,'witness.sqlite'),anchor:join(dir,'anchor.sqlite'),sink:join(dir,'sink.sqlite')};
  const anchor = new RecoveryAnchor(paths.anchor);
  const seq = new FixtureSequencer(paths.witness, anchor);
  const sink = new DatabaseSync(paths.sink);
  sink.exec('CREATE TABLE effects(operation_id TEXT PRIMARY KEY, effect TEXT NOT NULL)');
  t.after(() => { sink.close(); seq.close(); anchor.close(); rmSync(dir,{recursive:true,force:true}); });
  const command = q => {
    const r = spawnSync(process.execPath,[worker,'--worker'],{input:JSON.stringify({...paths,...q}),encoding:'utf8',timeout:8000});
    assert.ifError(r.error);
    return { status:r.status, value:r.stdout ? JSON.parse(r.stdout) : undefined };
  };
  const parallel = q => new Promise((resolve,reject) => {
    const c = spawn(process.execPath,[worker,'--worker'],{stdio:['pipe','pipe','inherit'],timeout:8000});
    let result = '';
    c.stdout.setEncoding('utf8').on('data',s=>result+=s);
    c.on('error',reject);
    c.on('close',status=>{
      try { resolve({status,value:JSON.parse(result)}); } catch (error) { reject(error); }
    });
    c.stdin.end(JSON.stringify({...paths,...q}));
  });
  seq.initialize(epoch);
  seq.hold(id);
  const {signer,pin} = await fixtureKey('admission');
  const wire = canonical(await sign(signer,pin.keyId,{...id,version:1,issuer:pin.issuer,audience:pin.audience,
    phase:'SEND_ADMISSION',epoch,sequence:seq.stamp().sequence,issued:now,expires:now+15}));
  const lost = command({command:'admit-lost',wire});
  assert.equal(lost.status,42);
  assert.equal(lost.value,undefined);
  const statement = changes => {
    const status = command({command:'status'}).value;
    return {stamp:status.stamp,admission:status.admission,journal_revision:id.journal_revision,
      journal_head:id.event_hash,next_epoch:next,effect:'UNOBSERVED',terminal:'UNKNOWN',...changes};
  };
  const wires = (msg, changes = {}) => Object.fromEntries(['sender','administrator','anchor'].map(role=>{
    const r = command({command:'sign',role,statement:msg,...changes});
    assert.equal(r.status,0,JSON.stringify(r.value));
    return [role,r.value];
  }));
  return {seq,anchor,sink,command,parallel,statement,wires};
}

test('lost admission acknowledgment survives process exit and concurrent recovery advances exactly once', async t => {
  const f = await setup(t);
  assert.equal(f.command({command:'status'}).value.outcome,'IN_FLIGHT');
  assert.equal(f.sink.prepare('SELECT COUNT(*) n FROM effects').get().n,0);
  const beforeStop = f.wires(f.statement());
  assert.equal(f.command({command:'recover',wires:beforeStop}).value.denied,'RECOVERY_STOP_REQUIRED');
  f.seq.stop();
  const wires = f.wires(f.statement());
  const results = await Promise.all([f.parallel({command:'recover',wires}),f.parallel({command:'recover',wires})]);
  assert.deepEqual(results.map(r=>r.status).sort(),[0,1]);
  const after = f.command({command:'status'}).value;
  assert.equal(after.stamp.epoch,next);
  assert.equal(after.effect,'UNOBSERVED');
  assert.equal(after.outcome,'UNKNOWN');
  assert.equal(after.stopped,true);
  assert.equal(f.sink.prepare('SELECT COUNT(*) n FROM effects').get().n,0);
  assert.equal(f.command({command:'recover',wires}).status,1);
});

test('signer revocation, stale proof and restored older journal deny', async t => {
  const f = await setup(t);
  f.seq.stop();
  const wires = f.wires(f.statement());
  assert.equal(f.command({command:'recover',wires,revoked:'administrator'}).status,1);
  assert.equal(f.command({command:'recover',wires:f.wires(f.statement(),{issued:now-30})}).status,1);
  assert.equal(f.command({command:'recover',wires:f.wires(f.statement({journal_revision:2}))}).value.denied,'RECOVERY_STALE');
  assert.equal(f.command({command:'recover',wires:{...wires,anchor:wires.administrator}}).status,1);
  assert.equal(f.command({command:'sign',role:'sender',statement:f.statement({effect:'ACKNOWLEDGED'})}).value.denied,'SINK_BINDING');
  assert.equal(f.command({command:'status'}).value.outcome,'IN_FLIGHT');
});

test('missing anchor, old witness restore, and committed epoch with lost anchor acknowledgment fail closed', async t => {
  const f = await setup(t);
  const older = canonical(f.seq.state());
  f.seq.stop();
  const wires = f.wires(f.statement());
  const pending = canonical(f.seq.state());
  f.seq.db.prepare('UPDATE witness SET state=? WHERE id=1').run(older);
  assert.equal(f.command({command:'recover',wires}).value.denied,'RECOVERY_HOLD');
  f.seq.db.prepare('UPDATE witness SET state=? WHERE id=1').run(pending);
  const floor = f.anchor.read();
  f.anchor.db.exec('DELETE FROM floor');
  assert.equal(f.command({command:'recover',wires}).value.denied,'ANCHOR_MISSING');
  f.anchor.initialize(floor);
  assert.equal(f.command({command:'recover',wires,loseAck:true}).value.denied,'ANCHOR_ACK_UNKNOWN');
  const status = f.command({command:'status'}).value;
  assert.equal(status.stamp.epoch,next);
  assert.equal(status.outcome,'UNKNOWN');
  assert.equal(status.stopped,true);
  assert.equal(f.command({command:'recover',wires}).status,1);
});


test('crash before epoch commit rolls back; crash after commit without floor acknowledgment holds', async t => {
  const f = await setup(t);
  f.seq.stop();
  const wires = f.wires(f.statement());
  const before = f.command({command:'status'}).value;
  assert.equal(f.command({command:'recover',wires,crash:'before-commit'}).status,43);
  assert.deepEqual(f.command({command:'status'}).value,before);
  assert.equal(f.command({command:'recover',wires,crash:'after-commit'}).status,43);
  assert.equal(f.anchor.read().epoch,epoch);
  assert.equal(f.seq.state().epoch,next);
  assert.equal(f.command({command:'status'}).value.denied,'RECOVERY_HOLD');
  assert.equal(f.command({command:'recover',wires}).value.denied,'RECOVERY_HOLD');
  assert.equal(f.seq.state().stopped,true);
  assert.equal(f.sink.prepare('SELECT COUNT(*) n FROM effects').get().n,0);
});

test('witness ahead repairs only a proven successor, with exactly one anchor CAS', async t => {
  const f = await setup(t);
  f.seq.stop();
  const statement = f.statement();
  const wires = f.wires(statement);
  assert.equal(f.command({command:'recover',wires,crash:'after-commit'}).status,43);
  assert.equal(f.command({command:'status'}).value.denied,'RECOVERY_HOLD');
  assert.equal(f.command({command:'reconcile',wires,revoked:'anchor'}).status,1);
  assert.equal(f.command({command:'reconcile',wires:{...wires,sender:wires.administrator}}).status,1);
  assert.equal(f.command({command:'reconcile',wires:f.wires(statement,{issued:now-30})}).status,1);
  assert.equal(f.command({command:'reconcile',wires:f.wires({...statement,next_epoch:'2'.repeat(64)})}).value.denied,'DIVERGENCE_SUCCESSOR');
  assert.equal(f.seq.state().stopped,true);
  const results = await Promise.all([f.parallel({command:'reconcile',wires}),f.parallel({command:'reconcile',wires})]);
  assert.deepEqual(results.map(r=>r.status).sort(),[0,1]);
  const final = f.command({command:'status'}).value;
  assert.equal(final.stamp.epoch,next);
  assert.equal(final.stamp.sequence,statement.stamp.sequence+1);
  assert.equal(final.outcome,'UNKNOWN');
  assert.deepEqual(final.admission,statement.admission);
  assert.equal(final.stopped,true);
  assert.equal(f.sink.prepare('SELECT COUNT(*) n FROM effects').get().n,0);
});

test('anchor ahead of restored witness, absent floor and lost repair acknowledgment fail closed', async t => {
  const f = await setup(t);
  f.seq.stop();
  const prior = canonical(f.seq.state());
  const floor = f.anchor.read();
  const wires = f.wires(f.statement());
  assert.equal(f.command({command:'recover',wires,crash:'after-commit'}).status,43);
  const committed = canonical(f.seq.state());
  f.anchor.db.exec('DELETE FROM floor');
  assert.equal(f.command({command:'reconcile',wires}).value.denied,'ANCHOR_MISSING');
  f.anchor.initialize(floor);
  assert.equal(f.command({command:'reconcile',wires,loseAck:true}).value.denied,'ANCHOR_ACK_UNKNOWN');
  assert.equal(f.command({command:'status'}).value.outcome,'UNKNOWN');
  assert.equal(f.command({command:'reconcile',wires}).status,1);
  const anchored = f.anchor.read();
  f.seq.db.prepare('UPDATE witness SET state=? WHERE id=1').run(prior);
  assert.equal(f.command({command:'reconcile',wires}).status,1);
  assert.equal(f.command({command:'status'}).value.denied,'RECOVERY_HOLD');
  assert.deepEqual(f.anchor.read(),anchored);
  assert.equal(f.seq.state().stopped,true);
  // Restoring the exact committed successor returns UNKNOWN; it never replays an effect.
  f.seq.db.prepare('UPDATE witness SET state=? WHERE id=1').run(committed);
  assert.equal(f.command({command:'status'}).value.outcome,'UNKNOWN');
  assert.equal(f.sink.prepare('SELECT COUNT(*) n FROM effects').get().n,0);
});
