import {
  canonical,
  hash,
  insist,
  sign,
  verify,
  freshness,
} from "../protocol.mjs";
import { SyntheticSqlRuntime, fixtureBody } from "./state.mjs";
export const CASES = Object.freeze([
  "atomic_commit",
  "state_rollback",
  "journal_rollback",
  "lost_ack",
  "duplicate_slot",
  "concurrent_slot",
  "nonce_rehydration",
  "emergency_generation",
  "pending_drain",
  "restart_fence",
  "storage_failure",
  "namespace_replacement",
  "stale_generation",
  "synthetic_timeout",
  "interrupted_dispatch",
  "stale_workload",
  "expired_owner",
  "quota_exhaustion",
  "epoch_mismatch",
  "code_rollback",
  "subordinate_rollback",
  "all_root_counterexample",
  "body_limit",
  "no_egress",
]);
const blocked = async (fn) => {
  let failed = false;
  try {
    await fn();
  } catch {
    failed = true;
  }
  insist(failed, "EXPECTED_DENIAL_MISSING");
};
export async function runCase(id, { storage, config, budget, globals }) {
  insist(CASES.includes(id), "CASE");
  let time = 1000;
  const c = {
      namespace: config.object_id,
      code: config.build,
      epoch: config.build,
    },
    make = (override = {}) =>
      new SyntheticSqlRuntime(
        storage,
        { ...c, ...override },
        () => time,
        budget,
        id,
      );
  const r = make();
  const initial = r.port.snapshot();
  await r.boot();
  if (id === "body_limit") {
    insist(fixtureBody(1048576).byteLength === 1048576, "BODY");
    await blocked(() => fixtureBody(1048577));
  } else if (id === "no_egress") {
    await blocked(() => globals.fetch("https://stage-a-forbidden.invalid"));
  } else {
    await r.session("fixture", 10);
    if (["state_rollback", "journal_rollback"].includes(id)) {
      const before = await hash(canonical(r.read().doc));
      r.port.arm(id === "state_rollback" ? "STATE" : "JOURNAL");
      await blocked(() => r.reserve("fixture", 0, "one"));
      insist(
        before === (await hash(canonical(r.read().doc))),
        "PARTIAL_COMMIT",
      );
    } else if (id === "lost_ack") {
      r.port.arm("ACK");
      await blocked(() => r.reserve("fixture", 0, "one"));
      insist(r.read().doc.nonces.one, "ACK_NOT_COMMITTED");
      await blocked(() => r.reserve("fixture", 0, "two"));
    } else if (id === "concurrent_slot") {
      const results = await Promise.allSettled([
        r.reserve("fixture", 0, "one"),
        r.reserve("fixture", 0, "two"),
      ]);
      insist(
        results.filter((x) => x.status === "fulfilled").length === 1,
        "DOUBLE_RESERVATION",
      );
    } else if (
      ["namespace_replacement", "epoch_mismatch", "code_rollback"].includes(id)
    ) {
      await blocked(() =>
        make({
          [id === "namespace_replacement"
            ? "namespace"
            : id === "epoch_mismatch"
              ? "epoch"
              : "code"]: "f".repeat(64),
        }).read(),
      );
    } else if (id === "stale_workload") {
      const pair = await crypto.subtle.generateKey("Ed25519", false, [
        "sign",
        "verify",
      ]);
      const certificate = await sign(pair.privateKey, "stage-a-workload", {
        protocol: "synthetic-only",
        code: c.code,
        issued_at: time - 61,
        expires_at: time - 1,
      });
      const checked = await verify(
        certificate,
        "stage-a-workload",
        pair.publicKey,
      );
      await blocked(() => freshness(checked, time, 60));
    } else if (id === "expired_owner") {
      time += 301;
      await blocked(() => r.reserve("fixture", 0, "one"));
    } else if (id === "quota_exhaustion") {
      for (let n = 0; n < 3; n++) await r.reserve("fixture", n, "n" + n);
      await blocked(() => r.reserve("fixture", 3, "four"));
    } else if (id === "emergency_generation") {
      const before = r.read().doc.generation;
      await r.emergency();
      await r.emergency();
      insist(r.read().doc.generation === before + 2, "GENERATION");
    } else if (id === "storage_failure") {
      r.port.arm("READ");
      await blocked(() => r.reserve("fixture", 0, "one"));
    } else if (id === "stale_generation") {
      const g = r.read().doc.generation;
      await r.emergency();
      await blocked(() => r.reserve("fixture", 0, "one", g));
    } else if (id === "all_root_counterexample") {
      await r.reserve("fixture", 0, "one");
      await r.begin("fixture:0");
      await r.complete("fixture:0");
      await r.emergency();
      r.port.restore(initial);
      const restored = make();
      await restored.boot();
      await restored.session("fixture");
      await restored.reserve("fixture", 0, "one");
      await restored.begin("fixture:0");
      insist(
        restored.read().doc.synthetic_calls === 1,
        "COUNTEREXAMPLE_NOT_REPRODUCED",
      );
      return {
        case_id: id,
        result: "COUNTEREXAMPLE_REPRODUCED",
        recovery: "RECOVERY_BLOCKED",
        provider_network_calls: 0,
      };
    } else {
      const snapshot = r.port.snapshot();
      await r.reserve("fixture", 0, "one");
      if (id === "duplicate_slot")
        await blocked(() => r.reserve("fixture", 0, "two"));
      if (["nonce_rehydration", "restart_fence"].includes(id)) {
        const rehydrated = make();
        insist(rehydrated.read().doc.nonces.one, "NONCE_LOST");
        insist((await rehydrated.boot()) === false, "AUTOMATIC_REARM");
        await blocked(() => rehydrated.reserve("fixture", 1, "one"));
      }
      if (
        ["pending_drain", "synthetic_timeout", "interrupted_dispatch"].includes(
          id,
        )
      ) {
        await r.begin("fixture:0");
        insist((await r.emergency()) === "DRAIN_PENDING", "FALSE_DRAIN_ACK");
        insist(
          r.read().doc.claims["fixture:0"].state === "ADMITTED",
          "LEASE_CLEARED",
        );
      }
      if (id === "subordinate_rollback") {
        const anchor = await hash(canonical(r.read().doc));
        r.port.restore(snapshot);
        insist(
          anchor !== (await hash(canonical(r.read().doc))),
          "ROLLBACK_UNDETECTED",
        );
      }
      if (id === "atomic_commit")
        insist(
          r.read().doc.nonces.one &&
            r.read().events.length === r.read().doc.revision,
          "COMMIT",
        );
    }
  }
  return {
    case_id: id,
    result: "VERIFIED_SYNTHETIC",
    provider_network_calls: 0,
  };
}
