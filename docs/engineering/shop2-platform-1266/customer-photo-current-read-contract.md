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

## Operator stored-session scope discovery v1 — candidate for B REST intake

`CustomerEditorRepository::OPERATOR_SESSION_SCOPE_CONTRACT` is `shop2-editor-session-scope-v1`; `targetShopForSession(string $session): string|WP_Error` returns only `personalized_pod` after the existing operator access admission validates the sealed/audited, non-revoked session, actual stored order/line/buyer scope, and bound source document. It accepts no claimed shop or caller-supplied order identity and exposes no customer/session record or payload. Ordinary buyers cannot use it as operator admission.

The wrapper acquires a fresh producer read fence, validates it and checks release; caller-owned transactions, unavailable state, stale/foreign stored order scope, missing/corrupt/revoked access, changed proof bytes and lost/uncertain release deny. It rechecks the current operator before returning. This leaves the existing `access()` and persisted record/version format unchanged.

B owns integration into generic REST target discovery. The bounded proposal is only the anchored existing POST `/customer-editor/{session}/intake`, `/publish`, and `/revoke` operator routes: verify this version/method is available, require POD in the actor's permitted shops, derive the target from this source-owned public method, reject conflicting shop claims, and propagate errors rather than treating them as an unscoped/global fallback. B must retain original callback identity, nonce, role, current authorization, target and immutable version checks. Buyer independent view/upload/fields/approve routes, access/grant and mockup routes, and unrelated shops are outside this proposal. No alternate POST handler is introduced.

This is a current scope observation, not a retained authorization lease or a freeze on B-owned order/identity/schema/mapping or external role/authority writers. Existing callbacks must repeat their own current source and authorization checks. It does not solve managed reviewer-authority retention, permit a cached proof in a caller transaction, certify the consumer, or promote S2-ART-14/S2-ORD-06. B's generic security source is not modified by the producer implementation.

## B-owned REST scope bridge intake — exact reuse

Issue1266 comment6918504798 authorizes A to reuse only `includes/Security/ShopAccessTargetScope.php` from B commit `4ecbaf1945aa3fe4de8afe355e52153ae2d33110`, SHA-256 `f5456a4c74c6ebf0df658c683139d10d1be7f03e5b32bdf9d8d42184898d46f8`. B retains semantic ownership. The 17-line bridge consumes the producer contract above only for registered operator POST intake/publish/revoke. URL, query, form, JSON and merged session/shop claims must agree with the actual route and stored POD target. Missing producer version/method, exceptions or failed stored admission deny rather than falling back to unscoped admission. All original nonce/capability/callback checks remain. Buyer and unrelated routes are unchanged.

A real registered REST regression reproduced HTTP403 with POD-only signed admission before this exact reuse and HTTP200 afterward. Additional real WordPress cases cover local layout/render/review/buyer preference/revoke, identical buyer/output artifact hashes, query/form/JSON claim substitution, malformed typed input, buyer/operator confusion, invalid nonce, corrupt proof, foreign order, preserved caller transaction and ordinary/signed actor revocation. Malformed JSON arrays may be rejected HTTP400 by core REST validation before scope admission; scope substitutions deny HTTP403. These results close the reproduced REST discovery defect only; they do not certify native/physical output, positive managed reviewer-authority retention or either complete capability.

### Managed authority: security-owner disposition

Issue1266 comment6919246288 confirms that no current public API retains reviewer authority. The managed transactional consumer must remain denied. Eligibility uses the actual independent selection reviewer from `review_provenance`, ordinary Orders capability and signed Orders/POD admission; it does not require that historical reviewer's login/session. The decision actor's current session remains required. A has no clearance to duplicate private ACL storage or change shared security writers.

A future security-owner handle must bind exact reviewer/review/selection revision, decision actor, connection, complete supported profile/grant/grantor/native-role sources, expiry and commit/unknown-outcome semantics. Its owner must cover ACL/profile/owner writers, native WordPress user/role changes, authority-file replacement, cache/backend behavior and expiry without writes, or explicitly deny unsupported backends. A's byte fence and fresh eligibility observations are not substitutes. Required combined positive acceptance remains fresh capture → owned consumer transaction → current locked version/schema/artifact → approved held plan, with concurrent authority revocation/expiry, stale input, crash/uncertain-commit/release and replay negatives. No claim of positive managed authority retention or S2-ART-14/S2-ORD-06 completion is made.
