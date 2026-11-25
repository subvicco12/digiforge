import test from 'node:test';
import assert from 'node:assert/strict';
import { PolicyAdmission, RecoveryFloor, seal } from './policy-service.mjs';

function storage(initial) {
    let value = structuredClone(initial);
    let tail = Promise.resolve();
    return {
        get:async () => structuredClone(value),
        transaction(fn) {
            const work = tail.then(async () => {
                let draft = structuredClone(value);
                const result = await fn({get:async()=>draft,put:async(_key,next)=>{draft=next;}});
                value=structuredClone(draft);
                return result;
            });
            tail=work.catch(()=>{});
            return work;
        },
        restore:state=>{value=structuredClone(state);},
    };
}
const scope_digest='a'.repeat(64);
const genesis={scope_digest,epoch:'fixture',sequence:0,revision:1,stop_all:false,external_lock:false,
    release_hold:false,revoked:[],admissions:[]};
const req=(path,body)=>new Request('https://service.invalid/'+path,{method:'POST',body:JSON.stringify(body)});
const identity={scope_digest,epoch:'fixture',prior_sequence:0,sequence:1,policy_revision:1,grant_id:'b'.repeat(32),
    request_digest:'c'.repeat(64)};
async function fixture() {
    const local=storage({state:genesis});
    const floor=storage({scope_digest,epoch:'fixture',sequence:0,digest:await seal(genesis)});
    const witness=new RecoveryFloor({storage:floor},{MODE:'offline-fixture'});
    const policy=new PolicyAdmission({storage:local},{MODE:'offline-fixture',WITNESS:witness});
    return {policy,local,floor};
}

test('independent floor admits once, denies replay, and catches restored local state',async()=>{
    const {policy,local}=await fixture();
    const replies=await Promise.all([policy.fetch(req('admit',identity)),policy.fetch(req('admit',identity))]);
    assert.equal((await Promise.all(replies.map(r=>r.json()))).filter(r=>r.accepted).length,1);
    assert.equal((await (await policy.fetch(req('begin',identity))).json()).accepted,true);
    assert.equal((await (await policy.fetch(req('finish',identity))).json()).accepted,true);
    assert.equal((await (await policy.fetch(req('release',identity))).json()).accepted,true);
    local.restore({state:genesis});
    assert.equal((await policy.fetch(req('snapshot',identity))).status,503);
});

test('STOP and revocation prevent dispatch/release and retain in-flight receipt until drained',async()=>{
    const {policy}=await fixture();
    assert.equal((await (await policy.fetch(req('admit',identity))).json()).accepted,true);
    assert.equal((await (await policy.fetch(req('begin',identity))).json()).accepted,true);
    assert.equal((await policy.fetch(req('stop',{scope_digest}))).status,200);
    assert.equal((await (await policy.fetch(req('release',identity))).json()).accepted,false);
    assert.equal((await (await policy.fetch(req('drain',{scope_digest}))).json()).in_flight,1);
    assert.equal((await policy.fetch(req('finish',identity))).status,200);
    assert.equal((await (await policy.fetch(req('drain',{scope_digest}))).json()).in_flight,0);
});

test('lost floor acknowledgement leaves a persistent fence with no automatic retry',async()=>{
    const local=storage({state:genesis});
    const floor=storage({scope_digest,epoch:'fixture',sequence:0,digest:await seal(genesis)});
    const witness=new RecoveryFloor({storage:floor},{MODE:'offline-fixture'});
    const failing={fetch:async request=>{
        const response=await witness.fetch(request);
        return request.url.endsWith('/advance') ? new Response(null,{status:503}) : response;
    }};
    const interrupted=new PolicyAdmission({storage:local},{MODE:'offline-fixture',WITNESS:failing});
    assert.equal((await interrupted.fetch(req('admit',identity))).status,503);
    assert.equal((await floor.get()).sequence,1);
    assert.ok((await local.get()).pending);
    const restarted=new PolicyAdmission({storage:local},{MODE:'offline-fixture',WITNESS:witness});
    for (const path of ['snapshot','admit','begin','release']) {
        assert.equal((await restarted.fetch(req(path,identity))).status,503);
    }
});

test('revocation after reservation denies the start of custody',async()=>{
    const {policy}=await fixture();
    assert.equal((await (await policy.fetch(req('admit',identity))).json()).accepted,true);
    assert.equal((await policy.fetch(req('revoke',identity))).status,200);
    assert.equal((await policy.fetch(req('begin',identity))).status,403);
    assert.equal((await (await policy.fetch(req('drain',{scope_digest}))).json()).in_flight,0);
});
