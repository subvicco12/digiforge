import test from 'node:test';
import assert from 'node:assert/strict';
import { providerUrl, admit } from './worker.mjs';

test('only the typed, fixed-origin GET route is admitted', () => {
    const scope = {shop:'107'};
    assert.equal(providerUrl({operation:'ORDERS_READ',scope,selection:{page:2,limit:1},
        method:'GET',path:'/v1/shops/107/orders.json?page=2&limit=1'}).href,
    'https://api.printify.com/v1/shops/107/orders.json?page=2&limit=1');
    for (const path of ['https://evil.invalid/','/v1/shops/108/orders.json?page=2&limit=1',
        '/v1/shops/107/orders.json?page=41&limit=1']) {
        assert.throws(() => providerUrl({operation:'ORDERS_READ',scope,selection:{page:2,limit:1},method:'GET',path}));
    }
    assert.throws(() => providerUrl({operation:'SHOPS_READ',scope,selection:[],method:'POST',path:'/v1/shops.json'}));
});

test('admission fences rollback and serializes grant consumption', async () => {
    const map = new Map();
    const store = {transaction: async fn => fn({get:k=>map.get(k),put:(k,v)=>map.set(k,v)})};
    const grant = {id:'a'.repeat(32),policy_revision:2,issued_at:100,expires_at:130};
    const scopeDigest = 'e'.repeat(64);
    const policy = {sequence:4,revision:2,epoch:'fixture-epoch',stop_all:false,external_lock:false,release_hold:false,revoked:[]};
    assert.equal(await admit(store,grant,policy,100,scopeDigest),'ADMISSION_UNAVAILABLE');
    map.set('head',{epoch:'fixture-epoch',sequence:4,revision:2,scope_digest:scopeDigest,count:0,consumed:[]});
    assert.equal(await admit(store,grant,policy,100,scopeDigest),'ADMITTED');
    assert.equal(await admit(store,grant,policy,100,scopeDigest),'GRANT_DENIED');
    assert.equal(await admit(store,{...grant,id:'b'.repeat(32)},{...policy,sequence:3},100,scopeDigest),'GRANT_DENIED');
    assert.equal(await admit(store,{...grant,id:'b'.repeat(32)},{...policy,stop_all:true},100,scopeDigest),'GRANT_DENIED');
});

test('held mode and absent private service never reach custody', async () => {
    const { default: ingress, ProtectedReadAdmission } = await import('./worker.mjs');
    assert.equal((await ingress.fetch()).status,404);
    let custody = 0;
    const FIXTURE_CUSTODY = {fetch:async () => { ++custody; return Response.json({}); }};
    const read = () => new Request('https://private.invalid/read',{
        method:'POST',body:JSON.stringify({
            request:{version:'DF_STANDALONE_GET_1',operation:'SHOPS_READ',method:'GET',scope:{shop:'107'},
                selection:[],path:'/v1/shops.json'},
            grant:{audience:'DF_STANDALONE_GET_1',id:'b'.repeat(32),request_digest:'e'.repeat(64)},
            signature:'a'.repeat(128),
        }),
    });
    assert.equal((await new ProtectedReadAdmission({},{EXECUTION_MODE:'held',FIXTURE_CUSTODY}).fetch(read())).status,403);
    assert.equal((await new ProtectedReadAdmission({},{EXECUTION_MODE:'offline-fixture',FIXTURE_CUSTODY}).fetch(read())).status,503);
    assert.equal(custody,0);
});

test('transaction contention charges exactly one grant and restores no quota on restart', async () => {
    const scope = 'f'.repeat(64);
    const policy = {epoch:'test',sequence:1,revision:1,stop_all:false,external_lock:false,release_hold:false,revoked:[]};
    const grant = {id:'a'.repeat(32),policy_revision:1,issued_at:100,expires_at:130};
    let queue = Promise.resolve();
    let head = {scope_digest:scope,epoch:'test',sequence:1,revision:1,count:0,consumed:[]};
    const store = {transaction(fn) {
        const run = queue.then(() => fn({get:async()=>structuredClone(head),put:async(_k,value)=>{head=value;}}));
        queue = run.catch(()=>{});
        return run;
    }};
    const results = await Promise.all(Array.from({length:12},()=>admit(store,grant,policy,100,scope)));
    assert.equal(results.filter(value=>value==='ADMITTED').length,1);
    assert.equal(head.count,1);
    const current = {...policy,sequence:2};
    assert.equal(await admit(store,grant,current,100,scope),'GRANT_DENIED');
    assert.equal(await admit(store,{...grant,id:'b'.repeat(32)},current,100,'d'.repeat(64)),'GRANT_DENIED');
    assert.equal(await admit(store,{...grant,id:'b'.repeat(32)},{...current,revoked:['b'.repeat(32)]},100,scope),'GRANT_DENIED');
    assert.equal(await admit(store,{...grant,id:'b'.repeat(32)},current,130,scope),'GRANT_DENIED');
    assert.equal(head.count,1);
});

test('unacknowledged floor prevents egress and revocation suppresses an in-flight projection', async () => {
    const { ProtectedReadAdmission } = await import('./worker.mjs');
    const scope = 'f'.repeat(64);
    const requestDigest = 'd'.repeat(64);
    const now = Math.floor(Date.now()/1000);
    const grant = {id:'a'.repeat(32),audience:'DF_STANDALONE_GET_1',request_digest:requestDigest,
        policy_revision:1,issued_at:now,expires_at:now+30};
    const request = {version:'DF_STANDALONE_GET_1',operation:'SHOPS_READ',method:'GET',scope:{shop:'107'},
        selection:[],path:'/v1/shops.json'};
    const inbound = () => new Request('https://private.invalid/read',{
        method:'POST',body:JSON.stringify({request,grant,signature:'a'.repeat(128)}),
    });
    for (const acknowledge of [false,true]) {
        let head = {scope_digest:scope,epoch:'test',sequence:1,revision:1,count:0,consumed:[]};
        let reads = 0;
        const service = fn => ({fetch:async req=>Response.json(fn(new URL(req.url).pathname,await req.json()))});
        const env = {
            EXECUTION_MODE:'offline-fixture',
            CONTRACTS:service(path=>path === '/verify'
                ? {verified:true,request_digest:requestDigest,path:request.path,scope_digest:scope}
                : {validated:true,data:{shops:[]}}),
            POLICY:service((path,identity)=>path === '/snapshot'
                ? {epoch:'test',sequence:1,revision:1,stop_all:false,external_lock:false,release_hold:false,revoked:[]}
                : {...identity,accepted:path === '/admit' && acknowledge}),
            FIXTURE_CUSTODY:service(()=>{++reads; return [];}),
            REPLY_SIGNER:service((_path,receipt)=>({receipt})),
        };
        const storage = {transaction:fn=>fn({get:()=>head,put:(_k,v)=>{head=v;}})};
        const result = await new ProtectedReadAdmission({storage},env).fetch(inbound());
        const {receipt} = await result.json();
        assert.equal(receipt.state,'DENIED');
        assert.equal(receipt.reason,'AUTHORITY_UNAVAILABLE');
        assert.equal(receipt.data,undefined);
        assert.equal(reads,Number(acknowledge));
        assert.equal(head.count,1);
    }
});
