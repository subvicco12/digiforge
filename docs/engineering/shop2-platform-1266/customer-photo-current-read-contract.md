# Photo current-read and producer fence contract

This repairs the source owner's stale Repeatable Read selection and stale legacy-NULL failures. It does not accept B-owned business transactions, authorize execution, or establish a cross-owner final-consumer fence. STOP ALL and external lock remain ON; automation remains OFF; manual approval and RELEASE HOLD remain required.

## Existing read signatures

```php
CustomerPhotoSelection::forOrder(string $shop, int $order, int $line, ?int $expectedRevision = null): array|WP_Error|null;
CustomerPhotoSelection::resolveForOrder(string $shop, int $order, int $line, ?int $expectedRevision = null): array|WP_Error|null;
CustomerPhotoSelection::forRender(array $render): array|WP_Error|null;
```

The strict `forOrder` never returns legacy absence. `resolveForOrder` can return NULL only after exact current order/line/shop/actor admission and absence of both selected head and order-level managed history. Positive expected revisions cannot fall back to NULL. Signed selection and editor/access evidence, audit proofs, current approved mapping/schema/canonical review, and local source bytes are still checked through the existing public admission path. Errors, revocation, missing/corrupt evidence, newer editor revisions, and incompatible scope deny admission.

Before those reads, the producer checks the actual session's autocommit, active transaction, and connection identity. A caller-owned transaction or autocommit=0 receives `photo_current_read_caller_transaction`; it never returns an old selected epoch or old legacy NULL. No COMMIT, ROLLBACK, SET isolation/autocommit, independent credential reader, `$wpdb` substitution, or independent-connection data read is performed by this admission. The caller retains its transaction and its pending writes.

## Supported producer fence

```php
CustomerPhotoProducerFence::acquireRead(): CustomerPhotoProducerFence|WP_Error;
$fence->validate(): true|WP_Error;
$fence->release(): true|WP_Error;
```

Acquire before any caller transaction, on the same autocommit WordPress connection that will read and consume the photo. Keep the object through the final supported autocommit consumer; validate immediately before consuming its result and release in `finally`. Nested public photo reads retain the outer lease. Failure to acquire/validate/release denies the result. Releasing is idempotent and does not alter caller transaction state. Destruction is cleanup, not a substitute for checked explicit release.

The fence is a zero-wait MariaDB advisory lock scoped to the current database and WordPress table prefix. It serializes participating A-owned editor/selection writes across lines and sessions. This deliberately conservative contention scope avoids a stale pre-read session-to-line mapping and the first-managed-selection gap. A second connection cannot upload, reorder, save text, grant/revoke access, select/review inputs, publish/approve buyer previews, or prepare/approve photo renders through the participating public boundaries while a read lease is retained. Same-connection mutation during a read lease is denied. Lock loss, connection replacement, transaction-state uncertainty, or unexpected transaction entry denies admission.

`acquireMutation()` and `startMutationTransaction()` are producer implementation APIs. An A-owned mutation starts its own transaction only after checking that no caller-owned transaction exists. Its nested validation reads may use that explicitly owned fresh transaction. This exception is local to the producer's mutation boundary; a read lease acquired before a B/caller transaction does **not** enable transaction reads.

## Limits requiring integration by other owners

The advisory lock coordinates participating A producer methods. It does not lock external order/business/shop identity, role/capability, schema, mapping, catalog/template, or filesystem writers. Hash and authorization checks are observations at admission; retaining this lease does not freeze all those sources through an arbitrary later consumer. Other owners must provide compatible locking/current reads and final actor/scope validation before claiming such a guarantee.

All caller-owned Repeatable Read, Read Committed, Serializable, or implicit autocommit=0 transactions remain unsupported for this authority. This includes pre-acquiring an A read lease and then starting a B transaction: public selection/render admission still denies. A green offline source-owner test is not B transaction acceptance or capability-ledger promotion. No generic consumer callback, new fulfillment orchestrator, provider call, production release, or cross-owner changes are introduced.

Tests use two actual wpdb connections against a dedicated disposable MariaDB socket, public upload/selection/review/revocation methods, and actual mapping/schema rows. Synthetic fixture setup explicitly closes only its own WordPress harness transaction before entering production methods. Test cleanup retains its checked ID boundaries; production never closes caller transactions.

## Retained read-lease witness v1 — validation only

Lane B requested this narrow extension in Issue1266 comments6910795305/6911055734. `CustomerPhotoProducerFence::RETAINED_READ_LEASE_CONTRACT` is `shop2-photo-retained-read-lease-v1` and `$fence->validateRetainedReadLease(): true|WP_Error` is the source-owned witness.

Acquire the read lease in fresh autocommit, perform all required public photo admission reads **after acquisition and before entering a transaction**, then retain the exact object. The new witness may validate it inside an explicit or implicit caller-owned transaction. It checks only the same WordPress object, connection ID, private generation, unreleased read-lease kind and current MariaDB lock owner. It performs two session/lock metadata SELECTs: no photo, source, identity or authorization data read; no transaction start/COMMIT/ROLLBACK/SET; no connection replacement. Mutation leases and lost/uncertain/released leases deny.

This does **not** change `validate()` or public photo/read/mutation admission: those continue to deny caller-owned transactions, even when a lease is held. The new witness cannot upgrade a read lease into A mutation ownership. It does not establish that a hash supplied by a consumer was actually read under the lease.

A safe consumer must bind the exact immutable result obtained through the fresh public admission to its own actor/order/line/version evidence; retain its own current identity/schema/mapping locks and CAS; perform its final actor/scope/approval checks; call the retained witness before its atomic decision/COMMIT; and check release afterward in a guaranteed cleanup path. B owns those consumer obligations and all non-A writers. A witness returning true means only that participating A producer writes have stayed fenced: it is not authorization, source readback, artifact certification, supplier/native authority, dispatch permission or approval.

The earlier categorical statement that all caller transactions are unsupported remains true for **photo data admission**. This addendum permits only lease ownership validation in a caller transaction, not stale or cached data admission. Full S2-ART-14/S2-ORD-06 acceptance still requires B's actual combined consumer tests; no capability is promoted here.


## Independent review provenance v1

Strict fenced operator selection admission (`forOrder`/`resolveForOrder` and managed `forRender`) adds `review_provenance` only after all existing snapshot, sealed review, audit and current independent-reviewer authority checks pass. Its exact fields are `version=shop2-photo-review-proof-v1`, `selection_sha256`, `selection_revision`, `reviewer_user_id`, `review_sha256`, `decision=APPROVED`, and `reviewed_at`. It does not change stored selection/review bytes or their hashes. `current`, status, submit/review and customer results do not emit this field, even when the underlying selection is approved. No note, customer/session secret or additional private payload is included.

`reviewer_user_id` is the independent selection reviewer. Identical canonical payload reuse may retain another actor in canonical submission `reviewed_by`; consumers must not substitute that actor. The digest binds the complete immutable review, including its private note, without publishing that note. This is photo-input review, not render/buyer/owner/production approval.

Read the proof under the retained producer lease in fresh autocommit before a consumer transaction. The lease freezes participating A review writers, not external reviewer roles or signed grants. B must preserve both existing canonical reviewer checks and current independent reviewer `manage_digiforge_orders` capability plus signed personalized_pod orders/POD admission at its final decision using its own current authorization protocol. All actor/order/line/version binding, other source locks, CAS and release obligations above remain. The provenance field alone is not a retained authorization grant, provider certification, consumer acceptance or capability promotion.
