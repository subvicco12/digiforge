import { keys, insist, hex, freshness } from "../protocol.mjs";
export const APPROVED_BUILD =
  "b0d29d9f7b362c420b5c56143315ebd1e0cde92d7f00fe4bdff3f46a9836ca1e";
export const CALLER_MODE = "STAGE_A_MANUAL_CALLER_V1";
export const CALLER_OBJECT = "digiforge-stage-a-caller-singleton-v1";
export const WORKFLOW_NAME = "digiforge-stage-a-manual";
export function operatorRequest(r, config, now, instance) {
  keys(r, [
    "action",
    "request_id",
    "operation_id",
    "build",
    "approval",
    "issued_at",
    "expires_at",
  ]);
  insist(
    ["ADVANCE_ONE", "READ_RECEIPT", "RECONCILE"].includes(r.action) &&
      hex(r.request_id, 32) &&
      hex(r.operation_id, 32),
    "OPERATOR_REQUEST",
  );
  insist(
    instance === "stage-a-" + r.request_id &&
      r.build === APPROVED_BUILD &&
      r.build === config.build &&
      r.approval === config.approval,
    "OPERATOR_BINDING",
  );
  if (r.action === "ADVANCE_ONE")
    insist(r.request_id === r.operation_id, "OPERATION_ID");
  freshness(r, now, 120);
  return r;
}
export function callerConfiguration(env, ctx = null) {
  keys(env, [
    "DF_STAGE_A",
    "DF_CALLER_STATE",
    "DF_MANUAL_WORKFLOW",
    "DF_CALLER_MODE",
    "DF_CALLER_WINDOW_START",
    "DF_CALLER_WINDOW_END",
    "DF_CALLER_BUILD",
    "DF_CALLER_APPROVAL",
  ]);
  insist(env.DF_CALLER_MODE === CALLER_MODE, "CALLER_DISABLED");
  for (const k of ["DF_CALLER_WINDOW_START", "DF_CALLER_WINDOW_END"])
    insist(
      typeof env[k] === "string" && /^[0-9]{1,12}$/.test(env[k]),
      "CALLER_WINDOW",
    );
  const start = Number(env.DF_CALLER_WINDOW_START),
    end = Number(env.DF_CALLER_WINDOW_END);
  insist(
    end > start &&
      end - start <= 3600 &&
      env.DF_CALLER_BUILD === APPROVED_BUILD &&
      hex(env.DF_CALLER_APPROVAL) &&
      env.DF_CALLER_APPROVAL !== "0".repeat(64),
    "CALLER_BINDING",
  );
  insist(
    typeof env.DF_STAGE_A?.qualify === "function" &&
      typeof env.DF_STAGE_A?.status === "function" &&
      typeof env.DF_CALLER_STATE?.idFromName === "function" &&
      typeof env.DF_CALLER_STATE?.get === "function",
    "PRIVATE_BINDING",
  );
  insist(
    typeof env.DF_MANUAL_WORKFLOW?.create === "function" &&
      typeof env.DF_MANUAL_WORKFLOW?.get === "function",
    "WORKFLOW_BINDING",
  );
  const object = env.DF_CALLER_STATE.idFromName(CALLER_OBJECT).toString();
  insist(
    hex(object) && (!ctx || ctx.id?.toString() === object),
    "CALLER_NAMESPACE",
  );
  return {
    start,
    end,
    object,
    build: env.DF_CALLER_BUILD,
    approval: env.DF_CALLER_APPROVAL,
  };
}
