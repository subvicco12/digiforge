import { WorkerEntrypoint, DurableObject } from "cloudflare:workers";
import { keys, hex } from "../protocol.mjs";
import { createStageA, configuration } from "./factory.mjs";
import { sealEgress } from "./egress.mjs";
const denied = () => ({
  status: "QUALIFICATION_UNAVAILABLE",
  retry_permitted: false,
});
const closed = () =>
  new Response('{"status":"STAGE_A_HTTP_DISABLED"}', {
    status: 503,
    headers: {
      "Content-Type": "application/json",
      "Cache-Control": "no-store",
      "X-Content-Type-Options": "nosniff",
    },
  });
/** Only a separately approved service-binding capability can invoke these fixed private methods. */
export default class StageAQualification extends WorkerEntrypoint {
  fetch() {
    return closed();
  }
  async qualify(...args) {
    return this.#call("qualify", args);
  }
  async status(...args) {
    return this.#call("status", args);
  }
  async #call(method, args) {
    try {
      if (method === "qualify") {
        if (args.length !== 1) return denied();
        keys(args[0], ["intent"]);
        if (!hex(args[0].intent, 32)) return denied();
      } else if (args.length) return denied();
      const c = configuration(this.env, null, Math.floor(Date.now() / 1000));
      sealEgress().check();
      const stub = this.env.DF_QUAL_STATE.get(
        this.env.DF_QUAL_STATE.idFromName("digiforge-stage-a-singleton-v1"),
      );
      return await stub[method](...args);
    } catch {
      return denied();
    }
  }
}
export class DigiForgeQualificationState extends DurableObject {
  #runner;
  #ready;
  constructor(ctx, env) {
    super(ctx, env);
    this.#ready = ctx.blockConcurrencyWhile(async () => {
      try {
        this.#runner = createStageA(ctx, env);
        return true;
      } catch {
        return false;
      }
    });
  }
  fetch() {
    return closed();
  }
  async qualify(...args) {
    try {
      if (args.length !== 1 || !(await this.#ready)) return denied();
      keys(args[0], ["intent"]);
      if (!hex(args[0].intent, 32)) return denied();
      return await this.#runner.step(args[0].intent);
    } catch {
      return denied();
    }
  }
  async status(...args) {
    try {
      if (args.length || !(await this.#ready)) return denied();
      return this.#runner.status();
    } catch {
      return denied();
    }
  }
}
