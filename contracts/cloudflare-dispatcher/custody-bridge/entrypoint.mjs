import { DurableObject, WorkerEntrypoint } from 'cloudflare:workers';
import { sealEgress } from '../stage-a/egress.mjs';
import { CustodyBridge } from './bridge.mjs';

const denied=()=>({state:'DENIED',reason:'BRIDGE_DISABLED',retry_permitted:false});
const uncertain=()=>({state:'UNKNOWN',reason:'JOURNAL',retry_permitted:false});
const closed=()=>new Response('{"state":"DENIED"}',{
  status:503,headers:{'content-type':'application/json','cache-control':'no-store'},
});
const now=()=>Math.floor(Date.now()/1000);
const validWire=wire=>typeof wire==='string' && wire.length<=4096
  && new TextEncoder().encode(wire).length<=4096;

/** An approved service binding is required. No inbound HTTP invocation. */
export default class CustodyFixture extends WorkerEntrypoint {
  fetch() { return closed(); }

  async exchange(wire) {
    if (this.env.DF_CUSTODY_MODE!=='SYNTHETIC_TEST' || !validWire(wire)) return denied();
    try {
      sealEgress().check();
      return await this.env.DF_CUSTODY_STATE.get(
        this.env.DF_CUSTODY_STATE.idFromName('digiforge-custody-fixture-v1'),
      ).exchange(wire);
    } catch {
      return uncertain();
    }
  }
}

export class CustodyFixtureState extends DurableObject {
  #bridge;
  #ready;
  constructor(ctx,env) {
    super(ctx,env);
    this.#ready=ctx.blockConcurrencyWhile(async()=>{
      if (env.DF_CUSTODY_MODE!=='SYNTHETIC_TEST') return false;
      try {
        sealEgress().check();
        const scope=JSON.parse(env.DF_CUSTODY_SCOPE_JSON);
        if (scope.end<=now() || scope.start>now() || scope.end-now()>300) return false;
        const material=env.DF_CUSTODY_FIXTURE_SIGNER_PKCS8;
        if (typeof material!=='string' || material.length>256) return false;
        const key=await crypto.subtle.importKey('pkcs8',
          Uint8Array.from(atob(material),character=>character.charCodeAt(0)),
          'Ed25519',false,['sign']);
        this.#bridge=new CustodyBridge(ctx.storage,scope,now,
          bytes=>crypto.subtle.sign('Ed25519',key,bytes));
        return true;
      } catch {
        return false;
      }
    });
  }

  fetch() { return closed(); }

  async exchange(wire) {
    if (!validWire(wire) || !(await this.#ready)) return denied();
    return this.#bridge.exchange(wire);
  }
}
