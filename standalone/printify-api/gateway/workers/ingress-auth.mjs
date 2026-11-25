const algorithm = 'RSASSA-PKCS1-v1_5';
const encoder = new TextEncoder();
const decoder = new TextDecoder('utf-8',{fatal:true});
const hex = (v,n) => typeof v==='string' && v.length===n && /^[0-9a-f]+$/.test(v);
const object = v => v!==null && typeof v==='object' && !Array.isArray(v);
const encode = bytes => btoa(String.fromCharCode(...bytes)).replaceAll('+','-').replaceAll('/','_').replace(/=+$/,'');
const json = value => encode(encoder.encode(JSON.stringify(value)));
const kid = v => typeof v==='string' && /^[A-Za-z0-9_-]{1,100}$/.test(v);
function decode(value) {
    if (!/^[A-Za-z0-9_-]+$/.test(value)) {
        throw new Error('Malformed JWT encoding');
    }
    const bytes = Uint8Array.from(atob(value.replaceAll('-','+').replaceAll('_','/')),c=>c.charCodeAt(0));
    if (encode(bytes)!==value) {
        throw new Error('Noncanonical JWT encoding');
    }
    return bytes;
}
const rsaKey = (key, type) => key?.type===type && key.algorithm?.name===algorithm
    && key.algorithm?.hash?.name==='SHA-256' && key.algorithm.modulusLength>=2048 && key.algorithm.modulusLength<=4096;

export async function signJwt(key, id, claims) {
    if (!rsaKey(key,'private') || key.extractable || !kid(id)) {
        throw new Error('Invalid signing key');
    }
    const value=json({alg:'RS256',typ:'JWT',kid:id})+'.'+json(claims);
    return value+'.'+encode(new Uint8Array(await crypto.subtle.sign(algorithm,key,encoder.encode(value))));
}

export async function verifyJwt(token,config,keySet,now=Math.floor(Date.now()/1000)) {
    try {
        if (typeof token!=='string' || token.length>8192 || !Number.isSafeInteger(now)
            || typeof config?.issuer!=='string' || !config.issuer || typeof config.audience!=='string' || !config.audience
            || !Number.isSafeInteger(config.maxAge) || config.maxAge<1 || config.maxAge>86400
            || !object(keySet?.keys) || !Number.isSafeInteger(keySet.expiresAt) || keySet.expiresAt<=now) {
            return null;
        }
        const parts=token.split('.');
        if (parts.length!==3) return null;
        const header=JSON.parse(decoder.decode(decode(parts[0])));
        if (!object(header) || header.alg!=='RS256' || (header.typ!==undefined && header.typ!=='JWT')
            || !kid(header.kid) || !Object.hasOwn(keySet.keys,header.kid)
            || Object.keys(header).some(key=>!['alg','typ','kid'].includes(key))) return null;
        const key=keySet.keys[header.kid];
        if (!rsaKey(key,'public') || !await crypto.subtle.verify(algorithm,key,decode(parts[2]),
            encoder.encode(parts[0]+'.'+parts[1]))) return null;
        const claims=JSON.parse(decoder.decode(decode(parts[1])));
        if (!object(claims) || claims.iss!==config.issuer
            || (claims.aud!==config.audience && !(Array.isArray(claims.aud) && claims.aud.length===1 && claims.aud[0]===config.audience))
            || !Number.isSafeInteger(claims.iat) || claims.iat>now || claims.iat<now-config.maxAge
            || !Number.isSafeInteger(claims.exp) || claims.exp<=now || claims.exp-claims.iat>config.maxAge
            || (claims.nbf!==undefined && (!Number.isSafeInteger(claims.nbf) || claims.nbf>now || claims.nbf>=claims.exp))
            || (config.identityClaim!==undefined && (!['sub','common_name'].includes(config.identityClaim)
                || typeof config.identity!=='string' || !config.identity || claims[config.identityClaim]!==config.identity))) {
            return null;
        }
        return claims;
    } catch {
        return null;
    }
}

export async function pinnedKeys(jwks,expiresAt) {
    if (!Array.isArray(jwks?.keys) || jwks.keys.length<1 || jwks.keys.length>8 || !Number.isSafeInteger(expiresAt)) {
        throw new Error('Invalid key set');
    }
    const keys=Object.create(null);
    for (const jwk of jwks.keys) {
        if (!kid(jwk?.kid) || Object.hasOwn(keys,jwk.kid) || jwk.kty!=='RSA'
            || (jwk.alg!==undefined && jwk.alg!=='RS256') || (jwk.use!==undefined && jwk.use!=='sig')
            || typeof jwk.n!=='string' || jwk.n.length<342 || jwk.n.length>683 || typeof jwk.e!=='string'
            || jwk.e.length>8 || Object.hasOwn(jwk,'d')) throw new Error('Invalid RSA key');
        const key=await crypto.subtle.importKey('jwk',{kty:'RSA',n:jwk.n,e:jwk.e},
            {name:algorithm,hash:'SHA-256'},false,['verify']);
        if (!rsaKey(key,'public')) throw new Error('Invalid RSA strength');
        keys[jwk.kid]=key;
    }
    return {keys,expiresAt};
}

function binding(envelope,workspace) {
    const {request,grant}=envelope;
    const {site,actor,session,revision,account,shop,integration}=request.scope;
    if (![site,account,shop,integration,request.operation].every(v=>typeof v==='string' && /^[A-Za-z0-9_-]{1,100}$/.test(v))
        || !Number.isSafeInteger(actor) || actor<1 || !hex(session,64) || !hex(revision,64) || !hex(workspace,64)
        || !hex(grant.request_digest,64) || !hex(grant.id,32)) throw new Error('Invalid attestation binding');
    return {site,actor,session,revision,workspace,account,shop,integration,operation:request.operation,
        request_digest:grant.request_digest,jti:grant.id};
}

const same = (expected,actual) => object(actual) && Object.entries(expected).every(([key,value])=>actual[key]===value);

/** The trusted current-state source must independently attest these values, including revocation. */
export class OperatorAssertions {
    constructor(config) { this.config=config; }

    async issue(envelope,workspace,now=Math.floor(Date.now()/1000)) {
        try {
            const expected=binding(envelope,workspace);
            if (!same(expected,await this.config.current(expected))) return null;
            return await signJwt(this.config.signingKey,this.config.kid,{...expected,
                iss:this.config.issuer,aud:this.config.audience,iat:now,nbf:now,exp:now+30});
        } catch {
            return null;
        }
    }

    async verify(token,envelope,workspace,now=Math.floor(Date.now()/1000)) {
        try {
            const expected=binding(envelope,workspace);
            const claims=await verifyJwt(token,{...this.config,maxAge:30},this.config.keySet,now);
            // The assertion ID is the grant ID. Durable admission consumes that same ID.
            return same(expected,claims) && same(expected,await this.config.current(expected)) ? expected : null;
        } catch {
            return null;
        }
    }
}
