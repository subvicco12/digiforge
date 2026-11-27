import { b64, canonical, hash, hex, keys, unb64 } from '../protocol.mjs';

const REQUEST='DF_PRINTIFY_CUSTODY_READ_REQUEST_1';
const RECEIPT='DF_PRINTIFY_CUSTODY_READ_RECEIPT_1';
const FIXTURE='SIGNED_CUSTODY_FIXTURE';
const REQUEST_KEYS=[
  'schema','evidence_class','operation_id','provider','shop','account_reference','credential_reference',
  'source_sha','source_revision','authorization_revision','operation','method','endpoint','required_scope',
  'typed_request_sha256','timeout_ms','max_response_bytes',
];
const SCOPE_KEYS=[
  'shop','account_reference','credential_reference','source_sha','source_revision','authorization_revision',
  'signer_key','start','end',
];
const ENCODER=new TextEncoder();
const DEADLINE=2000;
const CAP=24;
const outcome=(state,reason)=>({state,reason,retry_permitted:false});

/** Private fixture exchange. The caller supplies storage and a synthetic signing port. */
export class CustodyBridge {
  #storage;
  #scope;
  #clock;
  #sign;
  constructor(storage,scope,clock,sign) {
    keys(scope,SCOPE_KEYS);
    if (!/^[1-9][0-9]{0,18}$/.test(scope.shop) || typeof scope.shop!=='string'
        || !['account_reference','credential_reference'].every(k=>typeof scope[k]==='string'
          && /^[A-Za-z0-9_-]{1,100}$/.test(scope[k]))
        || !hex(scope.source_sha,40) || !['source_revision','authorization_revision','start','end'].every(
          k=>Number.isSafeInteger(scope[k])&&scope[k]>0)
        || scope.end<=scope.start || scope.end-scope.start>300
        || typeof clock!=='function' || typeof sign!=='function') {
      throw new TypeError('Invalid custody fixture configuration');
    }
    unb64(scope.signer_key,32);
    this.#scope=Object.freeze({...scope});
    this.#storage=storage;
    this.#clock=clock;
    this.#sign=sign;
    this.#storage.transactionSync(()=>{
      this.#storage.sql.exec('CREATE TABLE IF NOT EXISTS df_custody_fixture (id INTEGER PRIMARY KEY CHECK(id=1), document TEXT NOT NULL)');
      this.#storage.sql.exec('INSERT OR IGNORE INTO df_custody_fixture VALUES(1,?)',canonical({
        scope:this.#scope, revoked:false, last:this.#scope.start, attempts:{},
      }));
      const doc=this.#load();
      let changed=false;
      for (const entry of Object.values(doc.attempts)) {
        if (entry.state==='PENDING') {
          entry.state='UNKNOWN';
          changed=true;
        }
      }
      if (changed) this.#save(doc);
    });
  }

  #load() {
    const rows=this.#storage.sql.exec('SELECT document FROM df_custody_fixture WHERE id=1').toArray();
    if (rows.length!==1 || typeof rows[0].document!=='string' || rows[0].document.length>16384) {
      throw new Error('Custody journal missing or oversized');
    }
    const doc=JSON.parse(rows[0].document);
    keys(doc,['scope','revoked','last','attempts']);
    if (canonical(doc)!==rows[0].document || canonical(doc.scope)!==canonical(this.#scope)
        || typeof doc.revoked!=='boolean' || !Number.isSafeInteger(doc.last) || doc.last<this.#scope.start
        || !doc.attempts || Object.getPrototypeOf(doc.attempts)!==Object.prototype
        || Object.entries(doc.attempts).length>CAP) {
      throw new Error('Custody journal drift');
    }
    for (const [id,entry] of Object.entries(doc.attempts)) {
      keys(entry,['digest','at','state','status']);
      if (!hex(id) || !hex(entry.digest) || !Number.isSafeInteger(entry.at)
          || entry.at<this.#scope.start || entry.at>doc.last
          || !['PENDING','COMPLETED','UNKNOWN'].includes(entry.state)
          || ![200,429].includes(entry.status)) {
        throw new Error('Custody journal entry corrupt');
      }
    }
    return doc;
  }

  #save(doc) {
    this.#storage.sql.exec('UPDATE df_custody_fixture SET document=? WHERE id=1',canonical(doc));
  }

  /** For a trusted lifecycle controller only. No request method exposes this operation. */
  revoke() {
    this.#storage.transactionSync(()=>{
      const doc=this.#load();
      doc.revoked=true;
      for (const attempt of Object.values(doc.attempts)) {
        if (attempt.state==='PENDING') attempt.state='UNKNOWN';
      }
      this.#save(doc);
    });
  }

  async #parse(wire) {
    if (typeof wire!=='string' || wire.length>4096 || ENCODER.encode(wire).length>4096) return null;
    const request=JSON.parse(wire);
    keys(request,REQUEST_KEYS);
    if (JSON.stringify(Object.fromEntries(REQUEST_KEYS.map(k=>[k,request[k]])))!==wire
        || request.schema!==REQUEST || request.evidence_class!==FIXTURE || !hex(request.operation_id)
        || request.provider!=='printify' || request.operation!=='ORDER_READ' || request.method!=='GET'
        || request.endpoint!==`/v1/shops/${this.#scope.shop}/orders/order-1.json`
        || request.required_scope!=='orders.read' || request.timeout_ms!==DEADLINE
        || request.max_response_bytes!==262144
        || !SCOPE_KEYS.slice(0,6).every(k=>request[k]===this.#scope[k])) {
      return null;
    }
    const typed=canonical({
      operation:'ORDER_READ',provider_shop_id:this.#scope.shop,method:'GET',endpoint:request.endpoint,
      body:[],required_scope:'orders.read',
    });
    return request.typed_request_sha256===await hash(typed)?request:null;
  }

  #reserve(id,digest) {
    return this.#storage.transactionSync(()=>{
      const doc=this.#load();
      if (doc.revoked) return outcome('DENIED','REVOKED');
      const at=this.#clock();
      if (!Number.isSafeInteger(at) || at<doc.last || at>=this.#scope.end) return outcome('DENIED','WINDOW');
      if (Object.values(doc.attempts).some(a=>a.state==='UNKNOWN')) return outcome('UNKNOWN','RECOVERY_REQUIRED');
      if (doc.attempts[id]) return outcome('DENIED','REPLAY');
      if (Object.values(doc.attempts).some(a=>a.state==='PENDING')) return outcome('DENIED','BUSY');
      if (Object.keys(doc.attempts).length>=CAP) return outcome('DENIED','WINDOW_BUDGET');
      const recent=Object.values(doc.attempts).filter(a=>a.at>at-60 && a.status===200);
      const retry=recent.length>=3?60+Math.min(...recent.map(a=>a.at))-at:0;
      doc.last=at;
      doc.attempts[id]={digest,at,state:'PENDING',status:retry>0?429:200};
      this.#save(doc);
      return {at,retry};
    });
  }

  #finish(id,digest,success) {
    return this.#storage.transactionSync(()=>{
      const doc=this.#load(), entry=doc.attempts[id], now=this.#clock();
      if (!entry || entry.digest!==digest || entry.state!=='PENDING') return false;
      const verified=success && !doc.revoked && Number.isSafeInteger(now)
        && now>=doc.last && now<this.#scope.end;
      entry.state=verified?'COMPLETED':'UNKNOWN';
      if (verified) doc.last=now;
      this.#save(doc);
      return verified;
    });
  }

  async #signReceipt(digest,at,retry) {
    const body=retry>0?'':JSON.stringify({
      id:'order-1',shop_id:this.#scope.shop,status:'fulfilled',line_items:[],
    });
    const record=JSON.stringify({
      schema:RECEIPT,evidence_class:FIXTURE,request_sha256:digest,content_sha256:await hash(body),
      observed_at:at,expires_at:Math.min(at+30,this.#scope.end),status:retry>0?429:200,retry_after:retry,
    });
    const material=ENCODER.encode(RECEIPT+'\n'+record);
    const signature=await this.#sign(material);
    const signer=await crypto.subtle.importKey('raw',unb64(this.#scope.signer_key,32),'Ed25519',false,['verify']);
    if (!(await crypto.subtle.verify('Ed25519',signer,signature,material))) {
      throw new Error('Unexpected signer');
    }
    return {record,signature:b64(signature),body};
  }

  async exchange(wire) {
    const started=performance.now();
    let request;
    try { request=await this.#parse(wire); } catch { return outcome('DENIED','REQUEST'); }
    if (!request) return outcome('DENIED','REQUEST');
    const id=request.operation_id, digest=await hash(wire);
    let reservation;
    try { reservation=this.#reserve(id,digest); } catch { return outcome('UNKNOWN','JOURNAL'); }
    if (reservation.state) return reservation;
    let timer;
    const timeout={};
    try {
      const remaining=Math.max(0,DEADLINE-(performance.now()-started));
      const response=await Promise.race([
        new Promise(resolve=>{ timer=setTimeout(()=>resolve(timeout),remaining); }),
        this.#signReceipt(digest,reservation.at,reservation.retry),
      ]);
      if (response===timeout || performance.now()-started>=DEADLINE) {
        this.#finish(id,digest,false);
        return outcome('TIMEOUT','DEADLINE');
      }
      return this.#finish(id,digest,true)?response:outcome('UNKNOWN','FINAL_FENCE');
    } catch {
      try { this.#finish(id,digest,false); } catch { /* Pending state remains spent after restart. */ }
      return outcome('UNKNOWN','RECEIPT');
    } finally {
      clearTimeout(timer);
    }
  }
}
