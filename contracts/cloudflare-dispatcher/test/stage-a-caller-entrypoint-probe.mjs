import assert from "node:assert/strict";
import { stageFixture } from "./stage-a-support.mjs";
import { LocalSqliteStorage } from "./support.mjs";
import worker, {
  DigiForgeStageAManual,
  DigiForgeStageACallerState,
} from "../stage-a-caller/entrypoint.mjs";
import { APPROVED_BUILD, CALLER_MODE } from "../stage-a-caller/contract.mjs";
const now = Math.floor(Date.now() / 1000),
  target = stageFixture({
    clock: { now },
    envOverrides: {
      DF_QUAL_WINDOW_START: String(now - 1),
      DF_QUAL_WINDOW_END: String(now + 3599),
      DF_QUAL_BUILD_ID: APPROVED_BUILD,
    },
  });
const storage = new LocalSqliteStorage();
Object.defineProperty(storage.sql, "databaseSize", {
  get: () =>
    Number(storage.db.prepare("PRAGMA page_count").get().page_count) *
    Number(storage.db.prepare("PRAGMA page_size").get().page_size),
});
let object,
  targetCalls = 0,
  lookups = 0;
const env = {
  DF_STAGE_A: {
    status: () => target.runner.status(),
    qualify: (r) => {
      targetCalls++;
      return target.runner.step(r.intent);
    },
  },
  DF_CALLER_STATE: {
    idFromName: () => "c".repeat(64),
    get: () => {
      lookups++;
      return object;
    },
  },
  DF_MANUAL_WORKFLOW: {
    create: () => {
      throw Error("NO_SELF_INITIATION");
    },
    get: () => {},
  },
  DF_CALLER_MODE: CALLER_MODE,
  DF_CALLER_WINDOW_START: String(now - 1),
  DF_CALLER_WINDOW_END: String(now + 3599),
  DF_CALLER_BUILD: APPROVED_BUILD,
  DF_CALLER_APPROVAL: "d".repeat(64),
};
for (const method of ["GET", "POST", "PUT", "DELETE"])
  assert.equal(
    (
      await worker.fetch(
        new Request("https://arbitrary.invalid/start", { method }),
      )
    ).status,
    503,
  );
assert.equal(lookups, 0);
const ctx = {
  id: "c".repeat(64),
  storage,
  blockConcurrencyWhile: (fn) => fn(),
};
object = new DigiForgeStageACallerState(ctx, env);
const flow = new DigiForgeStageAManual({}, env);
const request = {
  action: "ADVANCE_ONE",
  request_id: "1".repeat(32),
  operation_id: "1".repeat(32),
  build: APPROVED_BUILD,
  approval: env.DF_CALLER_APPROVAL,
  issued_at: now,
  expires_at: now + 120,
};
let steps = 0;
const step = {
  do: async (name, policy, fn) => {
    steps++;
    assert.equal(policy.retries.limit, 0);
    assert.equal(policy.timeout, "50 seconds");
    return fn();
  },
};
assert.equal(
  (
    await flow.run(
      {
        payload: request,
        instanceId: "stage-a-" + request.request_id,
        schedule: {},
      },
      step,
    )
  ).status,
  "DENIED",
);
assert.equal(steps, 0);
assert.equal(
  (
    await flow.run(
      {
        payload: { ...request, url: "https://example.invalid" },
        instanceId: "stage-a-" + request.request_id,
      },
      step,
    )
  ).status,
  "DENIED",
);
assert.equal(steps, 0);
const r = await flow.run(
  { payload: request, instanceId: "stage-a-" + request.request_id },
  step,
);
assert.equal(r.status, "COMPLETED");
assert.equal(targetCalls, 1);
const replay = await flow.run(
  { payload: request, instanceId: "stage-a-" + request.request_id },
  step,
);
assert.equal(replay.status, "COMPLETED");
assert.equal(targetCalls, 1);
assert.deepEqual(
  Object.getOwnPropertyNames(DigiForgeStageACallerState.prototype).sort(),
  ["constructor", "fetch", "invoke"],
);
assert.equal(typeof worker.initiate, "undefined");
assert.equal(typeof worker.reset, "undefined");
assert.throws(() => fetch("https://example.invalid"), /EGRESS_DENIED/);
console.log("MANUAL_OPERATOR_AND_PRIVATE_BINDING_VERIFIED");
