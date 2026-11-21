import { canonical, insist, hex } from "../protocol.mjs";
const LIMIT = 1000000;
/** Same-account reference SQL journal; not an independently unrewindable authority. */
export class CallerJournal {
  #storage;
  #config;
  #clock;
  constructor(storage, config, clock) {
    this.#storage = storage;
    this.#config = config;
    this.#clock = clock;
    this.atomic(() => {
      storage.sql.exec(
        "CREATE TABLE IF NOT EXISTS df_stage_a_caller (id INTEGER PRIMARY KEY CHECK(id=1),revision INTEGER NOT NULL,document TEXT NOT NULL)",
      );
      if (
        storage.sql
          .exec("SELECT id FROM df_stage_a_caller WHERE id=1")
          .toArray().length === 0
      )
        storage.sql.exec(
          "INSERT INTO df_stage_a_caller VALUES(1,0,?)",
          canonical(
            {
              ...config,
              revision: 0,
              used: 0,
              admissions: [],
              next_index: 1,
              last_advance_at: null,
              operations: {},
              requests: {},
              last_time: clock(),
            },
            65536,
          ),
        );
    });
    this.change((d) => {
      for (const op of Object.values(d.operations))
        if (["PREPARED", "DISPATCHING"].includes(op.state)) {
          op.state = "UNKNOWN";
          op.reason = "CALLER_RESTART";
        }
    });
  }
  atomic(work) {
    insist(
      Number.isSafeInteger(this.#storage.sql.databaseSize) &&
        this.#storage.sql.databaseSize + 100000 <= LIMIT,
      "CALLER_STORAGE",
    );
    return this.#storage.transactionSync(() => {
      const r = work();
      insist(
        !r?.then && this.#storage.sql.databaseSize <= LIMIT,
        "CALLER_STORAGE",
      );
      return r;
    });
  }
  read() {
    const rows = this.#storage.sql
      .exec("SELECT revision,document FROM df_stage_a_caller WHERE id=1")
      .toArray();
    insist(rows.length === 1, "CALLER_STATE");
    const d = JSON.parse(rows[0].document);
    for (const k of Object.keys(this.#config))
      insist(d[k] === this.#config[k], "CALLER_BINDING");
    insist(
      d.revision === rows[0].revision &&
        Number.isSafeInteger(d.revision) &&
        d.revision >= 0 &&
        Number.isSafeInteger(d.used) &&
        d.used >= 0 &&
        d.used <= 1000 &&
        Number.isSafeInteger(d.next_index) &&
        d.next_index >= 1 &&
        d.next_index <= 25 &&
        Array.isArray(d.admissions) &&
        d.admissions.length <= 3 &&
        Number.isSafeInteger(d.last_time) &&
        d.operations &&
        d.requests,
      "CALLER_STATE",
    );
    return d;
  }
  change(work) {
    return this.atomic(() => {
      const d = this.read(),
        old = d.revision;
      const now = this.#clock();
      insist(Number.isSafeInteger(now) && now >= d.last_time, "CALLER_CLOCK");
      d.last_time = now;
      work(d);
      d.revision++;
      const changed = this.#storage.sql
        .exec(
          "UPDATE df_stage_a_caller SET revision=?,document=? WHERE id=1 AND revision=? RETURNING revision",
          d.revision,
          canonical(d, 65536),
          old,
        )
        .toArray();
      insist(changed.length === 1, "CALLER_CONFLICT");
      return structuredClone(d);
    });
  }
  admit(r, requestHash) {
    let created = false;
    const state = this.change((d) => {
      const now = this.#clock();
      insist(
        d.used < 1000 && Object.keys(d.requests).length < 128,
        "CALLER_BUDGET",
      );
      d.admissions = d.admissions.filter((t) => t > now - 60);
      insist(d.admissions.length < 3, "CALLER_QUOTA");
      d.admissions.push(now);
      d.used++;
      const old = d.requests[r.request_id];
      insist(!old || old === requestHash, "OPERATOR_REPLAY");
      d.requests[r.request_id] = requestHash;
      if (r.action === "ADVANCE_ONE") {
        insist(now >= d.start && now < d.end, "CALLER_WINDOW");
        const existing = d.operations[r.operation_id];
        if (existing) {
          insist(existing.request_hash === requestHash, "OPERATOR_REPLAY");
          return;
        }
        insist(
          d.last_advance_at === null || now - d.last_advance_at >= 60,
          "CALLER_PACING",
        );
        insist(
          Object.values(d.operations).every((x) => x.state === "COMPLETED") &&
            Object.keys(d.operations).length < 24 &&
            d.next_index <= 24,
          "UNKNOWN_BLOCKS_ADVANCE",
        );
        created = true;
        d.operations[r.operation_id] = {
          intent: crypto.randomUUID().replaceAll("-", ""),
          request_hash: requestHash,
          state: "PREPARED",
          expected_index: d.next_index,
          attempts: 0,
          receipt: null,
          reason: null,
        };
      }
    });
    return { state, created };
  }
  latch(id) {
    return this.change((d) => {
      const op = d.operations[id];
      insist(
        op?.state === "PREPARED" && op.attempts === 0 && this.#clock() < d.end,
        "NO_REDISPATCH",
      );
      op.state = "DISPATCHING";
      op.attempts = 1;
    });
  }
  complete(id, receipt) {
    return this.change((d) => {
      const op = d.operations[id];
      insist(
        op?.state === "DISPATCHING" && op.expected_index === d.next_index,
        "CALLER_STATE",
      );
      op.receipt = receipt;
      op.state = "COMPLETED";
      d.next_index++;
      d.last_advance_at = this.#clock();
    });
  }
  unknown(id, reason = "RESPONSE_UNCERTAIN") {
    return this.change((d) => {
      const op = d.operations[id];
      if (op && op.state !== "COMPLETED") {
        op.state = "UNKNOWN";
        op.reason = reason;
      }
    });
  }
}
