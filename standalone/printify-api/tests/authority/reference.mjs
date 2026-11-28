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
  acknowledge(before, after, epochAdvance = false) {
    insist(!this.offline, 'ANCHOR_OFFLINE');
    insist((epochAdvance ? after.epoch !== before.epoch : after.epoch === before.epoch) && after.sequence === before.sequence + 1, 'ANCHOR_ORDER');
    insist(this.db.prepare('UPDATE floor SET stamp=? WHERE id=1 AND stamp=?').run(canonical(after), canonical(before)).changes === 1, 'ANCHOR_DIVERGENCE');
    insist(!this.loseAck, 'ANCHOR_ACK_UNKNOWN');
  }
  close() { this.db.close(); }
}

/** Offline reference sequencer. Never exported by, or packaged into, the plugin. */
export class FixtureSequencer {
  constructor(path, anchor, recoveryFault = () => {}) {
    this.anchor = anchor;
    this.recoveryFault = recoveryFault;
    this.db = new DatabaseSync(path);
    this.db.exec('PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000; CREATE TABLE IF NOT EXISTS witness (id INT PRIMARY KEY CHECK(id=1), state TEXT NOT NULL); CREATE TABLE IF NOT EXISTS recovery_predecessor (id INT PRIMARY KEY CHECK(id=1), state TEXT NOT NULL)');
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
  #change(edit, epochAdvance = false) {
    // Anchor I/O is outside the witness write transaction. The predicate compares
    // the anchored snapshot after acquiring the writer lock, fencing intervening writes.
    const before = this.anchor.read();
    this.db.exec('BEGIN IMMEDIATE');
    let state;
    try {
      state = this.state();
      insist(canonical(this.stamp(state)) === canonical(before), 'RECOVERY_HOLD');
      if (epochAdvance) this.db.prepare("REPLACE INTO recovery_predecessor VALUES (1,?)").run(canonical(state));
      edit(state);
      state.sequence += 1;
      this.db.prepare('UPDATE witness SET state=? WHERE id=1').run(canonical(state));
      if (epochAdvance) this.recoveryFault('before-commit');
      this.db.exec('COMMIT');
    } catch (e) {
      this.db.exec('ROLLBACK');
      throw e;
    }
    if (epochAdvance) this.recoveryFault('after-commit');
    this.anchor.acknowledge(before, this.stamp(state), epochAdvance);
  }
  hold(identity) {
    exactKeys(identity, identityFields);
    this.#change(s => {
      insist(!s.stopped && !s.held, 'HELD_DENIED');
      s.held = canonical(identity);
    });
  }
  stop() { this.#change(s => { s.stopped = true; }); }
  revoke() { this.#change(s => { s.revoked = true; }); }
  async admit(wire, clock, pin) {
    const manifest = await verifyEnvelope(wire, pin, clock());
    const now = clock();
    let result;
    this.#change(s => {
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
    this.#change(s => {
      // STOP can advance the sequence while this same admission is draining.
      insist(s.outcome === 'IN_FLIGHT' && canonical(s.admission) === canonical(admission), 'NO_ADMISSION');
      s.outcome = 'UNKNOWN';
    });
  }
  recoveryStatus() {
    const s = this.state();
    const stamp = this.stamp(s);
    insist(canonical(stamp) === canonical(this.anchor.read()), 'RECOVERY_HOLD');
    return { stamp, admission: s.admission ?? null, stopped: s.stopped, outcome: s.outcome, effect: s.effect ?? null };
  }
  async recover(wires, pins, clock) {
    const proof = await recoveryProof(wires, pins, clock);
    this.#change(s => this.#advance(s, proof, clock), true);
    return this.recoveryStatus();
  }
  async reconcileDivergence(wires, pins, clock) {
    const proof = await recoveryProof(wires, pins, clock);
    const floor = this.anchor.read();
    const current = this.state();
    const saved = this.db.prepare('SELECT state FROM recovery_predecessor WHERE id=1').get();
    insist(saved && canonical(proof.manifest.stamp) === canonical(floor), 'DIVERGENCE_UNPROVEN');
    const previous = JSON.parse(saved.state);
    insist(canonical(this.stamp(previous)) === canonical(floor), 'DIVERGENCE_PREDECESSOR');
    this.#advance(previous, proof, clock);
    previous.sequence += 1;
    insist(canonical(previous) === canonical(current), 'DIVERGENCE_SUCCESSOR');
    // The witness is already committed. Repair only the anchor, by CAS against
    // the exact signed predecessor. Ordinary witness writers are fenced until then.
    this.anchor.acknowledge(floor, this.stamp(current), true);
    return this.recoveryStatus();
  }
  #advance(s, proof, clock) {
    const { manifest: administrator, signatures, principals } = proof;
    insist(principals.every(p => !p.revoked), 'RECOVERY_REVOKED');
    const now = clock();
    insist(signatures.every(m => m.issued <= now && now < m.expires), 'RECOVERY_EXPIRY');
    insist(s.stopped && ['IN_FLIGHT', 'UNKNOWN'].includes(s.outcome), 'RECOVERY_STOP_REQUIRED');
    const identity = JSON.parse(s.held);
    insist(canonical(this.stamp(s)) === canonical(administrator.stamp)
      && canonical(s.admission) === canonical(administrator.admission)
      && identity.journal_revision === administrator.journal_revision
      && identity.event_hash === administrator.journal_head, 'RECOVERY_STALE');
    insist(administrator.next_epoch !== s.epoch && !(s.epochs ?? []).includes(administrator.next_epoch), 'RECOVERY_EPOCH');
    s.epochs = [...(s.epochs ?? []), s.epoch];
    s.epoch = administrator.next_epoch;
    s.outcome = 'UNKNOWN';
    s.effect = administrator.effect;
  }
  close() { this.db.close(); }
}

export async function sendSynthetic(wire, pin, clock, sequencer, sink, payload) {
  const manifest = await verifyEnvelope(wire, pin, clock());
  insist(typeof payload === 'string' && Buffer.byteLength(payload) <= 262144, 'PAYLOAD_BINDING');
  const submittedDigest = Buffer.from(await crypto.subtle.digest('SHA-256', Buffer.from(payload))).toString('hex');
  insist(submittedDigest === manifest.payload_digest, 'PAYLOAD_BINDING');
  const receipt = await sequencer.admit(wire, clock, pin);
  try {
    await sink(payload);
  } finally {
    sequencer.quarantine(receipt);
  }
  return { outcome: 'UNKNOWN', retry_permitted: false, provider_execution_authorized: false };
}


const recoveryFields = [
  'stamp', 'admission', 'journal_revision', 'journal_head', 'next_epoch', 'effect', 'terminal',
];

async function recoveryManifest(wire, pin, role, now) {
  insist(typeof wire === 'string' && Buffer.byteLength(wire) <= 4096 && pin.revoked !== true, 'RECOVERY_PIN');
  const envelope = JSON.parse(wire);
  insist(canonical(envelope) === wire, 'NON_CANONICAL');
  exactKeys(envelope, ['key_id', 'manifest', 'signature']);
  const m = await verify(envelope, pin.keyId, pin.key);
  exactKeys(m, [...recoveryFields, 'version', 'issuer', 'audience', 'role', 'issued', 'expires']);
  insist(m.version === 1 && m.issuer === pin.issuer && m.audience === 'fixture-recovery' && m.role === role, 'RECOVERY_CONTRACT');
  exactKeys(m.stamp, ['epoch', 'sequence', 'state_digest']);
  exactKeys(m.admission, ['epoch', 'sequence', 'operation_id']);
  insist([m.stamp.epoch, m.stamp.state_digest, m.admission.epoch, m.admission.operation_id,
    m.journal_head, m.next_epoch].every(v => hex(v)), 'RECOVERY_DIGEST');
  insist([m.stamp.sequence, m.admission.sequence, m.journal_revision, m.issued, m.expires, now].every(integer)
    && m.issued <= now && now < m.expires && m.expires - m.issued <= 30, 'RECOVERY_EXPIRY');
  insist(m.terminal === 'UNKNOWN' && ['UNOBSERVED','ATTEMPTED','ACKNOWLEDGED'].includes(m.effect), 'RECOVERY_EFFECT');
  return m;
}

async function recoveryProof(wires, pins, clock) {
  const sender = await recoveryManifest(wires.sender, pins.sender, 'SENDER', clock());
  const administrator = await recoveryManifest(wires.administrator, pins.administrator, 'ADMINISTRATOR', clock());
  const anchor = await recoveryManifest(wires.anchor, pins.anchor, 'ANCHOR', clock());
  const principals = [pins.sender, pins.administrator, pins.anchor];
  const keys = await Promise.all(principals.map(p => crypto.subtle.exportKey('spki', p.key)));
  insist(new Set(keys.map(k => Buffer.from(k).toString('hex'))).size === 3, 'ROLE_SEPARATION');
  const statement = manifest => Object.fromEntries(recoveryFields.map(k => [k, manifest[k]]));
  const binding = canonical(statement(administrator));
  insist(binding === canonical(statement(anchor)) && binding === canonical(statement(sender)), 'RECOVERY_BINDING');
  return { manifest: administrator, signatures: [sender, administrator, anchor], principals };
}
