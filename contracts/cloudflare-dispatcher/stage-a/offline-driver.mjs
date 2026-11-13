// Offline CLI ONLY; not included in the deployable module closure.
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { LocalSqliteStorage } from "../test/support.mjs";
import { stageFixture } from "../test/stage-a-support.mjs";
import { runStageAStep } from "./driver.mjs";
import { CASES } from "./suite.mjs";
if (process.argv.length !== 2) throw Error("NO_ARGUMENTS_ACCEPTED");
const dir = mkdtempSync(join(tmpdir(), "df-stage-a-driver-"));
let f;
try {
  f = stageFixture({
    storage: new LocalSqliteStorage(join(dir, "synthetic.sqlite")),
  });
  const results = [];
  for (const id of CASES) {
    const r = await runStageAStep({
      qualify: (input) => f.runner.step(input.intent),
    });
    if (r.case_id !== id) throw Error("CASE_SEQUENCE");
    results.push(r);
    f.clock.now += 21;
  }
  console.log(
    JSON.stringify({
      status: "OFFLINE_VERIFIED",
      hosted: "HOSTED_NOT_EXECUTED",
      clock: "SYNTHETIC_CLOCK",
      cases: results.length,
      invocations: results.at(-1).invocations,
      storage_bytes: results.at(-1).storage_bytes,
      provider_network_calls: 0,
      recovery: "RECOVERY_BLOCKED",
      results,
    }),
  );
} finally {
  f?.storage.db.close();
  rmSync(dir, { recursive: true, force: true });
}
