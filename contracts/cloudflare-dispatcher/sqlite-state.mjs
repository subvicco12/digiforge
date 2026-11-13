import { insist, canonical } from "./protocol.mjs";
/** Cloudflare ctx.storage.sql + transactionSync. No asynchronous/provider work here. */
export class SqliteState {
  constructor(storage) {
    this.storage = storage;
    insist(
      storage?.sql && typeof storage.transactionSync === "function",
      "SQLITE_REQUIRED",
    );
    this.atomic(() => {
      storage.sql.exec(
        "CREATE TABLE IF NOT EXISTS df_state (id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL, document TEXT NOT NULL)",
      );
      storage.sql.exec(
        "CREATE TABLE IF NOT EXISTS df_events (revision INTEGER PRIMARY KEY, envelope TEXT NOT NULL)",
      );
    });
  }
  atomic(work) {
    insist(
      typeof work === "function" && work.constructor.name !== "AsyncFunction",
      "ASYNC_TRANSACTION_DENIED",
    );
    return this.storage.transactionSync(() => {
      const r = work();
      insist(!r || typeof r.then !== "function", "ASYNC_TRANSACTION_DENIED");
      return r;
    });
  }
  bootstrap(doc) {
    this.atomic(() => {
      insist(
        this.storage.sql
          .exec("SELECT revision FROM df_state WHERE id=1")
          .toArray().length === 0,
        "ALREADY_INITIALIZED",
      );
      this.storage.sql.exec(
        "INSERT INTO df_state(id,revision,document) VALUES(1,?,?)",
        doc.revision,
        canonical(doc, 1048576),
      );
    });
  }
  read() {
    return this.atomic(() => {
      const row = this.storage.sql
        .exec("SELECT revision,document FROM df_state WHERE id=1")
        .toArray();
      insist(row.length === 1, "STATE_MISSING");
      const doc = JSON.parse(row[0].document);
      insist(
        doc.revision === row[0].revision &&
          Number.isSafeInteger(doc.revision) &&
          doc.revision >= 0,
        "STATE_CORRUPT",
      );
      const events = this.storage.sql
        .exec(
          "SELECT revision,envelope FROM df_events ORDER BY revision LIMIT 2049",
        )
        .toArray();
      insist(
        events.length <= 2048 && events.length === doc.revision,
        "AUDIT_CORRUPT",
      );
      return { doc, events: events.map((x) => JSON.parse(x.envelope)) };
    });
  }
  cas(expected, doc, envelope) {
    this.atomic(() => {
      insist(
        Number.isSafeInteger(expected) && doc.revision === expected + 1,
        "STATE_CORRUPT",
      );
      const changed = this.storage.sql
        .exec(
          "UPDATE df_state SET revision=?,document=? WHERE id=1 AND revision=? RETURNING revision",
          doc.revision,
          canonical(doc, 1048576),
          expected,
        )
        .toArray();
      insist(changed.length === 1, "CONCURRENT_CHANGE");
      this.storage.sql.exec(
        "INSERT INTO df_events(revision,envelope) VALUES(?,?)",
        doc.revision,
        canonical(envelope),
      );
    });
  }
}
export function initialState(c) {
  return {
    version: 1,
    revision: 0,
    epoch: c.epoch,
    namespace: c.namespace,
    code: c.code,
    generation: 1,
    booted: false,
    recovery_required: false,
    stopped: false,
    sessions: {},
    claims: {},
    nonces: {},
    rates: [],
    pause_until: 0,
  };
}
