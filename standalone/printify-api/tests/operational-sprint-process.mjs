import { createPrivateKey, createPublicKey } from 'node:crypto';
import { createServer, get } from 'node:http';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import { b64, canonical, hash } from '../../../contracts/cloudflare-dispatcher/protocol.mjs';
import { ProviderReadTransport } from '../../../contracts/cloudflare-dispatcher/provider-read/transport.mjs';

const scope={shop:'107',account_reference:'fixture-account',credential_reference:'fixture-custody-read',
  source_sha:'b'.repeat(40),source_revision:2,authorization_revision:4};
const seed=await hash('public-test-only-custody-mock-v1');
const pkcs8=Buffer.from('302e020100300506032b657004220420'+seed,'hex');
const key=await crypto.subtle.importKey('pkcs8',pkcs8,'Ed25519',false,['sign']);
scope.signer_key=b64(createPublicKey(createPrivateKey({key:pkcs8,format:'der',type:'pkcs8'}))
  .export({format:'der',type:'spki'}).subarray(-32));
const known={
  '/v1/catalog/print_providers.json':[{id:7,title:'Loopback fixture printer'}],
  '/v1/shops/107/products/product-1.json':{id:'product-1',shop_id:107,blueprint_id:42,print_provider_id:7,
    variants:[{id:9,price:1299,is_enabled:true}],print_areas:[]},
  '/v1/shops/107/orders/order-1.json':{id:'order-1',shop_id:107,status:'fulfilled',
    line_items:[{id:'line-1',product_id:'product-1',variant_id:9,quantity:1}],
    shipments:[{carrier:'Fixture carrier',number:'fixture-tracking',url:'https://example.invalid/tracking'}]},
};
let requests=0;
const server=createServer((req,res)=>{
  ++requests;
  if (req.method!=='GET' || !Object.hasOwn(known,req.url) ||
      !req.headers.authorization?.startsWith('Bearer fixture-')) {
    res.writeHead(403).end(); return;
  }
  res.writeHead(200,{'content-type':'application/json'}).end(JSON.stringify(known[req.url]));
});
const directory=mkdtempSync(join(tmpdir(),'df-provider-fixture-'));
const socketPath=join(directory,'provider.sock');
try {
  await new Promise((resolve,reject)=>server.once('error',reject).listen(socketPath,resolve));
  let wire='';
  for await (const chunk of process.stdin) {
    wire+=chunk;
    if (wire.length>4097) throw new Error('Fixture request limit');
  }
  wire=wire.replace(/\n$/,'');
  let spent, finalized;
  const authority={
    reserve:async binding=>{
      if (spent || !Object.hasOwn(known,binding.endpoint)) return null;
      if (binding.operation==='PRODUCT_READ' && binding.typed_request_sha256!==await hash(canonical({
        operation:'PRODUCT_READ',provider_shop_id:'107',method:'GET',endpoint:binding.endpoint,body:[],
        required_scope:'products.read', input:{product_id:'product-1',blueprint_id:42,provider_id:7,variant_ids:[9]},
      }))) return null;
      spent=binding.operation_id;
      return {state:'ADMITTED',token:'f'.repeat(64)};
    },
    current:async (binding,token)=>binding.operation_id===spent && token==='f'.repeat(64) && !finalized,
    finish:async (binding,token,state)=>{
      if (!await authority.current(binding,token)) return false;
      finalized=state;
      return true;
    },
  };
  const connector={get:async(binding,{signal})=>{
    if (!Object.hasOwn(known,binding.endpoint)) throw new Error('Loopback fixture route only');
    const observed=await new Promise((resolve,reject)=>{
      get({socketPath,path:binding.endpoint,signal,headers:{authorization:'Bearer fixture-disposable'}},resolve)
        .once('error',reject);
    });
    return {status:observed.statusCode,headers:{'content-type':observed.headers['content-type']},
      body:Readable.toWeb(observed)};
  }};
  const transport=new ProviderReadTransport({scope,authority,connector,
    sign:bytes=>crypto.subtle.sign('Ed25519',key,bytes),clock:()=>Math.floor(Date.now()/1000)});
  const response=await transport.exchange(wire);
  if (requests!==1 || finalized!=='COMPLETED' || !response.record) {
    throw new Error('Fixture transport did not make exactly one admitted loopback GET: '+JSON.stringify(
      {requests,finalized,state:response?.state,reason:response?.reason}));
  }
  process.stdout.write(JSON.stringify(response));
} finally {
  try {
    server.closeAllConnections();
    if (server.listening) await new Promise(resolve=>server.close(resolve));
  } finally {
    rmSync(directory,{recursive:true,force:true});
  }
}
