import test from 'node:test';
import assert from 'node:assert/strict';
import { authorityFixture } from './authority-support.mjs';

test('ordinary final sender rejects a revoked independent root generation',async()=>{
 const f=await authorityFixture(),session=await f.client.call('SESSION',await f.base.ownerAdmission());
 f.base.custody.perform=fn=>{f.root.change(d=>{d.generation++;});return fn();};
 const result=await f.client.call('EXECUTE',await f.base.message(session,0));
 assert.notEqual(result.receipt?.manifest.status,'OBSERVED');assert.equal(f.base.calls.length,0);
});
test('deployment floor changed after asynchronous guard blocks physical fetch',async()=>{
 const f=await authorityFixture(),session=await f.client.call('SESSION',await f.base.ownerAdmission());
 let material=false;const original=f.base.custody.syntheticAuthorization;
 f.base.custody.syntheticAuthorization=()=>{material=true;return original();};
 const guard=f.service.guard.bind(f.service);
 f.service.guard=async id=>{const checked=await guard(id);if(material)f.root.change(d=>{d.minimum_version=2;});return checked;};
 const result=await f.client.call('EXECUTE',await f.base.message(session,0));
 assert.notEqual(result.receipt?.manifest.status,'OBSERVED');assert.equal(f.base.calls.length,0);
});
test('custody expiry during receipt signing cannot release successful evidence',async()=>{
 const f=await authorityFixture(),session=await f.client.call('SESSION',await f.base.ownerAdmission());
 const receipt=f.base.runtime.signer.receipt;
 f.base.runtime.signer.receipt=async m=>{const signed=await receipt(m);f.base.clock.now+=61;return signed;};
 const result=await f.client.call('EXECUTE',await f.base.message(session,0));
 assert.notEqual(result.receipt?.manifest.status,'OBSERVED');assert.equal(f.base.calls.length,1);
});
async function renewedClient(f){
 const fresh={issued_at:f.base.clock.now,expires_at:f.base.clock.now+60};
 return f.makeClient({certificate:await f.resignCertificate({...f.certificate.manifest,...fresh}),custody:await f.resignCustody({...f.custody.manifest,...fresh})});
}
test('refreshed workload proofs cannot outlive the immutable owner grant at physical send',async()=>{
 const f=await authorityFixture(),session=await f.client.call('SESSION',await f.base.ownerAdmission());
 f.base.clock.now+=290;const client=await renewedClient(f);
 f.base.custody.perform=fn=>{f.base.clock.now+=11;return fn();};
 const result=await client.call('EXECUTE',await f.base.message(session,0));
 assert.notEqual(result.receipt?.manifest.status,'OBSERVED');assert.equal(f.base.calls.length,0);
});
test('fresh outer workload request cannot extend an expired inner client request',async()=>{
 const f=await authorityFixture(),session=await f.client.call('SESSION',await f.base.ownerAdmission());
 const payload=await f.base.message(session,0);f.base.clock.now+=20;
 f.base.custody.perform=fn=>{f.base.clock.now+=11;return fn();};
 const result=await f.client.call('EXECUTE',payload);
 assert.notEqual(result.receipt?.manifest.status,'OBSERVED');assert.equal(f.base.calls.length,0);
});
test('owner expiry during receipt signing discards success and preserves consumed authority',async()=>{
 const f=await authorityFixture(),session=await f.client.call('SESSION',await f.base.ownerAdmission());
 f.base.clock.now+=290;const client=await renewedClient(f),receipt=f.base.runtime.signer.receipt;
 f.base.runtime.signer.receipt=async m=>{const signed=await receipt(m);f.base.clock.now+=11;return signed;};
 const payload=await f.base.message(session,0),result=await client.call('EXECUTE',payload);
 assert.notEqual(result.receipt?.manifest.status,'OBSERVED');assert.equal(f.base.calls.length,1);
 const doc=f.base.state.read().doc,id=session.session+':0';
 assert.equal(doc.claims[id].state,'TERMINAL');assert.equal(doc.nonces[payload.authority.manifest.nonce],true);assert.equal(doc.nonces[payload.authentication.manifest.nonce],true);
 assert.equal(doc.claims[id].receipt.manifest.qualification,'TESTED_SYNTHETIC');assert.equal(doc.claims[id].receipt.manifest.compatibility,'UNVERIFIED');
 assert.equal(f.root.read().leases.includes(id),false);
 const replay=await f.base.message(session,0);
 await assert.rejects(()=>client.call('EXECUTE',replay));assert.equal(f.base.calls.length,1);
});
