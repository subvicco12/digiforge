const VERSION = 'DF_STANDALONE_GET_1';
const ORIGIN = 'https://api.printify.com';
const MAX_BODY = 262144;
const MAX_ADMISSIONS = 100;
const MAX_POLICIES = 256;

const positive = value => Number.isInteger(value) && value > 0 && value <= 2147483647;
const hex = (v, n) => typeof v === 'string' && v.length === n && /^[0-9a-f]+$/.test(v);
const resourceId = v => typeof v === 'string' && /^[A-Za-z0-9_-]{1,160}$/.test(v);
const integer = value => Number.isSafeInteger(value) && value >= 0;

export function providerUrl(request) {
    const {operation, method, selection: s, path, scope:{shop}} = request;
    if (method !== 'GET' || typeof shop !== 'string' || !/^[1-9][0-9]{0,18}$/.test(shop)) {
        throw new Error('Read route rejected');
    }
    const root = '/v1/shops/' + shop;
    const bp = positive(s.blueprint_id) ? '/v1/catalog/blueprints/' + s.blueprint_id : null;
    const provider = bp && positive(s.provider_id) ? bp + '/print_providers/' + s.provider_id : null;
    const shipping = provider ? '/v2' + provider.slice(3) + '/shipping' : null;
    const page = positive(s.page) && s.page <= 40 && positive(s.limit) && s.limit <= 10
        ? '?page=' + s.page + '&limit=' + s.limit : null;
    const paths = {
        SHOPS_READ:'/v1/shops.json', BLUEPRINTS_READ:'/v1/catalog/blueprints.json',
        BLUEPRINT_READ:bp && bp + '.json', BLUEPRINT_PROVIDERS_READ:bp && bp + '/print_providers.json',
        PROVIDERS_READ:'/v1/catalog/print_providers.json',
        PROVIDER_READ:positive(s.provider_id) && '/v1/catalog/print_providers/' + s.provider_id + '.json',
        BLUEPRINT_PROVIDER_READ:provider && provider + '.json',
        VARIANTS_READ:provider && provider + '/variants.json',
        VARIANT_READ:provider && positive(s.variant_id) && provider + '/variants.json',
        CATALOG_SHIPPING_READ:shipping && shipping + '.json',
        CATALOG_SHIPPING_METHOD_READ:shipping && ['standard','priority','express','economy'].includes(s.method)
            && shipping + '/' + s.method + '.json',
        PRODUCTS_READ:page && root + '/products.json' + page,
        ORDERS_READ:page && root + '/orders.json' + page,
        PRODUCT_READ:resourceId(s.product_id) && root + '/products/' + s.product_id + '.json',
        ORDER_READ:resourceId(s.order_id) && root + '/orders/' + s.order_id + '.json',
    };
    if (!Object.hasOwn(paths, operation) || !paths[operation] || paths[operation] !== path) {
        throw new Error('Read route rejected');
    }
    return new URL(path, ORIGIN);
}

function open(policy, grant) {
    return policy?.stop_all === false && policy.external_lock === false && policy.release_hold === false
        && integer(policy.sequence) && positive(policy.revision) && policy.revision === grant.policy_revision
        && typeof policy.epoch === 'string' && /^[A-Za-z0-9_-]{1,80}$/.test(policy.epoch)
        && Array.isArray(policy.revoked) && policy.revoked.length <= MAX_POLICIES
        && policy.revoked.every(id => hex(id,32)) && !policy.revoked.includes(grant.id);
}

export async function admit(storage, grant, policy, now, scopeDigest) {
    return storage.transaction(async transaction => {
        const head = await transaction.get('head');
        if (!head || !integer(head.sequence) || !positive(head.revision) || !integer(head.count) || head.count > MAX_ADMISSIONS
            || !Array.isArray(head.consumed) || head.consumed.length !== head.count
            || !head.consumed.every(id => hex(id,32))) {
            return 'ADMISSION_UNAVAILABLE';
        }
        if (!hex(scopeDigest,64) || head.scope_digest !== scopeDigest || !open(policy,grant) || head.epoch !== policy.epoch || head.sequence !== policy.sequence
            || policy.sequence === Number.MAX_SAFE_INTEGER || head.revision > policy.revision
            || !hex(grant.id,32) || !integer(grant.issued_at) || !integer(grant.expires_at)
            || grant.issued_at > now || grant.expires_at <= now || grant.expires_at - grant.issued_at > 60
            || head.consumed.includes(grant.id)) {
            return 'GRANT_DENIED';
        }
        if (head.count === MAX_ADMISSIONS) {
            return 'RATE_LIMITED';
        }
        await transaction.put('head', {epoch:policy.epoch,sequence:head.sequence+1,revision:policy.revision,
            scope_digest:scopeDigest,count:head.count+1,consumed:[...head.consumed,grant.id]});
        return 'ADMITTED';
    });
}

async function readBounded(response, limit) {
    const declared = response.headers.get('content-length');
    if (declared !== null && (!/^\d+$/.test(declared) || Number(declared) > limit)) {
        throw new Error('Body exceeds bounds');
    }
    const reader = response.body?.getReader();
    if (!reader) {
        throw new Error('Missing body');
    }
    const decoder = new TextDecoder('utf-8',{fatal:true});
    let size = 0;
    let body = '';
    try {
        while (true) {
            const {value,done} = await reader.read();
            if (done) {
                return body + decoder.decode();
            }
            size += value.byteLength;
            if (size > limit) {
                throw new Error('Body exceeds bounds');
            }
            body += decoder.decode(value,{stream:true});
        }
    } finally {
        await reader.cancel().catch(()=>{});
        reader.releaseLock();
    }
}

async function call(service, path, data, decode = true) {
    if (!service || typeof service.fetch !== 'function') {
        throw new Error('Missing private service');
    }
    const signal = AbortSignal.timeout(5000);
    const response = await service.fetch(new Request('https://gateway-service.invalid/' + path,{
        method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(data),
        redirect:'manual',signal,
    }));
    if (!decode) {
        return response;
    }
    if (response.status !== 200 || !response.headers.get('content-type')?.startsWith('application/json')) {
        throw new Error('Private service unavailable');
    }
    return JSON.parse(await readBounded(response,MAX_BODY));
}

export default {
    async fetch() {
        // Service ingress and identity admission are not registered on public Worker routes.
        return new Response(null,{status:404});
    },
};

export class ProtectedReadAdmission {
    constructor(state, env) {
        this.storage = state.storage;
        this.env = env;
    }

    async fetch(inbound) {
        if (this.env.EXECUTION_MODE !== 'offline-fixture'
            || inbound.method !== 'POST' || new URL(inbound.url).pathname !== '/read') {
            return Response.json({state:'BLOCKED',reason:'GATEWAY_HELD'},{status:403});
        }

        try {
            const input = JSON.parse(await readBounded(inbound,32768));
            const {request,grant,signature} = input;
            const url = providerUrl(request);
            if (request.version !== VERSION || grant?.audience !== VERSION
                || !hex(grant.request_digest,64) || !hex(grant.id,32) || !hex(signature,128)) {
                return Response.json({state:'DENIED',reason:'GRANT_DENIED'},{status:403});
            }
            // Contract service validates the complete request, issuer, grant and signature
            // using the shared typed contract. It runs outside the WordPress trust domain.
            const proof = await call(this.env.CONTRACTS,'verify',{request,grant,signature});
            if (proof?.verified !== true || proof.request_digest !== grant.request_digest || proof.path !== url.pathname+url.search
                || !hex(proof.scope_digest,64)) {
                return Response.json({state:'DENIED',reason:'GRANT_DENIED'},{status:403});
            }
            const now = Math.floor(Date.now()/1000);
            const identity = {scope_digest:proof.scope_digest,request_digest:grant.request_digest,grant_id:grant.id,
                policy_revision:grant.policy_revision};
            const policy = await call(this.env.POLICY,'snapshot',identity);
            const admission = await admit(this.storage,grant,policy,now,proof.scope_digest);
            if (admission !== 'ADMITTED') {
                return this.reply(identity, now, admission === 'GRANT_DENIED' ? 'DENIED' : 'UNAVAILABLE', admission);
            }
            // An independently sequenced compare-and-advance is required even when the
            // Durable Object commits. An uncertain acknowledgement never permits a retry.
            const nextSequence = policy.sequence+1;
            const checkpoint = {epoch:policy.epoch,prior_sequence:policy.sequence,sequence:nextSequence};
            const ordered = await call(this.env.POLICY,'admit',{...identity,...checkpoint});
            if (ordered?.accepted !== true || ordered.epoch !== policy.epoch || ordered.sequence !== nextSequence
                || ordered.request_digest !== grant.request_digest || ordered.grant_id !== grant.id
                || ordered.scope_digest !== identity.scope_digest || ordered.policy_revision !== grant.policy_revision
                || grant.expires_at <= Math.floor(Date.now()/1000)) {
                return this.reply(identity,now,'DENIED','AUTHORITY_UNAVAILABLE');
            }
            // The offline binding receives fixed-origin typed transport arguments and
            // returns a mock response. This module never takes custody of a provider token.
            const response = await call(this.env.FIXTURE_CUSTODY,'get',{
                ...identity,...checkpoint,method:'GET',url:url.href,redirect:'deny',
                scope:{account:request.scope.account,shop:request.scope.shop,integration:request.scope.integration,
                    custody:request.scope.custody},
                max_body_bytes:MAX_BODY,timeout_ms:5000,
            },false);
            if (response.status !== 200) {
                await response.body?.cancel();
                return this.reply(identity,now,'UNAVAILABLE',response.status === 429 ? 'RATE_LIMITED' : 'RESPONSE_UNAVAILABLE');
            }
            if (!response.headers.get('content-type')?.startsWith('application/json')) {
                await response.body?.cancel();
                return this.reply(identity,now,'UNAVAILABLE','RESPONSE_UNAVAILABLE');
            }
            const body = await readBounded(response,MAX_BODY);
            const projection = await call(this.env.CONTRACTS,'project',{request,body});
            const current = await call(this.env.POLICY,'release',{...identity,...checkpoint});
            if (current?.accepted !== true || current.epoch !== policy.epoch || current.sequence !== nextSequence
                || current.request_digest !== grant.request_digest || current.grant_id !== grant.id
                || current.scope_digest !== identity.scope_digest || current.policy_revision !== grant.policy_revision
                || grant.expires_at <= Math.floor(Date.now()/1000)) {
                return this.reply(identity,now,'DENIED','AUTHORITY_UNAVAILABLE');
            }
            if (projection?.validated !== true || !projection.data || typeof projection.data !== 'object') {
                return this.reply(identity,now,'UNAVAILABLE','RESPONSE_UNVERIFIED');
            }
            const digest = await crypto.subtle.digest('SHA-256',new TextEncoder().encode(body));
            return this.reply(identity,now,'OBSERVED',null,{
                source:'SYNTHETIC_PRINTIFY',observed_at:now,response_sha256:Array.from(new Uint8Array(digest),
                    byte=>byte.toString(16).padStart(2,'0')).join(''),data:projection.data,
            });
        } catch {
            return Response.json({state:'UNAVAILABLE',reason:'GATEWAY_UNAVAILABLE'},{status:503});
        }
    }

    async reply(identity, issuedAt, state, reason, extra = {}) {
        const signed = await call(this.env.REPLY_SIGNER,'sign',{
            schema:'DF_STANDALONE_GET_REPLY_1',request_digest:identity.request_digest,grant_id:identity.grant_id,
            issued_at:issuedAt,state,reason,source:null,observed_at:null,provider_execution_authorized:false,
            retry_permitted:false,...extra,
        });
        return Response.json(signed);
    }
}
