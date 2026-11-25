import { OperatorAssertions, pinnedKeys, verifyJwt } from './ingress-auth.mjs';
import { readBounded } from './worker.mjs';
export { ProtectedReadAdmission } from './worker.mjs';

const blocked = () => Response.json({state:'DENIED',reason:'INGRESS_UNAVAILABLE'},{status:403});
const hex = (v,n) => typeof v==='string' && v.length===n && /^[a-f0-9]+$/.test(v);

async function call(binding,path,payload) {
    const controller=new AbortController();
    let timer;
    const deadline=new Promise((_,reject)=>{
        timer=setTimeout(()=>{
            controller.abort();
            reject(new Error('Private binding unavailable'));
        },5000);
    });
    const exchange=async()=>{
        const response=await binding.fetch(new Request('https://t4-service.invalid/'+path,{
            method:'POST',redirect:'manual',signal:controller.signal,
            headers:{'content-type':'application/json'},body:JSON.stringify(payload),
        }));
        if (!response.ok) return null;
        return JSON.parse(await readBounded(response,32768));
    };
    try {
        return await Promise.race([exchange(),deadline]);
    } finally {
        clearTimeout(timer);
        controller.abort();
    }
}

/** Access is transport identity. Independently authenticated operator state is mandatory. */
export class T4Ingress {
    constructor(config) { this.config=config; }

    async fetch(inbound) {
        const config=this.config;
        const url=new URL(inbound.url);
        if (config.mode!=='t4-inert' || url.protocol!=='https:' || url.origin!==config.origin
            || inbound.method!=='POST' || url.pathname!=='/read' || url.search
            || inbound.headers.has('x-df-ingress-scope')
            || !['sub','common_name'].includes(config.access?.identityClaim)) return blocked();
        try {
            const transport=await verifyJwt(inbound.headers.get('cf-access-jwt-assertion'),config.access,config.accessKeys);
            if (!transport) return blocked();

            const envelope=JSON.parse(await readBounded(inbound,32768));
            const {request,grant,signature,workspace,operator_assertion}=envelope;
            const now=Math.floor(Date.now()/1000);
            const operator=await config.operator.verify(operator_assertion,envelope,workspace,now);
            if (!operator) return blocked();
            const proof=await call(config.contracts,'verify',{request,grant,signature});
            if (proof?.verified!==true || proof.request_digest!==operator.request_digest
                || !hex(proof.scope_digest,64) || proof.path!==request.path) return blocked();

            const id=config.admission.idFromName(proof.scope_digest);
            return await config.admission.get(id).fetch(new Request('https://admission.invalid/read',{
                method:'POST',headers:{'content-type':'application/json','x-df-ingress-scope':proof.scope_digest},
                body:JSON.stringify({request,grant,signature}),
            }));
        } catch {
            return blocked();
        }
    }
}

export default {
    async fetch(inbound,env) {
        if (env.T4_MODE!=='t4-inert') return new Response(null,{status:404});
        try {
            const accessKeys=await pinnedKeys(JSON.parse(env.T4_ACCESS_PUBLIC_JWKS),Number(env.T4_KEYS_EXPIRES_AT));
            const keySet=await pinnedKeys(JSON.parse(env.T4_OPERATOR_PUBLIC_JWKS),Number(env.T4_KEYS_EXPIRES_AT));
            const operator=new OperatorAssertions({issuer:env.T4_OPERATOR_ISSUER,audience:env.T4_OPERATOR_AUDIENCE,keySet,
                current:expected=>call(env.OPERATOR_STATE,'attest',expected)});
            return await new T4Ingress({
                mode:env.T4_MODE,origin:env.T4_ORIGIN,
                access:{issuer:env.T4_ACCESS_ISSUER,audience:env.T4_ACCESS_AUDIENCE,
                    identityClaim:env.T4_ACCESS_IDENTITY_CLAIM,identity:env.T4_ACCESS_IDENTITY,maxAge:300},
                accessKeys,operator,contracts:env.CONTRACTS,admission:env.ADMISSION,
            }).fetch(inbound);
        } catch {
            return blocked();
        }
    },
};
