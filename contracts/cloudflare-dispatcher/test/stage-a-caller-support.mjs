import { LocalSqliteStorage } from "./support.mjs";
import { stageFixture } from "./stage-a-support.mjs";
import { CallerJournal } from "../stage-a-caller/journal.mjs";
import { PrivateCaller } from "../stage-a-caller/caller.mjs";
import { APPROVED_BUILD } from "../stage-a-caller/contract.mjs";
export async function callerFixture({
  storage = new LocalSqliteStorage(),
} = {}) {
  const target = stageFixture({
      envOverrides: { DF_QUAL_BUILD_ID: APPROVED_BUILD },
    }),
    clock = target.clock;
  Object.defineProperty(storage.sql, "databaseSize", {
    configurable: true,
    get: () =>
      Number(storage.db.prepare("PRAGMA page_count").get().page_count) *
      Number(storage.db.prepare("PRAGMA page_size").get().page_size),
  });
  const config = {
      start: 990,
      end: 4590,
      object: "c".repeat(64),
      build: APPROVED_BUILD,
      approval: "d".repeat(64),
    },
    journal = new CallerJournal(storage, config, () => clock.now),
    calls = { status: 0, qualify: 0 };
  const service = {
    status: () => {
      calls.status++;
      return target.runner.status();
    },
    qualify: (input) => {
      calls.qualify++;
      return target.runner.step(input.intent);
    },
  };
  const caller = new PrivateCaller({
      journal,
      service,
      config,
      clock: () => clock.now,
    }),
    id = "1".repeat(32);
  return {
    caller,
    journal,
    target,
    service,
    calls,
    config,
    clock,
    storage,
    id,
    request: (
      action = "ADVANCE_ONE",
      operation = id,
      requestId = action === "ADVANCE_ONE"
        ? operation
        : crypto.randomUUID().replaceAll("-", ""),
    ) => ({
      action,
      request_id: requestId,
      operation_id: operation,
      build: APPROVED_BUILD,
      approval: config.approval,
      issued_at: clock.now,
      expires_at: clock.now + 120,
    }),
  };
}
