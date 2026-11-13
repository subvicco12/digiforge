import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { LocalSqliteStorage } from './support.mjs';
import { AuthorityJournal } from '../authority-journal.mjs';

function rootFixture(filename=':memory:') {
 const storage=new LocalSqliteStorage(filename);
 const root=new AuthorityJournal(storage,()=>1000);
 root.initialize({epoch:'e'.repeat(64),namespace:'d'.repeat(64),code:'c'.repeat(64),account:'synthetic-account',credential_binding:'a'.repeat(64)}, {revision:0,hash:'b'.repeat(64)}, 'synthetic-workload-key');
 return {root,storage};
}
for (const [field,value] of [['generation',0],['generation',-1],['generation','1'],['stopped',0],['recovery','false'],['leases',['same','same']],['leases',Array(11).fill('lease')],['nonce_count',-1],['minimum_version',0],['last_time','1000']]) {
 test(`persisted authority rejects corrupt ${field}=${JSON.stringify(value)}`,()=>{
  const {root,storage}=rootFixture();try{
   const d=root.read();d[field]=value;
   storage.db.prepare('UPDATE df_security_authority SET document=? WHERE id=1').run(JSON.stringify(d));
   assert.throws(()=>root.active(),/ROOT_CORRUPT/);
  }finally{storage.db.close();}
 });
}
test('generation exhaustion cannot persist an unsafe integer or rearm root',()=>{
 const {root,storage}=rootFixture();try{
  const d=root.read();d.generation=Number.MAX_SAFE_INTEGER;
  storage.db.prepare('UPDATE df_security_authority SET document=? WHERE id=1').run(JSON.stringify(d));
  assert.throws(()=>root.stop(),/ROOT_CORRUPT|ROOT_GENERATION_EXHAUSTED/);
  assert.equal(root.read().generation,Number.MAX_SAFE_INTEGER);
  assert.throws(()=>root.active(),/ROOT_GENERATION_EXHAUSTED/);
 }finally{storage.db.close();}
});
test('committed UNKNOWN lease survives SQLite reopen and startup fence',()=>{
 const dir=mkdtempSync(join(tmpdir(),'df-root-crash-'));const filename=join(dir,'root.sqlite');
 let storage;
 try{
  let f=rootFixture(filename);storage=f.storage;f.root.boot();f.root.lease('synthetic-inflight');storage.db.close();storage=null;
  storage=new LocalSqliteStorage(filename);const reopened=new AuthorityJournal(storage,()=>1001);
  assert.deepEqual(reopened.read().leases,['synthetic-inflight']);
  reopened.boot();assert.equal(reopened.read().recovery,true);assert.equal(reopened.read().stopped,true);
  assert.deepEqual(reopened.read().leases,['synthetic-inflight']);assert.throws(()=>reopened.active(),/ROOT_REVOKED/);
 }finally{storage?.db.close();rmSync(dir,{recursive:true,force:true});}
});
for (const stage of ['headers','body']) {
 test(`expired signed custody during ${stage} cannot produce accepted evidence`,async()=>{
  const {authorityFixture}=await import('./authority-support.mjs');const f=await authorityFixture();
  const custody=await f.resignCustody({...f.custody.manifest,expires_at:f.base.clock.now+1});
  const client=f.makeClient({custody});
  const s=await client.call('SESSION',await f.base.ownerAdmission());
  if(stage==='headers')f.base.provider.during=()=>{f.base.clock.now+=2;};
  else f.base.provider.fetchOverride=()=>new Response(new ReadableStream({pull(c){f.base.clock.now+=2;c.enqueue(new TextEncoder().encode('{}'));c.close();}},{highWaterMark:0}));
  const r=await client.call('EXECUTE',await f.base.message(s,0));
  assert.ok(f.certificate.manifest.expires_at>f.base.clock.now);
  assert.notEqual(r.receipt?.manifest.status,'OBSERVED');assert.equal(r.status,'UNKNOWN');
  assert.equal(r.retry_permitted,false);assert.equal(f.root.read().leases.length,1);
 });
}
for(const phase of ['before_commit','after_commit']){
 test(`killed SQLite authority worker ${phase} cannot silently rearm`,async()=>{
  const {spawnSync}=await import('node:child_process');const dir=mkdtempSync(join(tmpdir(),'df-kill-root-'));const filename=join(dir,'root.sqlite');let storage;
  try{
   const f=rootFixture(filename);storage=f.storage;storage.db.exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL');f.root.boot();storage.db.close();storage=null;
   const child=spawnSync(process.execPath,[new URL('./root-crash-probe.mjs',import.meta.url).pathname,filename,phase],{timeout:8000,encoding:'utf8'});
   assert.equal(child.signal,'SIGKILL',child.stderr);assert.equal(child.error,undefined);
   storage=new LocalSqliteStorage(filename);const root=new AuthorityJournal(storage,()=>1002);root.boot();
   assert.equal(root.read().recovery,true);assert.equal(root.read().stopped,true);
   assert.equal(root.read().leases.length,phase==='after_commit'?1:0);
   assert.throws(()=>root.active(),/ROOT_REVOKED/);
  }finally{storage?.db.close();rmSync(dir,{recursive:true,force:true});}
 });
}
test('wrong lease identity cannot complete another in-flight owner',()=>{
 const {root,storage}=rootFixture();try{
  root.boot();root.lease('owner-A:slot-1');
  assert.throws(()=>root.complete('owner-B:slot-1'),/ROOT_LEASE_MISSING/);
  assert.throws(()=>root.lease('owner-A:slot-1'),/ROOT_REVOKED/);
  assert.deepEqual(root.read().leases,['owner-A:slot-1']);
 }finally{storage.db.close();}
});
test('generation revocation retains in-flight lease instead of acknowledging empty drain',()=>{
 const {root,storage}=rootFixture();try{
  root.boot();root.lease('owner-A:slot-1');const generation=root.read().generation;
  root.stop();assert.equal(root.read().generation,generation+1);
  assert.deepEqual(root.read().leases,['owner-A:slot-1']);
  assert.throws(()=>root.lease('owner-B:slot-1'),/ROOT_REVOKED/);
 }finally{storage.db.close();}
});
