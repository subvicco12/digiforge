import { providerUrl, readBounded } from './worker.mjs';
import { DetachedSigningService } from './signing-service.mjs';
import { OperatorAssertions } from './ingress-auth.mjs';
import { fixtureVectors } from './t4-vectors.mjs';

const encode=new TextEncoder();
const unavailable=()=>Response.json({state:'UNAVAILABLE'},{status:503});
const hex=(v,n)=>typeof v==='string' && v.length===n && /^[a-f0-9]+$/.test(v);
const version='DF_STANDALONE_GET_1';
const now=()=>Math.floor(Date.now()/1000);
const ordered=v=>Array.isArray(v) ? v.map(ordered) : v!==null && typeof v==='object'
    ? Object.fromEntries(Object.keys(v).sort().map(k=>[k,ordered(v[k])])) : v;
const canonical=v=>JSON.stringify(ordered(v)).replaceAll(String.fromCharCode(8232),'\\u2028').replaceAll(String.fromCharCode(8233),'\\u2029');
const bytes=hexText=>Uint8Array.from(hexText.match(/.{2}/g),b=>parseInt(b,16));
const digest=async v=>Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',encode.encode(canonical(v)))),
    b=>b.toString(16).padStart(2,'0')).join('');

function find(request) {
    providerUrl(request);
    if (request.version!==version) throw new Error('Wrong read version');
    return fixtureVectors.find(row=>row.operation===request.operation && row.path===request.path
        && canonical(row.selection)===canonical(request.selection));
}

function scopePermitted(request,env) {
    const configured=JSON.parse(env.T4_FIXTURE_SCOPE);
    const scope=request.scope;
    return canonical(scope)===canonical(configured) && scope.shop==='107' && scope.account==='fixture-account'
        && scope.integration==='fixture-read' && scope.custody==='custody-ref:fixture'
        && hex(scope.session,64) && hex(scope.revision,64) && Number.isSafeInteger(scope.actor) && scope.actor>0;
}

async function verify(input,env) {
    const {request,grant,signature}=input;
    if (!find(request) || !scopePermitted(request,env) || Object.keys(grant).length!==7
        || grant.issuer!=='fixture-custodian' || grant.audience!==version || !hex(grant.id,32)
        || !Number.isSafeInteger(grant.policy_revision) || grant.policy_revision<1
        || !Number.isSafeInteger(grant.issued_at) || !Number.isSafeInteger(grant.expires_at)
        || grant.issued_at>now() || grant.expires_at<=now() || grant.expires_at-grant.issued_at>60
        || !hex(signature,128) || !hex(env.T4_GRANT_PUBLIC_KEY,64)) return null;
    const request_digest=await digest(request);
    if (grant.request_digest!==request_digest) return null;
    const key=await crypto.subtle.importKey('raw',bytes(env.T4_GRANT_PUBLIC_KEY),'Ed25519',false,['verify']);
    if (!await crypto.subtle.verify('Ed25519',key,bytes(signature),encode.encode(await digest(grant)))) return null;
    const {site,account,shop,integration}=request.scope;
    return {verified:true,request_digest,scope_digest:await digest({site,account,shop,integration}),path:request.path};
}

async function privateCall(binding,path,payload) {
    const response=await binding.fetch(new Request('https://t4-binding.invalid/'+path,{
        method:'POST',redirect:'manual',signal:AbortSignal.timeout(5000),body:JSON.stringify(payload),
    }));
    return response.ok ? JSON.parse(await readBounded(response,32768)) : null;
}

function attest(expected,env) {
    const record=JSON.parse(env.T4_OPERATOR_RECORD);
    if (!Number.isSafeInteger(record.expires_at) || record.expires_at<=now() || record.revoked!==false
        || !['site','actor','session','revision','workspace','account','shop','integration'].every(k=>record[k]===expected[k])
        || !Array.isArray(record.operations) || !record.operations.includes(expected.operation)) return null;
    return expected;
}

/** Private Worker entrypoint for selected role. No Internet fetch or provider credential exists here. */
export default {
    async fetch(request,env) {
        if (env.MODE!=='t4-inert' || request.method!=='POST') return new Response(null,{status:404});
        try {
            const path=new URL(request.url).pathname;
            const input=JSON.parse(await readBounded(request,524288));
            if (env.ROLE==='contracts') {
                if (path==='/ready') {
                    JSON.parse(env.T4_FIXTURE_SCOPE);
                    return Response.json({ready:hex(env.T4_GRANT_PUBLIC_KEY,64),protocol:version});
                }
                if (path==='/verify') {
                    const proof=await verify(input,env);
                    return proof ? Response.json(proof) : unavailable();
                }
                if (path==='/project') {
                    const row=find(input.request);
                    if (row && scopePermitted(input.request,env) && canonical(JSON.parse(input.body))===canonical(row.body)) {
                        return Response.json({validated:true,data:row.projection});
                    }
                }
            }
            if (env.ROLE==='operator') {
                if (path==='/attest') {
                    return Response.json(attest(input,env));
                }
                if (path==='/issue') {
                    const {envelope,workspace}=input;
                    if (!await verify(envelope,env)) return unavailable();
                    const signingKey=await crypto.subtle.importKey('pkcs8',bytes(env.T4_OPERATOR_PRIVATE_PKCS8),
                        {name:'RSASSA-PKCS1-v1_5',hash:'SHA-256'},false,['sign']);
                    const issuer=new OperatorAssertions({issuer:env.T4_OPERATOR_ISSUER,audience:env.T4_OPERATOR_AUDIENCE,
                        kid:env.T4_OPERATOR_KID,signingKey,current:expected=>attest(expected,env)});
                    const token=await issuer.issue(envelope,workspace);
                    return token ? Response.json({token}) : unavailable();
                }
            }
            if (env.ROLE==='reply') {
                // Private binding only. An independent policy release check guards successful signing.
                const keyProvider=()=>crypto.subtle.importKey('pkcs8',bytes(env.T4_REPLY_PRIVATE_PKCS8),'Ed25519',false,['sign']);
                const signer=new DetachedSigningService({role:'reply',mode:'offline-fixture',keyProvider,
                    verifyIntent:async (_role,receipt)=>{
                        const approved=await privateCall(env.POLICY,'approve-reply',{scope_digest:env.T4_SCOPE_DIGEST,grant_id:receipt.grant_id,
                            request_digest:receipt.request_digest,state:receipt.state});
                        return approved?.request_digest===receipt.request_digest && approved?.grant_id===receipt.grant_id
                            && approved?.authorized===true ? {authorized:true,digest:await digest(receipt)} : null;
                    }});
                return signer.fetch(new Request(request.url,{method:'POST',body:JSON.stringify(input)}));
            }
            if (env.ROLE==='custody') {
                if (path==='/ready') return Response.json({ready:typeof env.POLICY?.fetch==='function',protocol:version});
                if (path==='/get') {
                    const {intent:typed,grant,signature}=input;
                    const row=find(typed);
                    const proof=await verify({request:typed,grant,signature},env);
                    if (!row || !proof || proof.scope_digest!==input.scope_digest || proof.request_digest!==input.request_digest
                        || input.method!=='GET' || input.url!=='https://api.printify.com'+row.path || input.grant_id!==grant.id
                        || input.policy_revision!==grant.policy_revision || input.redirect!=='deny'
                        || input.timeout_ms!==5000 || input.max_body_bytes!==262144) return unavailable();
                    const receipt=Object.fromEntries(['scope_digest','request_digest','grant_id','epoch','sequence','policy_revision']
                        .map(k=>[k,input[k]]));
                    const matches=result=>result?.accepted===true && Object.entries(receipt).every(([k,v])=>result[k]===v);
                    if (!matches(await privateCall(env.POLICY,'begin',receipt))
                        || !matches(await privateCall(env.POLICY,'finish',receipt))) return unavailable();
                    // The only result is a prevalidated fixture. There is no upstream call.
                    return Response.json(row.body);
                }
            }
        } catch {
            return unavailable();
        }
        return unavailable();
    },
};
