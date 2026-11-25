import { readBounded } from './worker.mjs';

const hex = (value,length) => typeof value==='string' && value.length===length && /^[0-9a-f]+$/.test(value);
const protocol='DF_STANDALONE_GET_1';

/** Detached Ed25519 signer. The trusted contract port approves intent and supplies its canonical digest. */
export class DetachedSigningService {
    constructor({role,issuer,mode,keyProvider,verifyIntent}) {
        this.role=role;
        this.issuer=issuer;
        this.mode=mode;
        this.keyProvider=keyProvider;
        this.verifyIntent=verifyIntent;
    }

    async key() {
        const key=await this.keyProvider();
        if (key?.type!=='private' || key.extractable || key.algorithm.name!=='Ed25519'
            || !key.usages.includes('sign')) {
            return null;
        }
        return key;
    }

    async sign(payload) {
        try {
            if (this.mode!=='offline-fixture' || !payload || !Number.isSafeInteger(payload.issued_at)
                || !hex(payload.request_digest,64)) {
                return null;
            }
            const now=Math.floor(Date.now()/1000);
            if (payload.issued_at>now || payload.issued_at<now-30) {
                return null;
            }
            const grant=this.role==='grant' && typeof this.issuer==='string' && this.issuer.length>0
                && Object.keys(payload).length===7 && payload.issuer===this.issuer && payload.audience===protocol
                && hex(payload.id,32) && Number.isSafeInteger(payload.policy_revision) && payload.policy_revision>0
                && Number.isSafeInteger(payload.expires_at) && payload.expires_at>now
                && payload.expires_at-payload.issued_at<=60;
            const reply=this.role==='reply' && payload.schema==='DF_STANDALONE_GET_REPLY_1'
                && hex(payload.grant_id,32) && payload.provider_execution_authorized===false
                && payload.retry_permitted===false
                && ['OBSERVED','UNAVAILABLE','DENIED'].includes(payload.state)
                && (payload.state==='OBSERVED' ? payload.source==='SYNTHETIC_PRINTIFY'
                    && hex(payload.response_sha256,64) && payload.observed_at===payload.issued_at
                    : !Object.hasOwn(payload,'data'));
            if (!grant && !reply) {
                return null;
            }
            const proof=await this.verifyIntent(this.role,payload);
            if (proof?.authorized!==true || !hex(proof.digest,64)) {
                return null;
            }
            const key=await this.key();
            if (!key) {
                return null;
            }
            const bytes=await crypto.subtle.sign('Ed25519',key,new TextEncoder().encode(proof.digest));
            return Array.from(new Uint8Array(bytes),byte=>byte.toString(16).padStart(2,'0')).join('');
        } catch {
            return null;
        }
    }

    async fetch(request) {
        if (this.mode!=='offline-fixture' || request.method!=='POST') {
            return new Response(null,{status:404});
        }
        try {
            const path=new URL(request.url).pathname;
            if (path==='/ready') {
                return Response.json({ready:!!await this.key(),protocol});
            }
            if (path==='/sign' && this.role==='reply') {
                const receipt=JSON.parse(await readBounded(request,524288));
                const signature=await this.sign(receipt);
                if (signature) {
                    return Response.json({receipt,signature});
                }
            }
        } catch {
            // Opaque unavailable result only.
        }
        return Response.json({state:'UNAVAILABLE'},{status:503});
    }
}
