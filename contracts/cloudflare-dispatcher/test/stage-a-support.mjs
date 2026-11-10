import { LocalSqliteStorage } from "./support.mjs";
import { createStageA, OBJECT_NAME, MODE } from "../stage-a/factory.mjs";
export function stageFixture({
  storage = new LocalSqliteStorage(),
  clock = { now: 1000 },
  globals = {},
  envOverrides = {},
} = {}) {
  Object.defineProperty(storage.sql, "databaseSize", {
    configurable: true,
    get: () =>
      Number(storage.db.prepare("PRAGMA page_count").get().page_count) *
      Number(storage.db.prepare("PRAGMA page_size").get().page_size),
  });
  const object = "a".repeat(64),
    env = {
      DF_QUAL_STATE: {
        idFromName: (n) => {
          if (n !== OBJECT_NAME) throw Error("wrong object");
          return object;
        },
        get: () => {},
      },
      DF_QUAL_MODE: MODE,
      DF_QUAL_WINDOW_START: "990",
      DF_QUAL_WINDOW_END: "4590",
      DF_QUAL_BUILD_ID: "b".repeat(64),
      ...envOverrides,
    };
  const ctx = { id: object, storage };
  return {
    ctx,
    env,
    storage,
    clock,
    globals,
    runner: createStageA(ctx, env, { clock: () => clock.now, globals }),
  };
}
