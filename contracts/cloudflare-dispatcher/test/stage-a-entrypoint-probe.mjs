import assert from "node:assert/strict";
import { stageFixture } from "./stage-a-support.mjs";
import Worker, { DigiForgeQualificationState } from "../stage-a/entrypoint.mjs";
const now = Math.floor(Date.now() / 1000),
  f = stageFixture({
    clock: { now },
    envOverrides: {
      DF_QUAL_WINDOW_START: String(now - 1),
      DF_QUAL_WINDOW_END: String(now + 3599),
    },
  });
let calls = 0;
f.ctx.blockConcurrencyWhile = (fn) => fn();
const object = new DigiForgeQualificationState(f.ctx, f.env);
f.env.DF_QUAL_STATE.get = () => {
  calls++;
  return object;
};
const worker = new Worker({}, f.env);
for (const method of ["GET", "POST", "PUT", "PATCH", "DELETE"])
  assert.equal(
    (
      await worker.fetch(
        new Request("https://arbitrary.invalid/reset", { method }),
      )
    ).status,
    503,
  );
assert.equal(calls, 0);
assert.equal(
  (await worker.qualify({ fault: "reset", url: "https://example.invalid" }))
    .status,
  "QUALIFICATION_UNAVAILABLE",
);
assert.equal(calls, 0);
const r = await worker.qualify({ intent: "1".repeat(32) });
assert.equal(r.case_id, "atomic_commit");
assert.equal(r.provider_network_calls, 0);
assert.equal(
  (await object.fetch(new Request("https://arbitrary.invalid/fault"))).status,
  503,
);
assert.deepEqual(
  Object.getOwnPropertyNames(DigiForgeQualificationState.prototype).sort(),
  ["constructor", "fetch", "qualify", "status"],
);
assert.throws(() => fetch("https://example.invalid"), /EGRESS_DENIED/);
assert.equal(typeof worker.reset, "undefined");
assert.equal(typeof object.injectFault, "undefined");
console.log("PRIVATE_RPC_AND_HTTP_DENIAL_VERIFIED");
