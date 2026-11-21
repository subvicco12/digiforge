import { b64, canonical, hash, hex, keys, unb64 } from '../protocol.mjs';

const REQUEST='DF_PRINTIFY_CUSTODY_READ_REQUEST_1';
const RECEIPT='DF_PRINTIFY_CUSTODY_READ_RECEIPT_1';
export const SIMULATION_EVIDENCE='SIGNED_PROVIDER_TRANSPORT_SIMULATION';
const TIMEOUT=2000;
const MAX_BYTES=262144;
const MAX_ENTRIES=100;
const encoder=new TextEncoder();
const REQUEST_FIELDS=[
  'schema','evidence_class','operation_id','provider','shop','account_reference','credential_reference',
  'source_sha','source_revision','authorization_revision','operation','method','endpoint','required_scope',
  'typed_request_sha256','timeout_ms','max_response_bytes',
];
const BINDING_FIELDS=['shop','account_reference','credential_reference','source_sha','source_revision','authorization_revision'];
const outcome=(state,reason)=>({state,reason,retry_permitted:false});

function route(request) {
  const {operation, endpoint, shop}=request;
  const root='/v1/shops/'+shop;
  const simple={
    SHOPS_READ:['/v1/shops.json','shops.read'],
    PROVIDERS_READ:['/v1/catalog/print_providers.json','print_providers.read'],
  };
  if (Object.hasOwn(simple,operation)) {
    const [path,scope]=simple[operation];
    return endpoint===path?{scope}:null;
  }
  if (operation==='PROVIDER_READ') {
    const match=/^\/v1\/catalog\/print_providers\/([1-9][0-9]{0,9})\.json$/.exec(endpoint);
    return match?{scope:'print_providers.read',id:match[1]}:null;
  }
  if (['PRODUCT_READ','ORDER_READ'].includes(operation)) {
    const resource=operation==='PRODUCT_READ'?'products':'orders';
    const match=new RegExp('^'+root+'/'+resource+'/([A-Za-z0-9_-]{1,160})\\.json$').exec(endpoint);
    return match?{scope:resource+'.read',id:match[1]}:null;
  }
  if (['PRODUCTS_READ','ORDERS_READ'].includes(operation)) {
    const resource=operation==='PRODUCTS_READ'?'products':'orders';
    const match=new RegExp('^'+root+'/'+resource+'\\.json\\?page=([1-3])&limit=(10|[1-9])$').exec(endpoint);
    return match?{scope:resource+'.read',page:Number(match[1]),limit:Number(match[2])}:null;
  }
  return null;
}

function validResponse(value, request, path) {
  const object=v=>v && typeof v==='object' && !Array.isArray(v);
  const identifier=v=>typeof v==='string' && /^[A-Za-z0-9_-]{1,160}$/.test(v)
    || Number.isSafeInteger(v) && v>0;
  const scoped=v=>object(v) && identifier(v.id)
    && (!Object.hasOwn(v,'shop_id') || identifier(v.shop_id) && String(v.shop_id)===request.shop);
  if (request.operation==='SHOPS_READ') {
    return Array.isArray(value) && value.length<=1 && value.every(v=>scoped(v)&&String(v.id)===request.shop);
  }
  if (request.operation==='PROVIDERS_READ') {
    return Array.isArray(value) && value.length<=MAX_ENTRIES && value.every(scoped);
  }
  if (path.id) return scoped(value) && String(value.id)===path.id;
  return object(value) && Array.isArray(value.data) && value.data.length<=path.limit && value.data.every(scoped)
    && value.current_page===path.page && value.per_page===path.limit
    && Number.isSafeInteger(value.last_page) && value.last_page>=path.page && value.last_page<=2147483647;
}

async function readBody(stream) {
  if (!stream || typeof stream[Symbol.asyncIterator]!=='function') throw new Error('STREAM');
  const decoder=new TextDecoder('utf-8',{fatal:true,ignoreBOM:true});
  let size=0, raw='';
  for await (const chunk of stream) {
    if (!(chunk instanceof Uint8Array) || size+chunk.byteLength>MAX_BYTES) throw new Error('SIZE');
    size+=chunk.byteLength;
    raw+=decoder.decode(chunk,{stream:true});
  }
  return raw+decoder.decode();
}

/**
 * Private synthetic connector boundary only. Authority owns durable admission, quota,
 * currentness and final fencing. An operational authority/connector is not composed here.
 */
export class ProviderReadTransport {
  #scope;
  #authority;
  #connector;
  #sign;
  #clock;
  #telemetry;
  constructor({scope,authority,connector,sign,clock,telemetry=()=>{}}) {
    keys(scope,[...BINDING_FIELDS,'signer_key']);
    if (typeof scope.shop!=='string' || !/^[1-9][0-9]{0,18}$/.test(scope.shop)
        || !['account_reference','credential_reference'].every(k=>typeof scope[k]==='string' && /^[A-Za-z0-9_-]{1,100}$/.test(scope[k]))
        || !hex(scope.source_sha,40)
        || !['source_revision','authorization_revision'].every(k=>Number.isSafeInteger(scope[k])&&scope[k]>0)
        || !['reserve','current','finish'].every(k=>typeof authority?.[k]==='function')
        || typeof connector?.get!=='function' || typeof sign!=='function' || typeof clock!=='function'
        || typeof telemetry!=='function') throw new TypeError('Invalid provider simulation ports');
    unb64(scope.signer_key,32);
    this.#scope=Object.freeze({...scope});
    this.#authority=authority;
    this.#connector=connector;
    this.#sign=sign;
    this.#clock=clock;
    this.#telemetry=telemetry;
  }

  async #parse(wire) {
    if (typeof wire!=='string' || wire.length>4096 || encoder.encode(wire).length>4096) return null;
    const request=JSON.parse(wire);
    keys(request,REQUEST_FIELDS);
    if (JSON.stringify(Object.fromEntries(REQUEST_FIELDS.map(k=>[k,request[k]])))!==wire
        || request.schema!==REQUEST || request.evidence_class!==SIMULATION_EVIDENCE || !hex(request.operation_id)
        || request.provider!=='printify' || request.method!=='GET'
        || typeof request.endpoint!=='string' || /[\r\n]/.test(request.endpoint)
        || request.timeout_ms!==TIMEOUT || request.max_response_bytes!==MAX_BYTES
        || !hex(request.typed_request_sha256) || !BINDING_FIELDS.every(k=>request[k]===this.#scope[k])) return null;
    const path=route(request);
    if (!path || request.required_scope!==path.scope) return null;
    // Product selectors include additional typed input. Its exact opaque digest
    // is joined against the authoritative plan by reserve(), which owns approval.
    if (request.operation!=='PRODUCT_READ' && request.typed_request_sha256!==await hash(canonical({
      operation:request.operation,provider_shop_id:request.shop,method:'GET',endpoint:request.endpoint,body:[],required_scope:path.scope,
    }))) return null;
    return [Object.freeze({...request,request_sha256:await hash(wire)}),path];
  }

  async #receipt(binding,status,body,retry_after) {
    const now=this.#clock();
    if (!Number.isSafeInteger(now) || now<=0 || now>Number.MAX_SAFE_INTEGER-30) throw new Error('CLOCK');
    const record=JSON.stringify({
      schema:RECEIPT,evidence_class:SIMULATION_EVIDENCE,request_sha256:binding.request_sha256,
      content_sha256:await hash(body),observed_at:now,expires_at:now+30,status,retry_after,
    });
    const bytes=encoder.encode(RECEIPT+'\n'+record);
    const signature=await this.#sign(bytes);
    const key=await crypto.subtle.importKey('raw',unb64(this.#scope.signer_key,32),'Ed25519',false,['verify']);
    if (!await crypto.subtle.verify('Ed25519',key,signature,bytes)) throw new Error('SIGNER');
    return {record,signature:b64(signature),body};
  }

  #observe(state,reason,binding) {
    try {
      Promise.resolve(this.#telemetry({state,reason,operation:binding?.operation??null,
        operation_id:binding?.operation_id??null})).catch(()=>{});
    } catch { /* Diagnostics never decide admission. */ }
  }

  async exchange(wire) {
    const controller=new AbortController(), {signal}=controller;
    const timer=setTimeout(()=>controller.abort(),TIMEOUT);
    const deadline=performance.now()+TIMEOUT;
    const wait=async value=>{
      signal.throwIfAborted();
      let onAbort;
      try {
        return await Promise.race([value,new Promise((_,reject)=>{
          onAbort=()=>reject(new Error('TIMEOUT'));
          signal.addEventListener('abort',onAbort,{once:true});
        })]);
      } finally {
        signal.removeEventListener('abort',onAbort);
      }
    };
    let binding,token;
    const finish=(state)=>this.#authority.finish(binding,token,state,{signal});
    const reject=(state,reason)=>{
      this.#observe(state,reason,binding);
      return outcome(state,reason);
    };
    try {
      let parsed;
      try { parsed=await wait(this.#parse(wire)); } catch { return reject('DENIED','REQUEST'); }
      if (!parsed) return reject('DENIED','REQUEST');
      [binding]=parsed;
      const admission=await wait(this.#authority.reserve(binding,{signal}));
      if (admission===null) return reject('DENIED','AUTHORITY');
      if (!hex(admission?.token)) throw new Error('AUTHORITY');
      token=admission.token;
      const limited=admission.state==='RATE_LIMITED';
      keys(admission,limited?['state','token','retry_after']:['state','token']);
      if (limited
        ? !Number.isSafeInteger(admission.retry_after)||admission.retry_after<1||admission.retry_after>60
        : admission.state!=='ADMITTED') throw new Error('AUTHORITY');
      if (await wait(this.#authority.current(binding,token,{signal}))!==true) {
        if (await wait(finish('DENIED'))!==true) throw new Error('FENCE');
        return reject('DENIED','CURRENTNESS');
      }
      signal.throwIfAborted();
      if (performance.now()>=deadline) throw new Error('TIMEOUT');
      let status=429, body='', retry=admission.retry_after??0;
      if (!limited) {
        const response=await wait(this.#connector.get(binding,{signal,maxResponseBytes:MAX_BYTES}));
        keys(response,['status','headers','body']);
        if (!response.headers || Array.isArray(response.headers) || typeof response.headers!=='object') throw new Error('HEADERS');
        status=response.status;
        if (status===429) {
          const value=response.headers['retry-after'];
          retry=typeof value==='string' && /^[1-9][0-9]{0,8}$/.test(value)?Math.min(60,Number(value)):60;
        } else if (status===200) {
          if (typeof response.headers['content-type']!=='string'
              || !/^application\/json(?:;\s*charset=utf-8)?$/i.test(response.headers['content-type'])) throw new Error('CONTENT_TYPE');
          body=await wait(readBody(response.body));
          if (!validResponse(JSON.parse(body),binding,parsed[1])) throw new Error('BODY');
        } else if (![400,401,403,404,422].includes(status)) throw new Error('STATUS');
      }
      const receipt=await wait(this.#receipt(binding,status,body,retry));
      if (performance.now()>=deadline || await wait(finish('COMPLETED'))!==true || performance.now()>=deadline) {
        throw new Error('FINAL_FENCE');
      }
      this.#observe('COMPLETED',String(status),binding);
      return receipt;
    } catch {
      if (token) {
        try {
          const final=Promise.resolve(finish('UNKNOWN'));
          if (signal.aborted) final.catch(()=>{});
          else await wait(final);
        } catch { /* Pending operations remain fenced by the authority port. */ }
      }
      return reject('UNKNOWN',signal.aborted?'DEADLINE':'PROVIDER_READ_UNCERTAIN');
    } finally {
      clearTimeout(timer);
      controller.abort();
    }
  }
}
