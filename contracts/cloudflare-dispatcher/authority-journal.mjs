import { canonical, insist } from "./protocol.mjs";
/** Reference authority storage. Its independent administration/durability is NOT certified. */
export class AuthorityJournal {
  #storage;
  #clock;
  constructor(storage, clock) {
    this.#storage = storage;
    this.#clock = clock;
    storage.transactionSync(() =>
      storage.sql.exec(
        "CREATE TABLE IF NOT EXISTS df_security_authority (id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL, document TEXT NOT NULL)",
      ),
    );
  }
  initialize(config, anchor, workloadKey, minimumVersion = 1) {
    this.#storage.transactionSync(() => {
      insist(
        this.#storage.sql
          .exec("SELECT revision FROM df_security_authority WHERE id=1")
          .toArray().length === 0,
        "ROOT_ALREADY_INITIALIZED",
      );
      const d = {
        revision: 0,
        epoch: config.epoch,
        namespace: config.namespace,
        code: config.code,
        account: config.account,
        credential_binding: config.credential_binding,
        workload_key: workloadKey,
        minimum_version: minimumVersion,
        anchor,
        stopped: false,
        recovery: false,
        booted: false,
        generation: 1,
        last_time: this.#clock(),
        nonces: [],
        leases: [],
        nonce_count: 0,
      };
      this.#storage.sql.exec(
        "INSERT INTO df_security_authority VALUES(1,?,?)",
        0,
        canonical(d, 1048576),
      );
    });
  }
  read() {
    const rows = this.#storage.sql
      .exec("SELECT revision,document FROM df_security_authority WHERE id=1")
      .toArray();
    insist(rows.length === 1, "ROOT_MISSING");
    const d = JSON.parse(rows[0].document);
    insist(
      d.revision === rows[0].revision && Number.isSafeInteger(d.revision),
      "ROOT_CORRUPT",
    );
    return d;
  }
  change(fn) {
    insist(fn.constructor.name !== "AsyncFunction", "ASYNC_TRANSACTION_DENIED");
    return this.#storage.transactionSync(() => {
      const d = this.read(),
        old = d.revision,
        now = this.#clock();
      insist(Number.isSafeInteger(now) && now >= d.last_time, "ROOT_CLOCK");
      d.last_time = now;
      const result = fn(d);
      insist(!result?.then, "ASYNC_TRANSACTION_DENIED");
      d.revision++;
      const changed = this.#storage.sql
        .exec(
          "UPDATE df_security_authority SET revision=?,document=? WHERE id=1 AND revision=? RETURNING revision",
          d.revision,
          canonical(d, 1048576),
          old,
        )
        .toArray();
      insist(changed.length === 1, "ROOT_CONCURRENT_CHANGE");
      return structuredClone(d);
    });
  }
  boot() {
    return this.change((d) => {
      if (d.booted) {
        d.stopped = true;
        d.recovery = true;
        d.generation++;
      }
      d.booted = true;
    });
  }
  active() {
    const d = this.read();
    insist(!d.stopped && !d.recovery, "ROOT_REVOKED");
    return d;
  }
  claim(nonce, active = true) {
    return this.change((d) => {
      if (active) insist(!d.stopped && !d.recovery, "ROOT_REVOKED");
      insist(
        d.nonces.length < 2048 && !d.nonces.includes(nonce),
        "ROOT_REPLAY_OR_CAPACITY",
      );
      d.nonces.push(nonce);
      d.nonce_count++;
    });
  }
  anchor(previous, next) {
    return this.change((d) => {
      insist(
        canonical(d.anchor) === canonical(previous),
        "ROOT_ANCHOR_CONFLICT",
      );
      insist(next.revision === previous.revision + 1, "ROOT_ANCHOR_CONFLICT");
      d.anchor = next;
    });
  }
  lease(id) {
    return this.change((d) => {
      insist(
        !d.stopped && !d.recovery && !d.leases.includes(id),
        "ROOT_REVOKED",
      );
      insist(d.leases.length < 10, "ROOT_LEASE_LIMIT");
      d.leases.push(id);
    });
  }
  complete(id) {
    return this.change((d) => {
      insist(d.leases.includes(id), "ROOT_LEASE_MISSING");
      d.leases = d.leases.filter((x) => x !== id);
    });
  }
  stop() {
    return this.change((d) => {
      d.stopped = true;
      d.generation++;
    });
  }
  fence() {
    return this.change((d) => {
      d.stopped = true;
      d.recovery = true;
      d.generation++;
    });
  }
}
