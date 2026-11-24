import { createPrivateKey, createPublicKey } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import { canonical, sign } from '../../../../contracts/cloudflare-dispatcher/protocol.mjs';
import { FixtureSequencer, RecoveryAnchor } from './reference.mjs';

export const now = 1700000000;
export async function fixtureKey(role) {
  const n = {sender:'11', administrator:'22', anchor:'33', admission:'44'}[role];
  const key = createPrivateKey({format:'der', type:'pkcs8', key:Buffer.from('302e020100300506032b657004220420'+n.repeat(32),'hex')});
  const signer = await crypto.subtle.importKey('pkcs8', key.export({type:'pkcs8',format:'der'}), 'Ed25519', false, ['sign']);
  const pub = await crypto.subtle.importKey('spki', createPublicKey(key).export({type:'spki',format:'der'}),
    'Ed25519', true, ['verify']);
  return { signer, pin:{key:pub,keyId:'fixture-'+role,issuer:'fixture-'+role,audience:'fixture-sender'} };
}

async function run(q) {
  const floor = new RecoveryAnchor(q.anchor);
  const seq = new FixtureSequencer(q.witness, floor, stage => {
    if (q.crash === stage) process.exit(43);
  });
  try {
    if (q.command === 'admit-lost') {
      await seq.admit(q.wire, () => now, (await fixtureKey('admission')).pin);
      // Admission and anchor are durable. Deliberately exit before handing a receipt to any sink.
      process.exitCode = 42;
      return;
    }
    if (q.command === 'status') { return seq.recoveryStatus(); }
    if (q.command === 'sign') {
      const { signer, pin } = await fixtureKey(q.role);
      if (q.role === 'anchor' && canonical(floor.read()) !== canonical(q.statement.stamp)) {
        throw Error('ANCHOR_DIVERGENCE');
      }
      if (q.role === 'sender') {
        const sink = new DatabaseSync(q.sink,{readOnly:true});
        try {
          const row = sink.prepare('SELECT effect FROM effects WHERE operation_id=?').get(q.statement.admission.operation_id);
          if ((row?.effect ?? 'UNOBSERVED') !== q.statement.effect) throw Error('SINK_BINDING');
        } finally { sink.close(); }
      }
      return canonical(await sign(signer, pin.keyId, {
        ...q.statement, version:1, role:q.role.toUpperCase(), issuer:pin.issuer,
        audience:'fixture-recovery', issued:q.issued ?? now, expires:(q.issued ?? now)+15,
      }));
    }
    if (q.command === 'recover') {
      const pins = {};
      for (const role of ['sender','administrator','anchor']) {
        pins[role] = (await fixtureKey(role)).pin;
        pins[role].revoked = role === q.revoked;
      }
      floor.loseAck = q.loseAck === true;
      return await seq.recover(q.wires,pins,()=>now);
    }
    throw Error('UNKNOWN_COMMAND');
  } finally { seq.close(); floor.close(); }
}

if (process.argv[2] === '--worker') {
  try {
    const q = JSON.parse(readFileSync(0,'utf8'));
    const result = await run(q);
    if (result !== undefined) console.log(JSON.stringify(result));
  } catch (error) {
    console.log(JSON.stringify({ denied: error.message }));
    process.exitCode = 1;
  }
}
