import { providerUrl, readBounded } from './worker.mjs';

const maximum = 262144;
const unavailable = () => Response.json({state:'UNAVAILABLE'},{status:503});
const match = (a,b) => a?.accepted === true && ['epoch','sequence','scope_digest','request_digest','grant_id','policy_revision']
    .every(key=>a[key]===b[key]);

/** Private custody adapter. The only response transport in this release is injected synthetic I/O. */
export class ReadCustodyService {
    constructor(env) { this.env=env; }

    async fetch(request) {
        if (this.env.MODE!=='offline-fixture' || request.method!=='POST') {
            return new Response(null,{status:404});
        }
        try {
            const path=new URL(request.url).pathname;
            if (path==='/ready') {
                return Response.json({ready:typeof this.env.FIXTURE_UPSTREAM?.fetch==='function'
                    && typeof this.env.CREDENTIAL_PROVIDER?.resolve==='function'
                    && typeof this.env.POLICY?.fetch==='function' && typeof this.env.CONTRACTS?.fetch==='function',protocol:'DF_STANDALONE_GET_1'});
            }
            const input=JSON.parse(await readBounded(request,32768));
            const url=providerUrl(input.intent);
            if (path!=='/get' || input.url!==url.href || input.method!=='GET' || input.redirect!=='deny'
                || input.timeout_ms!==5000 || input.max_body_bytes!==maximum
                || ['account','shop','integration','custody'].some(key=>input.scope[key]!==input.intent.scope[key])) {
                return unavailable();
            }
            const context=Object.fromEntries(['epoch','sequence','scope_digest','request_digest','grant_id','policy_revision']
                .map(key=>[key,input[key]]));
            const call=async action=>{
                const result=await this.env.POLICY.fetch(new Request('https://policy.invalid/'+action,{
                    method:'POST',body:JSON.stringify(context),redirect:'manual',signal:AbortSignal.timeout(5000),
                }));
                return result.ok && match(await result.json(),context);
            };
            const verified=await this.env.CONTRACTS.fetch(new Request('https://contracts.invalid/verify',{
                method:'POST',redirect:'manual',signal:AbortSignal.timeout(5000),
                body:JSON.stringify({request:input.intent,grant:input.grant,signature:input.signature}),
            }));
            const proof=verified.ok ? JSON.parse(await readBounded(verified,32768)) : null;
            if (proof?.verified!==true || proof.request_digest!==context.request_digest
                || proof.scope_digest!==context.scope_digest || proof.path!==url.pathname+url.search
                || input.grant.id!==context.grant_id || input.grant.policy_revision!==context.policy_revision
                || !Number.isSafeInteger(input.grant.expires_at) || input.grant.expires_at<=Math.floor(Date.now()/1000)) {
                return unavailable();
            }
            const credential=await this.env.CREDENTIAL_PROVIDER.resolve(input.scope);
            if (credential?.synthetic!==true || typeof credential.value!=='string'
                || !/^fixture-[A-Za-z0-9_-]{1,80}$/.test(credential.value) || !await call('begin')) {
                return unavailable();
            }
            // An uncertain effect remains in-flight. This service never retries the request.
            const response=await this.env.FIXTURE_UPSTREAM.fetch(new Request(url,{
                method:'GET',redirect:'manual',signal:AbortSignal.timeout(5000),
                headers:{accept:'application/json',authorization:'Bearer '+credential.value},
            }));
            const status=response.status;
            const contentType=response.headers.get('content-type') ?? '';
            const body=await readBounded(response,maximum);
            if (!await call('finish') || (status!==200 && status!==429)
                || !/^application\/json(?:\s*;|$)/i.test(contentType)) {
                return unavailable();
            }
            return new Response(body,{status,headers:{'content-type':contentType}});
        } catch {
            return unavailable();
        }
    }
}
