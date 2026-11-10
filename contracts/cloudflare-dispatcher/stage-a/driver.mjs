import { keys, insist, hex } from "../protocol.mjs";
import { CASES } from "./suite.mjs";
/** One approved private invocation intent only. No loop, sleep, retry, alarm, URL, reset or fault argument. */
export async function runStageAStep(service) {
  insist(
    arguments.length === 1 && service && typeof service.qualify === "function",
    "PRIVATE_SERVICE_REQUIRED",
  );
  let timer;
  let r;
  const deadline = new Promise((_, reject) => {
    timer = setTimeout(
      () => reject(Error("STAGE_A_INVOCATION_UNKNOWN")),
      20000,
    );
  });
  try {
    r = await Promise.race([
      service.qualify({ intent: crypto.randomUUID().replaceAll("-", "") }),
      deadline,
    ]);
  } catch {
    throw Error("STAGE_A_INVOCATION_UNKNOWN");
  } finally {
    clearTimeout(timer);
  }
  keys(r, [
    "case_id",
    "result",
    "provider_network_calls",
    "case_index",
    "case_count",
    "complete",
    "invocations",
    "storage_bytes",
    "build",
    "recovery",
  ]);
  insist(
    CASES.includes(r.case_id) &&
      r.case_count === CASES.length &&
      r.case_index === CASES.indexOf(r.case_id) + 1 &&
      r.provider_network_calls === 0 &&
      r.recovery === "RECOVERY_BLOCKED" &&
      hex(r.build) &&
      Number.isSafeInteger(r.invocations) &&
      r.invocations >= 1 &&
      r.invocations <= 1000 &&
      Number.isSafeInteger(r.storage_bytes) &&
      r.storage_bytes >= 0 &&
      r.storage_bytes <= 10000000 &&
      r.complete === (r.case_index === CASES.length),
    "RECEIPT",
  );
  insist(
    r.result ===
      (r.case_id === "all_root_counterexample"
        ? "COUNTEREXAMPLE_REPRODUCED"
        : "VERIFIED_SYNTHETIC"),
    "COUNTEREXAMPLE_CLASSIFICATION",
  );
  return r;
}
