import { createPrivateKey, createPublicKey } from 'node:crypto';
import { b64, hash } from '../protocol.mjs';
import { CustodyBridge } from '../custody-bridge/bridge.mjs';
import { LocalSqliteStorage } from './support.mjs';

// Test source only. Public fixture seed, no environmental secrets or network port.
const seed=await hash('public-test-only-custody-mock-v1');
const pkcs8=Buffer.from('302e020100300506032b657004220420'+seed,'hex');
const privateKey=await crypto.subtle.importKey('pkcs8',pkcs8,'Ed25519',false,['sign']);
const publicDer=createPublicKey(createPrivateKey({key:pkcs8,format:'der',type:'pkcs8'}))
  .export({format:'der',type:'spki'});
const signer_key=b64(publicDer.subarray(-32));
const [filename,windowStart]=process.argv.slice(2);
const start=Number(windowStart);
if (!filename || !Number.isSafeInteger(start) || start<=0) process.exit(2);
let wire=Buffer.alloc(0);
for await (const chunk of process.stdin) {
  if (wire.length+chunk.length>4097) process.exit(2);
  wire=Buffer.concat([wire,chunk]);
}
if (wire[wire.length-1]!==10) process.exit(2);
const bridge=new CustodyBridge(new LocalSqliteStorage(filename),{
  shop:'107',account_reference:'fixture-account',credential_reference:'fixture-custody-read',
  source_sha:'b'.repeat(40),source_revision:2,authorization_revision:4,signer_key,
  start,end:start+120,
},()=>Math.floor(Date.now()/1000),bytes=>crypto.subtle.sign('Ed25519',privateKey,bytes));
process.stdout.write(JSON.stringify(await bridge.exchange(wire.subarray(0,-1).toString('utf8'))));
