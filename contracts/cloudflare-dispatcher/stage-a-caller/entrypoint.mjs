import { DurableObject, WorkflowEntrypoint } from "cloudflare:workers";
import {
  callerConfiguration,
  operatorRequest,
  CALLER_OBJECT,
} from "./contract.mjs";
import { CallerJournal } from "./journal.mjs";
import { PrivateCaller } from "./caller.mjs";
import { sealEgress } from "../stage-a/egress.mjs";
export const STEP_POLICY = Object.freeze({
  retries: Object.freeze({ limit: 0, delay: 1000, backoff: "constant" }),
  timeout: "50 seconds",
});
const denied = () => ({
  status: "DENIED",
  retry_permitted: false,
  recovery: "RECOVERY_BLOCKED",
});
const closed = () =>
  new Response('{"status":"CALLER_HTTP_DISABLED"}', {
    status: 503,
    headers: {
      "Content-Type": "application/json",
      "Cache-Control": "no-store",
    },
  });
// No HTTP or default RPC initiation method. Only the platform-managed Workflow is an initiation path.
export default { fetch: closed };
export class DigiForgeStageAManual extends WorkflowEntrypoint {
  async run(event, step) {
    let admitted = false;
    try {
      if (event.schedule !== undefined) return denied();
      const config = callerConfiguration(this.env);
      operatorRequest(
        event.payload,
        config,
        Math.floor(Date.now() / 1000),
        event.instanceId,
      );
      sealEgress().check();
      const stub = this.env.DF_CALLER_STATE.get(
        this.env.DF_CALLER_STATE.idFromName(CALLER_OBJECT),
      );
      admitted = true;
      return await step.do(
        "one-manually-authorized-stage-a-operation",
        STEP_POLICY,
        () => stub.invoke(event.payload, event.instanceId),
      );
    } catch {
      return {
        status: admitted ? "UNKNOWN" : "DENIED",
        retry_permitted: false,
        recovery: "RECOVERY_BLOCKED",
      };
    }
  }
}
export class DigiForgeStageACallerState extends DurableObject {
  #caller;
  #ready;
  constructor(ctx, env) {
    super(ctx, env);
    this.#ready = ctx.blockConcurrencyWhile(async () => {
      try {
        const config = callerConfiguration(env, ctx);
        sealEgress().check();
        const clock = () => Math.floor(Date.now() / 1000);
        const journal = new CallerJournal(ctx.storage, config, clock);
        this.#caller = new PrivateCaller({
          journal,
          service: env.DF_STAGE_A,
          config,
          clock,
        });
        return true;
      } catch {
        return false;
      }
    });
  }
  fetch() {
    return closed();
  }
  async invoke(...args) {
    try {
      if (args.length !== 2 || !(await this.#ready)) return denied();
      return await this.#caller.execute(args[0], args[1]);
    } catch {
      return {
        status: "UNKNOWN",
        retry_permitted: false,
        recovery: "RECOVERY_BLOCKED",
      };
    }
  }
}
