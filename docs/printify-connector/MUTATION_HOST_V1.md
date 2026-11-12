# Companion Batch 2: mutation coordination protocol 1.0.0

Status: OFFLINE_IMPLEMENTED; SYNTHETICALLY_VERIFIED. No provider sender, production adapter, installation approval or external execution authority is supplied. Companion version 0.3.0 preserves the GET interface and preparation interface at 1.0.0. No migration, vault, mapping store, independent approval store or scheduled work is introduced. PR1264 is untouched.

## Core integration boundary

`Plugin::mutationHost()` discovers ONLY `DigiForge\Connector\Printify\MutationBridge::connect('1.0.0')`. The optional bridge is not present in the current core. It must lazily return an implementation of companion `Preparation\MutationCoreInterface` after that interface is available; core must not require an absent companion class during ordinary boot. Missing bridge, wrong type, version mismatch or exceptions deny. Constructing an adapter does not access credentials or send HTTP. The companion cannot implement this core authority on its own.

The port exposes `version()` and `exchange(array $request)`. Each request carries fixed protocol action, exact immutable binding, original context, evidence references, duplicate key and action-specific data. Every response must echo the binding, version and action; carry a positive journal revision; and carry matching independently checked recovery/checkpoint epochs plus its canonical SHA-256. Unknown envelope fields are rejected. Hashes detect changed bytes; they are not signatures and do not authenticate untrusted input. The in-process trusted core port supplies provenance. No REST route or browser-supplied callback exposes this port.

Binding includes operation/payload/intent/scope fingerprints, shop ID/key, integration ID, credential-reference integration ID, provider-shop identity, environment, actor, approval fingerprint, authorization revision, current/expected product versions. Core must resolve the actual account/credential association, owner/session permissions, product registry identity, approval and evidence. Caller claims or hashes alone are never sufficient.

## Actions and required core semantics

| Action | Contract |
|---|---|
| AUTHORIZE | Resolve current actor/session, exact scope/credential/account, approval, evidence, version, expiry, STOP ALL and external lock. No cached grants. |
| REGISTER | Transactionally verify the prepared request and approval, persist immutable intent, enforce physical duplicate-key uniqueness and append audit; return PREPARED only after durable acknowledgement. |
| CLAIM | In the same transaction revalidate current authority and atomically compare PREPARED to FENCED with a unique fence. Lost acknowledgement never restores a slot. |
| RECOVER | Return current authoritative journal and checkpoint evidence; never rearm or clear a fence. |
| CANCEL | PREPARED becomes CANCELLED. FENCED becomes UNKNOWN: cancellation cannot assert that a provider request was cancelled. Preserve evidence/history. |
| RECEIPT_READ | Resolve a trusted stored receipt for this operation and current fence, never a caller-supplied provider response. |
| RECONCILE | Atomically validate current authority, receipt hash, exact fence/epoch, unique event, sequence and state, persist minimized result and audit. Exact duplicate is idempotent; conflict or out-of-order receipt denies. |
| OUTCOME | UNKNOWN preserves consumed identity. METADATA_RECONCILED requires an already trusted matching provider receipt; a companion Boolean or locally computed metadata cannot finalize an operation. |

The adapter checks authorization before and after journal exchanges. This does not replace transactional checks in core. The future final sender must independently validate core fence/current authority immediately before any provider use; no sender is implemented here. Ordinary STOP ALL and external safety lock always deny. No GET-only exception is activated.

## Receipt contract and transitions

`DF_PRINTIFY_RECEIPT_1` requires exact operation/payload/intent/scope, shop/integration/credential reference, approval/revision/product version, unique event ID, positive sequence, fence ID, recovery epoch, integer timestamps, kind, HTTP status, minimized body and canonical receipt hash. RESPONSE accepts HTTP 200 and the strict operation-specific minimized fields, then reuses Batch1 upload/product validation. UNKNOWN requires status 0 and an empty body. Receipt metadata never certifies content rights, provider image content, publication status or commercial readiness.

Core state transitions:

- PREPARED → FENCED or CANCELLED.
- FENCED → UNKNOWN or METADATA_RECONCILED.
- UNKNOWN → UNKNOWN or METADATA_RECONCILED only through new verified evidence, never through retry.
- METADATA_RECONCILED/CANCELLED cannot return to PREPARED/FENCED.
- Exact duplicate receipt returns the same journal result without another event or operation.
- Wrong shop/fence/hash/epoch, conflicting event, stale sequence and unregistered receipts deny.

Crash-before-send retains FENCED because the companion cannot prove that nothing was sent. Crash-after-send/lost acknowledgement remains UNKNOWN or fenced pending trusted core reconciliation. Revoked principals cannot use this adapter to renew or replay authority. Core emergency/recovery operators must retain independent access to deny operations and preserve audit; this companion does not implement emergency ownership or grant changes.

## Recovery boundary and limits

Companion caches no authoritative journal state. Restoring companion files or local snapshots cannot replace the current core journal. Tests restore old records against a newer independent synthetic checkpoint and demonstrate denial. Test-only file journals exercise process reopen, flock-based concurrent CAS, atomic replacement, fsync and lost acknowledgement. These are local synthetic tests, not Hostinger durability, database transaction or hosted rollback certification.

Core must provide a durable authoritative journal plus a recovery checkpoint outside the supported restoration domain. A hash chain or one restored database is insufficient. Coordinated rollback of journal and all trusted checkpoints remains RECOVERY_BLOCKED. Exclusive credential custody and the independent revocation boundary remain unqualified. No production durability predicate is weakened. Historical reservation22 is not read, changed or replayed.

## Verification and next batch

Batch1 tests are retained. Batch2 covers missing/incompatible core, current WordPress actor enforcement, stale approval/version, wrong shop/credential, expired/revoked authority, STOP ALL/lock, forged journal hash, malformed/tampered/conflicting/duplicate/out-of-order receipts, UNKNOWN-to-terminal evidence, duplicate concurrent claims, process restart, lost claim/receipt acknowledgement and stale restored snapshots. Synthetic approval fixtures do not establish real owner enrollment or provider connectivity.

Batch3 should implement the separately reviewed **core-owned** port and transactional approval/journal/audit integration, then recovery-operator views and trusted receipt import. Coordinate with PR1264 rather than editing its branch. Native core availability, durable recovery authority, credential custody, verified provider binding, Stage2 acceptance and separate installation/write approvals remain external gates. No production or staging installation is authorized.
