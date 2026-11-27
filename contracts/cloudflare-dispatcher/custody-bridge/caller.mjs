import { canonical, hash, hex, keys, unb64 } from '../protocol.mjs';
import { CallerJournal } from '../stage-a-caller/journal.mjs';

const RESPONSE='DF_PRINTIFY_CUSTODY_READ_RECEIPT_1';
const FIXTURE='SIGNED_CUSTODY_FIXTURE';
const LIMIT=2000;
const ENCODER=new TextEncoder();
const result=(state,reason)=>({state,reason,retry_permitted:false});
const denied=()=>result('DENIED','CALLER_ADMISSION');
const uncertain=()=>result('UNKNOWN','CALLER_UNCERTAIN');

const CONFIG=[
  'shop','account_reference','credential_reference','source_sha','source_revision','authorization_revision',
  'signer_key','start','end','approval','request_sha256','operation_id',
];
export function checkedCustodyConfig(config) {
  keys(config,CONFIG);
  if (typeof config.shop!=='string' || !/^[1-9][0-9]{0,18}$/.test(config.shop)
      || !['account_reference','credential_reference'].every(k=>typeof config[k]==='string'
         && /^[A-Za-z0-9_-]{1,100}$/.test(config[k]))
      || !hex(config.source_sha,40) || !config.approval || config.approval==='0'.repeat(64)
      || !['approval','request_sha256','operation_id'].every(k=>hex(config[k]))
      || !['source_revision','authorization_revision','start','end'].every(k=>Number.isSafeInteger(config[k])&&config[k]>0)
      || config.end<=config.start || config.end-config.start>120) {
    throw new Error('Invalid custody caller configuration');
  }
  unb64(config.signer_key,32);
  return Object.freeze({...config});
}

export async function custodyRequest(payload,instance,config,now) {
  keys(payload,['schema','approval','wire']);
  if (payload.schema!=='DF_STAGE_A_CUSTODY_READ_1' || payload.approval!==config.approval
      || !Number.isSafeInteger(now) || now<config.start || now>=config.end
      || instance!=='stage-a-custody-'+config.operation_id || typeof payload.wire!=='string'
      || payload.wire.length>4096 || ENCODER.encode(payload.wire).length>4096
      || await hash(payload.wire)!==config.request_sha256) return false;
  const request=JSON.parse(payload.wire);
  return ['shop','account_reference','credential_reference','source_sha','source_revision',
    'authorization_revision','operation_id'].every(k=>request[k]===config[k])
    && request.schema==='DF_PRINTIFY_CUSTODY_READ_REQUEST_1' && request.evidence_class===FIXTURE
    && request.provider==='printify' && request.operation==='ORDER_READ' && request.method==='GET'
    && request.endpoint===`/v1/shops/${config.shop}/orders/order-1.json`
    && request.required_scope==='orders.read' && request.timeout_ms===LIMIT && request.max_response_bytes===262144;
}

export class CustodyCaller {
  #journal;
  #config;
  #clock;
  #service;
  constructor(storage,config,clock,service) {
    this.#config=checkedCustodyConfig(config);
    this.#clock=clock;
    this.#service=service;
    this.#journal=new CallerJournal(storage,{
      start:config.start,end:config.end,custody_scope:canonical(this.#config),
    },clock);
  }

  async invoke(payload,instance) {
    const begun=performance.now();
    const config=this.#config;
    const id=config.operation_id;
    try {
      if (!await custodyRequest(payload,instance,config,this.#clock())) return denied();
    } catch { return denied(); }
    let timer;
    try {
      const request={action:'ADVANCE_ONE',request_id:id,operation_id:id};
      const admission=this.#journal.admit(request,config.request_sha256);
      if (!admission.created) {
        return admission.state.operations[id]?.state==='COMPLETED'
          ? result('DENIED','CALLER_REPLAY') : uncertain();
      }
      this.#journal.latch(id);
      const rs=await Promise.race([
        new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('RPC timeout')),
          Math.max(0,LIMIT-(performance.now()-begun)));}),
        this.#service.exchange(payload.wire),
      ]);
      if (performance.now()-begun>=LIMIT || this.#clock()>=config.end) throw new Error('Window exhausted');
      if (rs?.state==='DENIED') {
        keys(rs,['state','reason','retry_permitted']);
        if (!['REVOKED','WINDOW','REPLAY','BUSY','WINDOW_BUDGET','BRIDGE_DISABLED','REQUEST'].includes(rs.reason)
            || rs.retry_permitted!==false) throw new Error('Invalid denial');
        this.#journal.complete(id,{state:'DENIED',reason:rs.reason});
        return rs;
      }
      const digest=await this.#verifiedReceipt(rs);
      if (performance.now()-begun>=LIMIT || this.#clock()>=config.end) throw new Error('Window exhausted');
      this.#journal.complete(id,{state:'COMPLETED',receipt_sha256:digest});
      return rs;
    } catch {
      try { this.#journal.unknown(id); } catch { /* A spent dispatch remains fenced on restart. */ }
      return uncertain();
    } finally {
      clearTimeout(timer);
    }
  }

  async #verifiedReceipt(rs) {
    keys(rs,['record','signature','body']);
    if (typeof rs.record!=='string' || rs.record.length>4096 || typeof rs.body!=='string'
        || rs.body.length>262144 || ENCODER.encode(rs.body).length>262144) throw new Error('Response bound');
    const data=JSON.parse(rs.record);
    const fields=['schema','evidence_class','request_sha256','content_sha256','observed_at','expires_at','status','retry_after'];
    keys(data,fields);
    const now=this.#clock(), config=this.#config;
    if (JSON.stringify(Object.fromEntries(fields.map(k=>[k,data[k]])))!==rs.record
        || data.schema!==RESPONSE || data.evidence_class!==FIXTURE || data.request_sha256!==config.request_sha256
        || data.content_sha256!==await hash(rs.body) || !Number.isSafeInteger(data.observed_at)
        || !Number.isSafeInteger(data.expires_at) || data.observed_at<config.start || data.observed_at>now
        || data.expires_at<=now || data.expires_at>config.end || data.expires_at-data.observed_at>30
        || !Number.isSafeInteger(data.retry_after) || data.retry_after<0 || data.retry_after>60
        || (data.status===200
          ? data.retry_after!==0 || rs.body!==JSON.stringify({id:'order-1',shop_id:config.shop,status:'fulfilled',line_items:[]})
          : data.status!==429 || rs.body!=='' || data.retry_after<1)) throw new Error('Receipt binding');
    const publicKey=await crypto.subtle.importKey('raw',unb64(config.signer_key,32),'Ed25519',false,['verify']);
    if (!await crypto.subtle.verify('Ed25519',publicKey,unb64(rs.signature,64),
      ENCODER.encode(RESPONSE+'\n'+rs.record))) throw new Error('Receipt signer');
    return hash(rs.record);
  }
}
