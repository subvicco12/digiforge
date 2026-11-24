import { DatabaseSync } from 'node:sqlite';
import { createHash } from 'node:crypto';
import { canonical, hex, insist, verify } from '../../../../contracts/cloudflare-dispatcher/protocol.mjs';

const digest = value => createHash('sha256').update(canonical(value)).digest('hex');
const integer = value => Number.isSafeInteger(value) && value > 0;
export const identityFields = Object.freeze([
  'operation_id', 'correlation_id', 'idempotency_id', 'shop', 'account', 'integration', 'actor',
  'session_generation', 'authorization_revision', 'policy_revision', 'payload_digest',
  'journal_revision', 'event_hash',
]);

function exactKeys(object, fields) {
  insist(object && Object.getPrototypeOf(object) === Object.prototype, 'SCHEMA');
  const actual = Object.keys(object);
  insist(actual.length === fields.length && fields.every(key => Object.hasOwn(object, key)), 'SCHEMA');
}

export async function verifyEnvelope(wire, pin, now) {
  insist(typeof wire === 'string' && Buffer.byteLength(wire) <= 4096, 'SIZE');
  const envelope = JSON.parse(wire);
  insist(canonical(envelope) === wire, 'NON_CANONICAL');
  exactKeys(envelope, ['key_id', 'manifest', 'signature']);
  insist(pin.revoked !== true, 'SIGNER_REVOKED');
  const m = await verify(envelope, pin.keyId, pin.key);
  exactKeys(m, [...identityFields, 'version', 'issuer', 'audience', 'phase', 'epoch', 'sequence', 'issued', 'expires']);
  insist(m.version === 1 && m.issuer === pin.issuer && m.audience === pin.audience && m.phase === 'SEND_ADMISSION', 'CONTRACT');
  for (const field of ['operation_id', 'correlation_id', 'idempotency_id', 'payload_digest', 'event_hash', 'epoch']) {
    insist(hex(m[field]), 'DIGEST');
  }
  for (const field of ['shop', 'account', 'integration']) {
    insist(typeof m[field] === 'string' && /^[A-Za-z0-9_-]{1,100}$/.test(m[field]), 'SCOPE');
  }
  for (const field of ['actor', 'session_generation', 'authorization_revision', 'policy_revision', 'journal_revision', 'sequence', 'issued', 'expires']) {
    insist(integer(m[field]), 'REVISION');
  }
  insist(integer(now) && m.issued <= now && now < m.expires && m.expires - m.issued <= 30, 'EXPIRY');
  return Object.freeze(m);
}

// Separate fixture database. A CAS is explicit, and an ambiguous result never grants permission.
export class RecoveryAnchor {
  constructor(path) {
    this.db = new DatabaseSync(path);
    this.db.exec('PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000; CREATE TABLE IF NOT EXISTS floor (id INT PRIMARY KEY CHECK(id=1), stamp TEXT NOT NULL)');
  }
  initialize(stamp) { this.db.prepare('INSERT INTO floor VALUES (1,?)').run(canonical(stamp)); }
  read() {
    insist(!this.offline, 'ANCHOR_OFFLINE');
    const row = this.db.prepare('SELECT stamp FROM floor WHERE id=1').get();
    insist(row, 'ANCHOR_MISSING');
    return JSON.parse(row.stamp);
  }
  acknowledge(before, after) {
    insist(!this.offline, 'ANCHOR_OFFLINE');
    insist(after.epoch === before.epoch && after.sequence === before.sequence + 1, 'ANCHOR_ORDER');
    insist(this.db.prepare('UPDATE floor SET stamp=? WHERE id=1 AND stamp=?').run(canonical(after), canonical(before)).changes === 1, 'ANCHOR_DIVERGENCE');
    insist(!this.loseAck, 'ANCHOR_ACK_UNKNOWN');
  }
  close() { this.db.close(); }
}

/** Offline reference sequencer. Never exported by, or packaged into, the plugin. */
export class FixtureSequencer {
  constructor(path, anchor) {
    this.anchor = anchor;
    this.db = new DatabaseSync(path);
    this.db.exec('PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000; CREATE TABLE IF NOT EXISTS witness (id INT PRIMARY KEY CHECK(id=1), state TEXT NOT NULL)');
  }
  initialize(epoch) {
    insist(hex(epoch), 'EPOCH');
    const state = { epoch, sequence: 1, stopped: false, held: null, outcome: null, revoked: false };
    this.db.prepare('INSERT INTO witness VALUES (1,?)').run(canonical(state));
    this.anchor.initialize(this.stamp(state));
  }
  state() {
    return JSON.parse(this.db.prepare('SELECT state FROM witness WHERE id=1').get().state);
  }
  stamp(state = this.state()) {
    return { epoch: state.epoch, sequence: state.sequence, state_digest: digest(state) };
  }
  change(edit) {
    // Anchor I/O is outside the witness write transaction. The predicate compares
    // the anchored snapshot after acquiring the writer lock, fencing intervening writes.
    const before = this.anchor.read();
    this.db.exec('BEGIN IMMEDIATE');
    let state;
    try {
      state = this.state();
      insist(canonical(this.stamp(state)) === canonical(before), 'RECOVERY_HOLD');
      edit(state);
      state.sequence += 1;
      this.db.prepare('UPDATE witness SET state=? WHERE id=1').run(canonical(state));
      this.db.exec('COMMIT');
    } catch (e) {
      this.db.exec('ROLLBACK');
      throw e;
    }
    this.anchor.acknowledge(before, this.stamp(state));
  }
  hold(identity) {
    exactKeys(identity, identityFields);
    this.change(s => {
      insist(!s.stopped && !s.held, 'HELD_DENIED');
      s.held = canonical(identity);
    });
  }
  stop() { this.change(s => { s.stopped = true; }); }
  revoke() { this.change(s => { s.revoked = true; }); }
  async admit(wire, clock, pin) {
    const manifest = await verifyEnvelope(wire, pin, clock());
    const now = clock();
    let result;
    this.change(s => {
      const identity = Object.fromEntries(identityFields.map(key => [key, manifest[key]]));
      insist(!s.stopped && !s.revoked && !pin.revoked && !s.outcome && s.held === canonical(identity), 'ADMISSION_DENIED');
      insist(manifest.epoch === s.epoch && manifest.sequence === s.sequence && now < manifest.expires, 'STALE');
      s.outcome = 'IN_FLIGHT';
      result = { epoch: s.epoch, sequence: s.sequence + 1, operation_id: manifest.operation_id };
      s.admission = result;
    });
    return result;
  }
  quarantine(admission) {
    this.change(s => {
      // STOP can advance the sequence while this same admission is draining.
      insist(s.outcome === 'IN_FLIGHT' && canonical(s.admission) === canonical(admission), 'NO_ADMISSION');
      s.outcome = 'UNKNOWN';
    });
  }
  close() { this.db.close(); }
}

export async function sendSynthetic(wire, pin, clock, sequencer, sink) {
  const receipt = await sequencer.admit(wire, clock, pin);
  try {
    await sink();
  } finally {
    sequencer.quarantine(receipt);
  }
  return { outcome: 'UNKNOWN', retry_permitted: false, provider_execution_authorized: false };
}
