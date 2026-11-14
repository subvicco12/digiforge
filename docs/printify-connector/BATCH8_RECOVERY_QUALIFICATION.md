# Batch 8 recovery qualification

Companion baseline: 4f9c0e1f20b69632b7de3567b529846389ce6222. Offline engineering only. Main core runtime unchanged.

## Implemented boundary

RecoveryDiagnostics obtains only NativePortAdapter::recovery(...,{action:INSPECT}). It exposes fixed classifications for PREPARED, FENCED/in-flight uncertainty, UNKNOWN, conflicting and recovery-required evidence, minimized core receipt references and pending lease count. It never writes a journal, releases a lease, retries, cancels, resets or rearms. Missing/revoked/incompatible native evidence denies. The renderer contains no form, route, execution control or scheduler. STOP ALL and the external lock remain in force; diagnostic evidence does not grant dispatch authority.

## Synthetic persistence and fault model

The test-only RecoveryStore uses a per-store exclusive flock, atomic local file replacement, explicit flush/fsync, append-linked event hashes and a separate synthetic checkpoint file. It serializes multi-process reservations and validates revision continuity, previous hash, current state hash, generation and checkpoint head. Prepared, reserved, UNKNOWN and conflicting state survives recreation of the adapter. An expired reserved lease becomes UNKNOWN without restoring a slot. Emergency increments generation, preserves uncertainty for reservations and permanently latches the synthetic store against rearm. No real operation or provider sender exists in this model.

Checkpoint-first writes intentionally fail closed if the state write then fails. Lost acknowledgement preserves the committed record; no implicit retry occurs. Tests cover storage failure, interrupted journal write, lost acknowledgement, one-root rollback, receipt/state tampering, restart, cross-worker one-shot contention, lease timing, stale generations and no automatic rearm.

**Limits:** these are synthetic local filesystem tests, not proof of Hostinger/Cloudflare crash durability, power-loss semantics, independent revocation or production transaction correctness. The checkpoint file is not an independent witness. Restoring BOTH state and checkpoint revives earlier authority in the explicit counterexample test. That passing counterexample test proves the desired invariant is NOT satisfied under all-root rollback: RECOVERY_BLOCKED. No production fallback is enabled. Future core integration must independently qualify durable journals, receipt provenance, recovery anchors, emergency drains and exclusive credential custody.

## Native reference findings

PR1264 independently advanced to 5fc4c7af57acee3b73077c22ab9db068161487a8. The detached source was inspected and its own committed-source packaging tool produced an offline artifact with build-owned file hashes; no files on the core development branch were changed. Its registry digest matches the Batch6 canonical schema.

The committed reusable probe `tests/contracts/printify-native-conformance.php <extracted-core-artifact-root> <reviewed-core-sha>` checks the actual factory interface, five versions, manifest/schema/source/spec identity, unsupported-version denial, malformed/write-method denials and no secret-callback execution. It uses only a minimal WP_Error shim and is NOT WordPress runtime acceptance. On that exact artifact: 18 checks passed; advertised capability is PANEL only; successful native workflows NOT_VERIFIED. Core source explicitly denies missing journal/checkpoint/allocation/workflow identity joins. No fake successful native journal was introduced.

The companion's default core source pin is unchanged. The probe compares the explicit independently reviewed source only for this offline test; it cannot update runtime trust. Native panel owner/session integration and positive full-port workflows remain blocked pending genuine core implementation, trusted pin review and isolated WordPress conformance. No external connectivity or credentials were tested.

## Fixture warnings

The legacy QA-stage assertion interpolated an undefined $brief, weakening its intended string check. It now checks the literal variable name. Anonymous database doubles in five test files now declare their last_error field, matching the wpdb property written by actual read models. Assertions and production runtime remain unchanged; deprecation suppression was not used. Unit warning/deprecation output was rechecked after fixes.

Expected WordPress negative-path duplicate-key diagnostics remain distinct from PHP fixture warnings; they are not suppressed or claimed repaired here.

## Remaining gates / Batch 9

Implement and review authoritative native journal/checkpoint/allocation/workflow joins in the separate core session. Then independently verify its exact new SHA, review the source pin, and run positive and negative actual-core conformance in isolated WordPress, including atomic reservation, revocation, provenance, emergency drain and credential-custody denial. Keep all-root rollback and missing independent anchoring as release blockers. No production durability certification or installation readiness is claimed.

STOP ALL ON, external lock ON, automation OFF, RELEASE HOLD. No installation, credentials, provider request, publication, order submission, Cloudflare, merge, policy activation or historical replay.
