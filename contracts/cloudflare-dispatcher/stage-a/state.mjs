import { canonical, hash, insist } from "../protocol.mjs";
import { SqliteState, initialState } from "../sqlite-state.mjs";
export const CAPS = Object.freeze({
  invocations: 1000,
  window: 3600,
  bytes: 10000000,
  headroom: 100000,
  slots: 10,
  perMinute: 3,
  ttl: 300,
  body: 1048576,
});
export function room(storage) {
  const n = storage.sql.databaseSize;
  insist(
    Number.isSafeInteger(n) && n >= 0 && n + CAPS.headroom <= CAPS.bytes,
    "STORAGE_LIMIT",
  );
}
function atomic(storage, work) {
  room(storage);
  return storage.transactionSync(() => {
    const v = work();
    insist(!v?.then, "ASYNC_TRANSACTION_DENIED");
    insist(storage.sql.databaseSize <= CAPS.bytes, "STORAGE_LIMIT");
    return v;
  });
}
export class RunBudget {
  #storage;
  #config;
  #clock;
  constructor(storage, config, clock) {
    this.#storage = storage;
    this.#config = config;
    this.#clock = clock;
    atomic(storage, () => {
      storage.sql.exec(
        "CREATE TABLE IF NOT EXISTS df_stage_a_budget (id INTEGER PRIMARY KEY CHECK(id=1), document TEXT NOT NULL)",
      );
      if (
        storage.sql
          .exec("SELECT id FROM df_stage_a_budget WHERE id=1")
          .toArray().length === 0
      )
        storage.sql.exec(
          "INSERT INTO df_stage_a_budget VALUES(1,?)",
          canonical(
            {
              ...config,
              used: 0,
              admissions: [],
              phase: "READY",
              index: 0,
              results: [],
              intent_results: {},
              current_intent: null,
              case_started_at: null,
              last_time: clock(),
              recovery_required: false,
            },
            16384,
          ),
        );
    });
    const d = this.read();
    if (d.phase === "RUNNING") this.finish(null, true);
  }
  read() {
    const rows = this.#storage.sql
      .exec("SELECT document FROM df_stage_a_budget WHERE id=1")
      .toArray();
    insist(rows.length === 1, "BUDGET_MISSING");
    const d = JSON.parse(rows[0].document);
    insist(
      Number.isSafeInteger(d.used) &&
        d.used >= 0 &&
        d.used <= CAPS.invocations &&
        Number.isSafeInteger(d.index) &&
        d.index >= 0 &&
        d.index <= 24 &&
        Array.isArray(d.results) &&
        d.results.length === d.index &&
        Array.isArray(d.admissions) &&
        d.admissions.length <= 3 &&
        d.admissions.every((t) => Number.isSafeInteger(t) && t >= 0) &&
        Number.isSafeInteger(d.last_time) &&
        typeof d.recovery_required === "boolean" &&
        ["READY", "RUNNING", "RECOVERY_REQUIRED"].includes(d.phase),
      "BUDGET_CORRUPT",
    );
    for (const k of Object.keys(this.#config))
      insist(d[k] === this.#config[k], "DEPLOYMENT_BINDING");
    return d;
  }
  check() {
    room(this.#storage);
    const d = this.read(),
      now = this.#clock();
    insist(
      Number.isSafeInteger(now) &&
        now >= d.last_time &&
        now >= d.start &&
        now < d.end,
      "TEST_WINDOW",
    );
    insist(!d.recovery_required && d.used < CAPS.invocations, "BUDGET_FENCED");
    if (d.phase === "RUNNING")
      insist(
        Number.isSafeInteger(d.case_started_at) &&
          now - d.case_started_at < CAPS.ttl,
        "CASE_EXPIRED",
      );
    return d;
  }
  update(fn) {
    return atomic(this.#storage, () => {
      const d = this.read();
      fn(d);
      insist(
        new TextEncoder().encode(canonical(d, 16384)).length <= 16384,
        "REPORT_SIZE",
      );
      this.#storage.sql.exec(
        "UPDATE df_stage_a_budget SET document=? WHERE id=1",
        canonical(d, 16384),
      );
      return structuredClone(d);
    });
  }
  consume() {
    this.check();
    return this.update((d) => {
      const now = this.#clock();
      insist(
        now >= d.last_time &&
          now < d.end &&
          !d.recovery_required &&
          d.used < CAPS.invocations,
        "BUDGET_FENCED",
      );
      d.used++;
      d.last_time = now;
    });
  }
  admit(startCase = true, intent = null) {
    this.check();
    return this.update((d) => {
      const now = this.#clock();
      d.admissions = d.admissions.filter((t) => t > now - 60);
      insist(d.admissions.length < CAPS.perMinute, "RPC_QUOTA");
      if (startCase) {
        insist(
          typeof intent === "string" && /^[a-f0-9]{32}$/.test(intent),
          "INVOCATION_INTENT",
        );
        if (!d.intent_results[intent]) {
          insist(d.phase === "READY", "RUN_NOT_READY");
          d.phase = "RUNNING";
          d.current_intent = intent;
          d.case_started_at = now;
        }
      }
      d.admissions.push(now);
      d.used++;
      d.last_time = now;
    });
  }

  finish(result, failed = false) {
    return this.update((d) => {
      d.phase = failed ? "RECOVERY_REQUIRED" : "READY";
      d.recovery_required = failed;
      d.case_started_at = null;
      if (result) {
        d.results.push(result);
        d.index++;
        d.intent_results[d.current_intent] = { result, index: d.index };
        d.current_intent = null;
      }
    });
  }
}
/** Only private fixed-script code can arm these synthetic faults; never exported by an RPC entrypoint. */
class ScopeStorage {
  #storage;
  #prefix;
  #fault = null;
  #mutated = false;
  constructor(storage, prefix) {
    insist(/^[a-z_]{1,35}$/.test(prefix), "SCOPE");
    this.#storage = storage;
    this.#prefix = prefix;
    const self = this;
    this.sql = {
      get databaseSize() {
        return storage.sql.databaseSize;
      },
      exec(q, ...args) {
        if (self.#fault === "READ" && q.startsWith("SELECT")) {
          self.#fault = null;
          throw Error("SYNTHETIC_READ_FAILURE");
        }
        if (self.#fault === "STATE" && q.startsWith("UPDATE df_state")) {
          self.#fault = null;
          throw Error("SYNTHETIC_STATE_FAILURE");
        }
        if (
          self.#fault === "JOURNAL" &&
          q.startsWith("INSERT INTO df_events")
        ) {
          self.#fault = null;
          throw Error("SYNTHETIC_JOURNAL_FAILURE");
        }
        if (/^(UPDATE|INSERT|DELETE)/.test(q)) self.#mutated = true;
        return storage.sql.exec(
          q
            .replaceAll("df_state", prefix + "_state")
            .replaceAll("df_events", prefix + "_events"),
          ...args,
        );
      },
    };
  }
  arm(fault) {
    insist(["STATE", "JOURNAL", "ACK", "READ"].includes(fault), "FAULT");
    this.#fault = fault;
  }
  transactionSync(work) {
    this.#mutated = false;
    const out = atomic(this.#storage, work);
    if (this.#fault === "ACK" && this.#mutated) {
      this.#fault = null;
      throw Error("SYNTHETIC_LOST_ACK");
    }
    return out;
  }
  snapshot() {
    return {
      state: this.sql.exec("SELECT * FROM df_state").toArray(),
      events: this.sql
        .exec("SELECT * FROM df_events ORDER BY revision")
        .toArray(),
    };
  }
  restore(snapshot) {
    this.transactionSync(() => {
      this.sql.exec("DELETE FROM df_events");
      this.sql.exec("DELETE FROM df_state");
      for (const r of snapshot.state)
        this.sql.exec(
          "INSERT INTO df_state VALUES(?,?,?)",
          r.id,
          r.revision,
          r.document,
        );
      for (const r of snapshot.events)
        this.sql.exec(
          "INSERT INTO df_events VALUES(?,?)",
          r.revision,
          r.envelope,
        );
    });
  }
}
export class SyntheticSqlRuntime {
  #state;
  #config;
  #clock;
  #budget;
  #armed = false;
  constructor(storage, config, clock, budget, scope) {
    this.port = new ScopeStorage(storage, scope);
    this.#state = new SqliteState(this.port);
    this.#config = config;
    this.#clock = clock;
    this.#budget = budget;
    if (
      this.port.sql.exec("SELECT revision FROM df_state").toArray().length === 0
    )
      this.#state.bootstrap({ ...initialState(config), synthetic_calls: 0 });
  }
  read() {
    const s = this.#state.read();
    for (const k of ["namespace", "code", "epoch"])
      insist(s.doc[k] === this.#config[k], "DEPLOYMENT_BINDING");
    return s;
  }
  async change(work, event = "FIXTURE") {
    this.#budget.consume();
    const before = this.read(),
      doc = structuredClone(before.doc);
    work(doc);
    doc.revision++;
    canonical(doc, 16384);
    const envelope = {
      synthetic: true,
      event,
      previous: await hash(canonical(before.doc)),
      next: await hash(canonical(doc)),
    };
    this.#budget.check();
    this.#state.cas(before.doc.revision, doc, envelope);
    return doc;
  }
  active(d) {
    insist(
      this.#armed && d.booted && !d.stopped && !d.recovery_required,
      "AUTHORITY_INACTIVE",
    );
  }
  async boot() {
    const was = this.read().doc.booted;
    await this.change((d) => {
      if (d.booted) {
        d.recovery_required = true;
        d.stopped = true;
        d.generation++;
      }
      d.booted = true;
    }, "BOOT");
    this.#armed = !was;
    return !was;
  }
  async session(id = "fixture", slots = 10, ttl = 300) {
    return this.change((d) => {
      this.active(d);
      insist(
        Number.isSafeInteger(slots) &&
          slots >= 1 &&
          slots <= CAPS.slots &&
          Number.isSafeInteger(ttl) &&
          ttl > 0 &&
          ttl <= CAPS.ttl,
        "SESSION_LIMIT",
      );
      insist(
        !d.sessions[id] && Object.keys(d.sessions).length < 10,
        "SESSION_EXISTS",
      );
      d.sessions[id] = {
        slots,
        generation: d.generation,
        expires_at: this.#clock() + ttl,
      };
    }, "SESSION");
  }
  async reserve(session, slot, nonce, generation = this.read().doc.generation) {
    return this.change((d) => {
      const s = d.sessions[session];
      insist(
        s && s.generation === generation && generation === d.generation,
        "GENERATION",
      );
      this.active(d);
      insist(s.expires_at > this.#clock(), "EXPIRED");
      insist(
        Number.isSafeInteger(slot) &&
          slot >= 0 &&
          slot < s.slots &&
          /^[a-z0-9_-]{1,40}$/.test(nonce),
        "SLOT",
      );
      const id = session + ":" + slot;
      insist(!d.claims[id] && !d.nonces[nonce], "REPLAY");
      d.rates = d.rates.filter((t) => t > this.#clock() - 60);
      insist(d.rates.length < 3, "QUOTA");
      d.rates.push(this.#clock());
      d.nonces[nonce] = true;
      d.claims[id] = { state: "RESERVED", generation };
    }, "RESERVE");
  }
  async begin(id) {
    return this.change((d) => {
      this.active(d);
      insist(
        d.claims[id]?.state === "RESERVED" &&
          d.claims[id].generation === d.generation,
        "CLAIM",
      );
      d.claims[id].state = "ADMITTED";
      d.synthetic_calls++;
    }, "ADMITTED");
  }
  async complete(id) {
    return this.change((d) => {
      insist(d.claims[id]?.state === "ADMITTED", "CLAIM");
      d.claims[id].state = "TERMINAL";
    }, "SYNTHETIC_KNOWN_COMPLETION");
  }
  async emergency() {
    const d = await this.change((d) => {
      d.stopped = true;
      d.generation++;
    }, "EMERGENCY");
    return Object.values(d.claims).some((c) => c.state === "ADMITTED")
      ? "DRAIN_PENDING"
      : "DRAIN_ACKNOWLEDGED";
  }
}
export function fixtureBody(size) {
  insist(
    Number.isSafeInteger(size) && size >= 0 && size <= CAPS.body,
    "BODY_LIMIT",
  );
  return new Uint8Array(size);
}
