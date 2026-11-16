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
