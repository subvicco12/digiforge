import { DurableObject, WorkflowEntrypoint } from 'cloudflare:workers';
import { sealEgress } from '../stage-a/egress.mjs';
import { checkedCustodyConfig, CustodyCaller, custodyRequest } from './caller.mjs';

const closed=()=>new Response('{"state":"DENIED"}',{
  status:503,headers:{'content-type':'application/json','cache-control':'no-store'},
});
const deny=()=>({state:'DENIED',reason:'CALLER_ADMISSION',retry_permitted:false});
const unknown=()=>({state:'UNKNOWN',reason:'CALLER_UNCERTAIN',retry_permitted:false});
const CALLER_OBJECT='digiforge-stage-a-custody-caller-v1';
const now=()=>Math.floor(Date.now()/1000);

function config(env) {
  if (env.DF_CUSTODY_CALLER_MODE!=='SYNTHETIC_TEST' || typeof env.DF_CUSTODY_CALLER_CONFIG!=='string'
      || env.DF_CUSTODY_CALLER_CONFIG.length>4096) throw new Error('Custody caller disabled');
  return checkedCustodyConfig(JSON.parse(env.DF_CUSTODY_CALLER_CONFIG));
}

// Separately reviewed build for the existing Stage A manual path. No HTTP or default RPC.
export default { fetch:closed };
export class DigiForgeStageAManual extends WorkflowEntrypoint {
  async run(event,step) {
    let attempted=false;
    try {
      if (event.schedule!==undefined || !await custodyRequest(event.payload,event.instanceId,config(this.env),now())) return deny();
      sealEgress().check();
      const space=this.env.DF_CALLER_STATE;
      const stub=space.get(space.idFromName(CALLER_OBJECT));
      attempted=true;
      return await step.do('single-approved-custody-read',{
        retries:{limit:0,delay:1000,backoff:'constant'},timeout:'5 seconds',
      },()=>stub.invoke(event.payload,event.instanceId));
    } catch { return attempted?unknown():deny(); }
  }
}

export class DigiForgeStageACallerState extends DurableObject {
  #caller;
  #ready;
  constructor(ctx,env) {
    super(ctx,env);
    this.#ready=ctx.blockConcurrencyWhile(async()=>{
      try {
        const binding=config(env);
        if (ctx.id.toString()!==env.DF_CALLER_STATE.idFromName(CALLER_OBJECT).toString()
            || typeof env.DF_CUSTODY_BRIDGE?.exchange!=='function') return false;
        sealEgress().check();
        this.#caller=new CustodyCaller(ctx.storage,binding,now,env.DF_CUSTODY_BRIDGE);
        return true;
      } catch { return false; }
    });
  }
  fetch() { return closed(); }
  async invoke(payload,instance) {
    try { return await this.#ready?await this.#caller.invoke(payload,instance):deny(); }
    catch { return unknown(); }
  }
}
