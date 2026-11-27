import test from 'node:test';
import assert from 'node:assert/strict';
import { b64, canonical, hash } from '../protocol.mjs';
import { ProviderReadTransport } from '../provider-read/transport.mjs';

const signer = await crypto.subtle.generateKey('Ed25519', true, ['sign','verify']);
const scope = {
  shop:'107',account_reference:'account-a',credential_reference:'opaque-v2',
  source_sha:'a'.repeat(40),source_revision:3,authorization_revision:2,
  signer_key:b64(await crypto.subtle.exportKey('raw',signer.publicKey)),
};
const enc=new TextEncoder();
async function wire(op='ORDER_READ', endpoint='/v1/shops/107/orders/order-1.json', required_scope='orders.read', changes={}) {
  const typed_request_sha256=await hash(canonical({
    operation:op,method:'GET',endpoint,required_scope,body:[],provider_shop_id:scope.shop,
  }));
  const {signer_key,...binding}=scope;
  return JSON.stringify({schema:'DF_PRINTIFY_CUSTODY_READ_REQUEST_1',
    evidence_class:'SIGNED_PROVIDER_TRANSPORT_SIMULATION',operation_id:'b'.repeat(64),provider:'printify',
    ...binding,operation:op,method:'GET',endpoint,required_scope,typed_request_sha256,
    timeout_ms:2000,max_response_bytes:262144,...changes});
}
function mock(response={status:200,headers:{'content-type':'application/json'},
  body:'{"id":"order-1","shop_id":"107","status":"fulfilled","line_items":[]}'}) {
  const attempts=new Map(), events=[];
  let sends=0,revoked=false;
  const authority={
    reserve(binding) {
      if (attempts.has(binding.operation_id)) return null;
      attempts.set(binding.operation_id,'PENDING');
      return {state:'ADMITTED',token:'c'.repeat(64)};
    },
    current:()=>!revoked,
    finish(binding,token,state) {
      if (attempts.get(binding.operation_id)!=='PENDING' || token!=='c'.repeat(64)) return false;
      if (state==='COMPLETED'&&revoked) return false;
      attempts.set(binding.operation_id,state);
      return true;
    },
  };
  const connector={async get(binding,{maxResponseBytes,signal}) {
    ++sends;
    assert.equal(maxResponseBytes,262144);
    assert.equal(binding.credential_reference,'opaque-v2');
    assert.equal(signal.aborted,false);
    if (typeof response==='function') return response(()=>{revoked=true;},signal);
    return {...response,body:(async function*(){yield enc.encode(response.body);})()};
  }};
  const read=new ProviderReadTransport({scope,authority,connector,clock:()=>1700000000,
    sign:bytes=>crypto.subtle.sign('Ed25519',signer.privateKey,bytes),
    telemetry:event=>events.push(event)});
  return {read,events,attempts,sends:()=>sends};
}
test('signed simulation receipt binds exact request and body; duplicate never reaches connector',async()=>{
  const sut=mock();
  const raw=await wire();
  const outcomes=await Promise.all([sut.read.exchange(raw),sut.read.exchange(raw)]);
  const receipt=outcomes.find(x=>x.record);
  assert.ok(receipt);
  assert.equal(JSON.parse(receipt.record).request_sha256,await hash(raw));
  assert.equal(JSON.parse(receipt.record).content_sha256,await hash(receipt.body));
  assert.equal(JSON.parse(receipt.record).evidence_class,'SIGNED_PROVIDER_TRANSPORT_SIMULATION');
  assert.equal(await crypto.subtle.verify('Ed25519',signer.publicKey,
    Buffer.from(receipt.signature,'base64'),enc.encode('DF_PRINTIFY_CUSTODY_READ_RECEIPT_1\n'+receipt.record)),true);
  assert.equal(outcomes.find(x=>!x.record).state,'DENIED');
  assert.equal(sut.sends(),1);
  assert.ok(!JSON.stringify(sut.events).includes('opaque-v2') && !JSON.stringify(sut.events).includes('account-a'));
});
test('cross-shop, stale revision, writes, altered typed hash and unbounded paging deny before dispatch',async()=>{
  const sut=mock();
  for (const changes of [{shop:'108'},{authorization_revision:1},{method:'POST'},{typed_request_sha256:'f'.repeat(64)},
    {evidence_class:'SIGNED_CUSTODY_FIXTURE'}]) assert.equal((await sut.read.exchange(await wire(undefined,undefined,undefined,changes))).state,'DENIED');
  assert.equal((await sut.read.exchange(await wire('ORDERS_READ','/v1/shops/107/orders.json?page=4&limit=10'))).state,'DENIED');
  assert.equal(sut.sends(),0);
});
test('scoped pagination validates metadata and never follows provided URLs',async()=>{
  const raw=await wire('ORDERS_READ','/v1/shops/107/orders.json?page=2&limit=1');
  const good=mock({status:200,headers:{'content-type':'application/json'},
    body:'{"data":[{"id":"ord-1","shop_id":"107","status":"fulfilled","line_items":[]}],"current_page":2,"per_page":1,"last_page":4}'});
  assert.equal(JSON.parse((await good.read.exchange(raw)).record).status,200);
  assert.equal(good.sends(),1);
  const foreign=mock({status:200,headers:{'content-type':'application/json'},
    body:'{"data":[{"id":"ord-1","shop_id":"108","status":"fulfilled","line_items":[]}],"current_page":2,"per_page":1,"last_page":4}'});
  assert.equal((await foreign.read.exchange(raw)).state,'UNKNOWN');
});
test('bounded retry evidence is sanitized; invalid status, payload, revocation and timeout fence UNKNOWN',async()=>{
  const raw=await wire();
  const limited=mock({status:429,headers:{'retry-after':'180'},body:'untouched'});
  const receipt=await limited.read.exchange(raw);
  assert.equal(JSON.parse(receipt.record).retry_after,60);
  assert.equal(receipt.body,'');
  for (const response of [
    {status:302,headers:{},body:''},
    {status:503,headers:{},body:''},
    {status:200,headers:{'content-type':'application/json'},body:'x'.repeat(262145)},
    revoke=>{revoke();return {status:200,headers:{},body:[]};},
    ()=>new Promise(()=>{}),
  ]) {
    const sut=mock(response);
    assert.equal((await sut.read.exchange(raw)).state,'UNKNOWN');
    assert.equal(sut.attempts.get('b'.repeat(64)),'UNKNOWN');
    assert.equal((await sut.read.exchange(raw)).state,'DENIED');
    assert.equal(sut.sends(),1);
  }
});

test('loopback connector passes only the requested GET through credential custody',async()=>{
  const {createServer,get}=await import('node:http');
  const {once}=await import('node:events');
  const fixtureToken='test-only-loopback-'+crypto.randomUUID();
  const requests=[];
  const server=createServer((req,res)=>{
    requests.push({method:req.method,url:req.url,allowed:req.headers.authorization==='Bearer '+fixtureToken});
    res.writeHead(200,{'content-type':'application/json'});
    res.end('[{"id":17,"title":"Synthetic partner","location":{}}]');
  });
  server.listen(0,'127.0.0.1');
  await once(server,'listening');
  try {
    const transport=new ProviderReadTransport({
      scope,clock:()=>1700000000,sign:bytes=>crypto.subtle.sign('Ed25519',signer.privateKey,bytes),
      authority:{
        reserve:()=>({state:'ADMITTED',token:'d'.repeat(64)}),
        current:()=>true,
        finish:()=>true,
      },
      connector:{async get(binding,{signal}) {
        // The transport receives a reference. Only the test connector holds the dummy value.
        assert.equal(binding.credential_reference,'opaque-v2');
        assert.equal(JSON.stringify(binding).includes(fixtureToken),false);
        assert.equal(binding.endpoint,'/v1/catalog/print_providers.json');
        // Use an explicit test-only loopback socket. The shared harness seals global fetch.
        return new Promise((resolve,reject)=>{
          const request=get({hostname:'127.0.0.1',port:server.address().port,path:binding.endpoint,
            agent:false,signal,headers:{authorization:'Bearer '+fixtureToken}},response=>{
            resolve({status:response.statusCode,headers:{'content-type':response.headers['content-type']},body:response});
          });
          request.once('error',reject);
        });
      }},
    });
    const result=await transport.exchange(await wire('PROVIDERS_READ','/v1/catalog/print_providers.json','print_providers.read'));
    assert.equal(typeof result.record,'string',JSON.stringify(result));
    assert.equal(JSON.parse(result.record).status,200);
    assert.deepEqual(requests,[{method:'GET',url:'/v1/catalog/print_providers.json',allowed:true}]);
    assert.equal(JSON.stringify(result).includes(fixtureToken),false);
  } finally {
    server.closeAllConnections();
    await new Promise(resolve=>server.close(resolve));
  }
});
