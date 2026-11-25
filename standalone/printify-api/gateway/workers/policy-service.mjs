const VERSION = 'DF_STANDALONE_GET_1';
const LIMIT = 32768;
const hex = (v,n) => typeof v === 'string' && v.length === n && /^[0-9a-f]+$/.test(v);
const number = v => Number.isSafeInteger(v) && v >= 0;
const epoch = v => typeof v === 'string' && /^[A-Za-z0-9_-]{1,80}$/.test(v);
const denied = () => Response.json({accepted:false},{status:403});
const unavailable = () => Response.json({accepted:false},{status:503});
const json = value => Response.json(value);

export async function seal(state) {
    const ordered = value => Array.isArray(value) ? value.map(ordered)
        : value !== null && typeof value === 'object'
            ? Object.fromEntries(Object.keys(value).sort().map(key => [key,ordered(value[key])])) : value;
    const bytes = await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(ordered(state))));
    return Array.from(new Uint8Array(bytes),v=>v.toString(16).padStart(2,'0')).join('');
}

async function message(request) {
    if (!request.body) {
        throw new Error('Expected private POST');
    }
    const reader = request.body.getReader();
    const decoder = new TextDecoder('utf-8',{fatal:true});
    let size = 0;
    let result = '';
    try {
        while (true) {
            const {done,value} = await reader.read();
            if (done) {
                return JSON.parse(result+decoder.decode());
            }
            size += value.byteLength;
            if (size>LIMIT) {
                throw new Error('Message too large');
            }
            result += decoder.decode(value,{stream:true});
        }
    } finally {
        void reader.cancel().catch(()=>{});
        reader.releaseLock();
    }
}

async function witness(binding,path,body) {
    if (!binding || typeof binding.fetch !== 'function') {
        throw new Error('Independent floor unavailable');
    }
    const response = await binding.fetch(new Request('https://witness.invalid/'+path,{
        method:'POST',body:JSON.stringify(body),signal:AbortSignal.timeout(5000),redirect:'manual',
    }));
    if (response.status!==200) {
        throw new Error('Independent floor denied');
    }
    return message(response);
}

function sameFloor(a,b) {
    return a?.scope_digest===b.scope_digest && a.epoch===b.epoch && a.sequence===b.sequence && a.digest===b.digest;
}

function validFloor(value) {
    return hex(value?.scope_digest,64) && epoch(value.epoch) && number(value.sequence) && hex(value.digest,64);
}

/** Private SQLite-backed object. Initialize the exact floor out of band. */
export class RecoveryFloor {
    constructor(ctx,env) { this.store=ctx.storage; this.env=env; }

    async fetch(request) {
        if (this.env.MODE!=='offline-fixture' || request.method!=='POST') {
            return denied();
        }
        try {
            const path=new URL(request.url).pathname;
            const input=await message(request);
            if (path==='/read') {
                const head=await this.store.get('head');
                return validFloor(head) && head.scope_digest===input.scope_digest ? json(head) : unavailable();
            }
            const {before,after}=input;
            if (path!=='/advance' || !validFloor(before) || !validFloor(after) || before.scope_digest!==after.scope_digest
                || before.epoch!==after.epoch || after.sequence!==before.sequence+1) {
                return denied();
            }
            const changed=await this.store.transaction(async tx=>{
                const current=await tx.get('head');
                if (!sameFloor(current,before)) {
                    return false;
                }
                await tx.put('head',after);
                return true;
            });
            return changed ? json(after) : denied();
        } catch {
            return unavailable();
        }
    }
}

function validState(state) {
    return hex(state?.scope_digest,64) && epoch(state.epoch) && number(state.sequence)
        && number(state.revision) && state.revision>0
        && ['stop_all','external_lock','release_hold'].every(key=>typeof state[key]==='boolean')
        && Array.isArray(state.revoked) && state.revoked.length<=256 && state.revoked.every(id=>hex(id,32))
        && Array.isArray(state.admissions) && state.admissions.length<=100
        && state.admissions.every(row=>hex(row.id,32) && hex(row.digest,64) && number(row.sequence)
            && ['reserved','in-flight','finished'].includes(row.phase));
}

function floorFor(state,generation,digest) {
    return {scope_digest:state.scope_digest,epoch:state.epoch,sequence:generation,digest};
}

function ready(state,input) {
    return !state.stop_all && !state.external_lock && !state.release_hold && state.revision===input.policy_revision
        && !state.revoked.includes(input.grant_id);
}

function checkpoint(input) {
    const fields=['scope_digest','epoch','sequence','policy_revision','grant_id','request_digest'];
    return Object.fromEntries(fields.map(key=>[key,input[key]]));
}

function apply(path,state,input) {
    if (path==='/stop') {
        state.stop_all=true;
        return true;
    }
    if (path==='/revoke' && hex(input.grant_id,32) && state.revoked.length<256 && !state.revoked.includes(input.grant_id)) {
        state.revoked.push(input.grant_id);
        return true;
    }
    if (input.epoch!==state.epoch || !hex(input.grant_id,32) || !hex(input.request_digest,64)
        || !number(input.sequence) || !number(input.policy_revision)) {
        return false;
    }
    const row=state.admissions.find(entry=>entry.id===input.grant_id);
    if (path==='/admit' && !row && ready(state,input) && input.prior_sequence===state.sequence
        && input.sequence===state.sequence+1 && state.admissions.length<100) {
        state.sequence=input.sequence;
        state.admissions.push({id:input.grant_id,digest:input.request_digest,sequence:input.sequence,phase:'reserved'});
        return true;
    }
    if (!row || row.digest!==input.request_digest || row.sequence!==input.sequence) {
        return false;
    }
    if (path==='/begin' && ready(state,input) && row.phase==='reserved') {
        row.phase='in-flight';
        return true;
    }
    if (path==='/finish' && row.phase==='in-flight') {
        row.phase='finished';
        return true;
    }
    return false;
}

/** Private authority that fences between local intent, witness compare/advance, and commit. */
export class PolicyAdmission {
    constructor(ctx,env) { this.store=ctx.storage; this.env=env; }

    async fetch(request) {
        if (this.env.MODE!=='offline-fixture' || request.method!=='POST') {
            return denied();
        }
        try {
            const input=await message(request);
            const path=new URL(request.url).pathname;
            const head=await this.store.get('head');
            if (!validState(head?.state) || head.pending) {
                return unavailable();
            }
            const {state}=head;
            const generation=head.generation??0;
            const digest=await seal(state);
            const before=floorFor(state,generation,digest);
            if (!validFloor(before) || (path!=='/ready' && input.scope_digest!==state.scope_digest)) {
                return denied();
            }
            const current=await witness(this.env.WITNESS,'read',{scope_digest:state.scope_digest});
            if (!sameFloor(current,before)) {
                return unavailable();
            }
            // A concurrent writer may have set its pending marker while the witness was read.
            const fresh=await this.store.get('head');
            if (!validState(fresh?.state) || fresh.pending || (fresh.generation??0)!==generation
                || await seal(fresh.state)!==digest) {
                return unavailable();
            }
            if (path==='/ready') {
                return json({ready:input.protocol===VERSION,protocol:VERSION});
            }
            if (path==='/snapshot') {
                const {admissions,...policy}=state;
                return json(policy);
            }
            if (path==='/drain') {
                return json({stop_all:state.stop_all,in_flight:state.admissions.filter(row=>row.phase==='in-flight').length});
            }
            if (path==='/release') {
                const entry=state.admissions.find(row=>row.id===input.grant_id && row.digest===input.request_digest
                    && row.sequence===input.sequence && row.phase==='finished');
                return json({...checkpoint(input),accepted:!!entry && input.epoch===state.epoch && ready(state,input)});
            }

            const next=structuredClone(state);
            if (!apply(path,next,input) || generation===Number.MAX_SAFE_INTEGER) {
                return denied();
            }
            const after=floorFor(next,generation+1,await seal(next));
            const marked=await this.store.transaction(async tx=>{
                const local=await tx.get('head');
                if (!validState(local?.state) || local.pending || (local.generation??0)!==generation
                    || await seal(local.state)!==digest) {
                    return false;
                }
                await tx.put('head',{state,generation,pending:{after,next}});
                return true;
            });
            if (!marked) {
                return unavailable();
            }
            const ack=await witness(this.env.WITNESS,'advance',{before,after});
            if (!sameFloor(ack,after)) {
                return unavailable();
            }
            const committed=await this.store.transaction(async tx=>{
                const local=await tx.get('head');
                if (!sameFloor(local?.pending?.after,after)) {
                    return false;
                }
                await tx.put('head',{state:next,generation:generation+1});
                return true;
            });
            return committed ? json({...checkpoint(input),accepted:true}) : unavailable();
        } catch {
            return unavailable();
        }
    }
}

export default { async fetch() { return new Response(null,{status:404}); } };
