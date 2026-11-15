# Checkpoint-bound synthetic final sender — offline qualification

Status: IMPLEMENTED_OFFLINE / TESTED_SYNTHETIC. HOSTED_UNVERIFIED / RECOVERY_BLOCKED / CREDENTIAL_CUSTODY_BLOCKED / LIVE_NOT_AUTHORIZED. No deployment, credential use, provider request, production change, safety amendment or merge. Core78e7f973455a3c89319be06f0907caf2103658a7 and companion4da802a1568b13bcf1bbca28eba009b8b6148ef1 are unchanged references.

## Scope and reuse

This extends source84b58335f0332fea6c205865df4c526bcbc4d973 without restarting its observer or the existing GET authority. New SyntheticCheckpointFinalSender composes the existing IndependentAuthorityService, OfflineRuntime, AuthorityJournal and SyntheticFinalSender. A private checkpoint gate adds a distinct CHECKPOINT_EXECUTE path. It has no HTTP registration, production factory, environment activation, provider mutation or new database/table. The original standalone synthetic authority retains its existing test behavior; a guarded instance rejects ordinary EXECUTE/SESSION rather than silently falling back.

Mode defaults to DISABLED; only the explicit synthetic qualification mode accepts a checkpoint operation. The trusted fixture pins one immutable prepared GET payload and all native context fields. No live core authorization/product-version join is claimed: the fixture's pinned revisions are test inputs, not operational evidence. The future core remains the sole owner of approvals, shop mappings, vault and native transactions. The actual production Worker still returns503 outside the existing explicit synthetic factory, and the frozen Stage A target/caller modules and disabled inventory workflow remain unchanged.

## Admission and durable intent

The existing workload and custody signatures authenticate the full message, including its checkpoint request. Existing GET authorization validates owner/client/session/shop/account/credential binding before querying the checkpoint issuer. The new gate then compares exact configured payload bytes, operation/request/intent fingerprints, owner/session/review and physical deployment identity. It consumes the existing observer's dual-signed synthetic witness acknowledgement, never treats it as current operational authority, and checks matching root epoch/generation.

One synchronous AuthorityJournal CAS inserts checkpoint-intent:<operation_id> and checkpoint-proof:<observation_hash> together. They are bounded non-secret one-use tombstones, not a second native approval store or full native journal. Only afterward does existing OfflineRuntime create RESERVED/ADMITTED signed journal entries and the existing witness acquire its dispatch lease. There is no network or asynchronous callback inside the SQLite transaction. A lost acknowledgement retains any committed intent; the instance fences and the outcome remains UNKNOWN. The adapter never clears consumption, retries or rearms after crash.

At each privileged physical guard, the service revalidates workload/custody freshness, the complete existing state/witness journal and the checkpoint's current root. After all asynchronous checks it synchronously rechecks the root, one-use intent/proof, epoch/generation, freshness and physical lease immediately before invoking the synthetic fetch callback. A STOP after proof signing or during deferred credential custody prevents dispatch. A STOP after physical invocation cannot unsend it: response use becomes UNKNOWN and emergency acknowledgement must follow the existing pending-drain protocol. The final sender retains its GET allowlist, no redirects/body/arbitrary headers,20-second physical timeout,1 MiB cap and no retries.

Successful results use existing minimized signed TESTED_SYNTHETIC receipts. If current checkpoint evidence becomes stale/revoked before return, the service returns a bounded UNKNOWN result instead of presenting historical observed data as current acceptance. Historical journal receipts are not rewritten. Native mutation readiness is never granted.

The mandatory currentGuard is a synchronous witness-owned final boundary, not an asynchronous remote authorization Boolean. After cryptographic verification, the service compares the actual SQLite document and every journal envelope to that verified snapshot, then checks the witness's current revision/generation/reservation/lease and root before invoking fetch without another await. Missing/async/Boolean substitutes deny. This port is implemented only by the synthetic witness; a hosted implementation must place actual dispatch within its mandatory trusted authority boundary. Adding another remote snapshot alone would move the race rather than remove it.

The observer now preserves evidence_expires_at as the minimum issuer/witness signed expiration. The gate honors shorter signed lifetimes at admission, dispatch and release, rather than extending them to a30-second maximum. After asynchronous outer-response signing, the service repeats synchronous state/witness/root/expiry checks; invalid evidence suppresses the newly signed response with UNKNOWN while preserving historical receipts.

## Fault evidence and limitations

New tests exercise the actual observer, SQLite journals, signed workload/GET authority, witness and physical synthetic sender together. They cover default/unknown modes, ordinary-path denial, exact identity and version substitution, concurrent duplicates, intent/proof durability, invalid inner approval before evidence consumption, pre-proof/post-signature/final-callback STOP, generation-only revocation, witness outage during custody, lost commit acknowledgement, restart, corrupted journal, proof expiry, uncertain in-flight timeout and redacted receipts.

Two new end-to-end counterexamples intentionally remain failing security guarantees despite passing test assertions:

1. After successful dispatch and acknowledged emergency drain, restoring every synthetic state/root/configuration and recreating authority revives the same consumed signed operation. A current independent root fences subordinate rollback, but an all-root restoration still defeats the model.
2. A provider that still accepts an old credential copy remains reachable outside the cooperative final-sender fence. No synthetic exclusive=true claim establishes actual invalidation or exclusive custody.

The existing observer counterexample also remains: STOP after witness signing can leave historical evidence cryptographically valid. The integrated path now rechecks current state at dispatch, but this does not turn historical signed observations into generally current permits.

A fresh read-only automated review found three Important integration defects: late witness/state changes during verification could reach fetch, shorter signed proof lifetimes were extended, and STOP during response signing could release OBSERVED. Each was reproduced with failing tests and corrected at its boundary. The additional currentGuard contract rejects Boolean/Promise substitutes. Full source/fault regression must pass after this fix pass; no fresh human approval or hosted qualification is inferred. Two minor coverage suggestions (explicit guarded SESSION and standalone CHECKPOINT_EXECUTE negatives) are deferred; both denial branches were inspected and ordinary EXECUTE fallback is tested.

No test establishes Cloudflare crash durability, provider-side credential invalidation, protection against malicious privileged deployment, independence from all administrators, or hosted cross-worker consistency. SQLite/WordPress/site-files rollback fixtures are synthetic; no restoration drill or production fault injection occurred.

## Future canonical native-port handoff

Keep canonical v1 and all REGISTER/RESERVE/mutation advertisement denied. Do not copy epochs from the synthetic fixture, observer output, local hashes or held-journal revisions.

The separate core session must eventually provide actual authority/identity joins for owner, exact account/Shop2/integration3/credential-reference, authorization revision, product and approval versions. A qualified external authority must then authenticate independent recovery epoch, revocation generation, witness high-water/namespace/deployment floor and consumed operation identity; CAS native operation registration/reservation and pre-dispatch journal evidence; own physical dispatch and exclusive usable credentials; and import trusted terminal/UNKNOWN receipts without replay. Neither a Boolean callback to WordPress nor this synthetic gate can supply that authority.

Before integration, qualify the root/administrator/restore boundary, fresh owner authority, credential invalidation/custody, emergency drain and hosted persistence using separately approved synthetic infrastructure. Only then review an operational native producer/adapter and its versioned interface. No change to the frozen core/companion is part of this work.

## Hosted-pilot prerequisites

The existing account is only a development target. Owner-provided metadata says usage model standard, zero Workers/namespaces, Billing Read unavailable and administration independence not established. No new account requests were needed. These facts prove neither Workers Free entitlement nor USD0 operation. Require non-secret entitlement/quota/CPU evidence, bounded synthetic budget, independently reviewed deployment/restore/secret roles, private invocation, cleanup and separate provisioning/execution approval. No paid plan, credential migration or operational policy approval is inferred.

STOP ALL and external safety lock remain ON, automation OFF, RELEASE HOLD active. Historical UNKNOWN reservation22 is untouched. The exact-head source audit, full test counts, reproducible source/evidence hashes and Drive readback belong in the final immutable qualification receipt; hosted artifact and operational certification are separate gates.
