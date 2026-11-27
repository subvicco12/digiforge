import { keys, insist, hex } from "../protocol.mjs";
import { sealEgress } from "./egress.mjs";
import { RunBudget, CAPS } from "./state.mjs";
import { CASES, runCase } from "./suite.mjs";
export const OBJECT_NAME = "digiforge-stage-a-singleton-v1";
export const MODE = "STAGE_A_SYNTHETIC_V1";
export function configuration(env, ctx, now) {
  keys(env, [
    "DF_QUAL_STATE",
    "DF_QUAL_MODE",
    "DF_QUAL_WINDOW_START",
    "DF_QUAL_WINDOW_END",
    "DF_QUAL_BUILD_ID",
  ]);
  insist(env.DF_QUAL_MODE === MODE, "SYNTHETIC_MODE_REQUIRED");
  for (const k of ["DF_QUAL_WINDOW_START", "DF_QUAL_WINDOW_END"])
    insist(
      typeof env[k] === "string" && /^[0-9]{1,12}$/.test(env[k]),
      "TEST_WINDOW",
    );
  const start = Number(env.DF_QUAL_WINDOW_START),
    end = Number(env.DF_QUAL_WINDOW_END);
  insist(
    end > start &&
      end - start <= CAPS.window &&
      now >= start &&
      now < end &&
      hex(env.DF_QUAL_BUILD_ID),
    "TEST_WINDOW",
  );
  const ns = env.DF_QUAL_STATE;
  insist(
    ns && typeof ns.idFromName === "function" && typeof ns.get === "function",
    "NAMESPACE",
  );
  const object_id = ns.idFromName(OBJECT_NAME).toString();
  insist(
    hex(object_id) && (!ctx || ctx.id?.toString() === object_id),
    "NAMESPACE",
  );
  return { build: env.DF_QUAL_BUILD_ID, object_id, start, end };
}
/** Synthetic-only platform factory. Does not import any provider sender, vault, transport or business runtime. */
export function createStageA(
  ctx,
  env,
  { clock = () => Math.floor(Date.now() / 1000), globals = globalThis } = {},
) {
  const config = configuration(env, ctx, clock()),
    guard = sealEgress(globals);
  guard.check();
  const budget = new RunBudget(ctx.storage, config, clock);
  return Object.freeze({
    async step(intent = crypto.randomUUID().replaceAll("-", "")) {
      let admitted = false;
      try {
        guard.check();
        const d = budget.admit(true, intent);
        if (d.intent_results[intent]) {
          const cached = d.intent_results[intent];
          return {
            ...cached.result,
            case_index: cached.index,
            case_count: CASES.length,
            complete: cached.index === CASES.length,
            invocations: d.used,
            storage_bytes: ctx.storage.sql.databaseSize,
            build: config.build,
            recovery: "RECOVERY_BLOCKED",
          };
        }
        admitted = true;
        insist(d.index < CASES.length, "RUN_COMPLETE");
        const result = await runCase(CASES[d.index], {
          storage: ctx.storage,
          config,
          budget,
          globals,
        });
        guard.check();
        budget.check();
        const final = budget.finish(result);
        return {
          ...result,
          case_index: final.index,
          case_count: CASES.length,
          complete: final.index === CASES.length,
          invocations: final.used,
          storage_bytes: ctx.storage.sql.databaseSize,
          build: config.build,
          recovery: "RECOVERY_BLOCKED",
        };
      } catch {
        if (admitted)
          try {
            budget.finish(null, true);
          } catch {}
        return {
          status: "QUALIFICATION_DENIED",
          retry_permitted: false,
          recovery: "RECOVERY_BLOCKED",
        };
      }
    },
    status() {
      budget.admit(false);
      const d = budget.read();
      return {
        phase: d.phase,
        completed: d.index,
        case_count: CASES.length,
        invocations: d.used,
        recovery_required: d.recovery_required,
        results: d.results,
        build: config.build,
        provider_network_calls: 0,
        recovery: "RECOVERY_BLOCKED",
      };
    },
  });
}
