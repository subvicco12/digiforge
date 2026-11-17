# DB_GPO_NATIVE retained reviewer prototype

This opt-in component retains **existing public WordPress/DigiForge reviewer authorization** across a caller-owned local transaction. It neither creates a reviewer permission nor substitutes for independent issuer, custody, provider or allocation authority. There is no activation hook, production migration, HTTP route or managed-photo consumer adoption.

## Exports and required sequence

Require `bootstrap.php` explicitly from the separately qualified bridge/bootstrap. `RetainedReviewerAuthority::ABI_VERSION` is `db-gpo-native-reviewer-v1` (an interface version, not an authority epoch). The default core autoloader does not discover this opt-in service; absent integration continues to deny. No Node credentials or issuer private keys belong in this WordPress bridge. Derive the reviewer ID from the actual independently retained producer review provenance and construct the lease privately; do not accept a caller-provided reviewer/lease as proof of that source join. `DigiForge\NativeAuthority\WordPress\RetainedReviewerAuthority::capture(string $shop, int $reviewerId)` returns a private, noncloneable, nonserializable `RetainedReviewerLease` or `WP_Error`.

1. Capture in known autocommit/no transaction, using the actual authenticated decision actor and cookies. No reviewer login is created or assumed.
2. The consumer starts its existing local transaction, then calls `pin()`. The lease performs a current generation `FOR UPDATE` read and inserts a private transactional witness into its own lease row. The generation lock remains held by the consumer's transaction.
Before step 2, `validateBeforePin()` performs the fresh pre-start authority check. The bridge consumer calls it without entering or resetting a caller frame.

3. Perform the protected local decision using the independently qualified source/data contract. Call `validate()` immediately before the consumer's COMMIT. This is the authorization linearization point, not a claim that clocks stop through COMMIT.
4. After a checked COMMIT or explicit consumer rollback, call `release($expectedCommitted)` with the consumer's truthful, checked outcome. A contradicting private transaction witness returns UNKNOWN metadata and outranks subsequent cleanup failures. Call `finalAdmission()` before returning any private committed-success response. Expiry, authorization change, native-session change, physical connection loss or uncertain release denies admission. Rollback cannot produce successful final admission. A lease cannot be repinned, cloned, serialized or reused in another transaction.

Every result must be checked. The component never starts, commits or rolls back a caller transaction. Its release result is cleanup acknowledgement, **not** permission to emit a private response. It does not certify the consumer's COMMIT acknowledgement or business idempotency: the consumer must preserve truthful COMMITTED/UNKNOWN outcomes, never automatically replay an uncertain decision, and keep existing workflow replay protection.

## Source of permission and supported backend

A physically separate, autocommit `wpdb` connection invokes the actual public `ShopAccess::authorize()` for native/signed Orders and POD for both the current decision actor and foreign reviewer. Existing public Global Primary Owner active/database-approval checks remain mandatory. Public ACL repository reads only identify the conservative supported transport: every grant belonging to the exact captured actor or selected reviewer in the retained shop must have GPO1 as grantor. An unrelated subject's delegation cannot authorize these subjects and does not become a retained dependency. The code does not reimplement grant permission predicates. Existing Global Owner public checks verify all four canonical shop ledgers.

The decision actor is reloaded from actual native DB rows through public cache/user/role mechanisms and authenticated using the original logged-in cookie and stock database session manager. The foreign reviewer is never made the current user. Native role/options/user caches are refreshed on the auxiliary connection; unknown capability, metadata, option, session and authorization-cache hooks are denied. Verified stock callbacks and the two existing negative-only DigiForge global guards remain present. Multisite, external/custom cache backends, custom roles/session managers, unsupported salts and file-delegated grants are outside this backend and fail closed. Immutable configured authentication keys/salts must exist.

## Actual write fence

`OfflineSchema::triggers()` is the executable SQL inventory. Its INSERT/UPDATE/DELETE authority triggers advance a singleton local permission-invalidation generation for:

- `wp_users` mutations;
- native single-site capability/user-level and `session_tokens` usermeta;
- native role options, home/site URL/salt option sources, enrolled DB owner authority/claim, portal profile/event and scoped ACL state/event option keys;
- only the real authority audit event pairs: shop grant/revoke, portal profile changed, global owner claimed.

UPDATE checks both OLD and NEW identity to cover key/event renames. Business/review/denial audits and unrelated options do not touch the generation. A pinned generation row serializes concurrent authority DML. The tests demonstrate a real second connection's permission write timing out while an unrelated business audit INSERT succeeds.

The generation is not an independently issued authority epoch. It is a local SQL invalidation mechanism driven by actual permission writes. Generation guard triggers prohibit deletion/reset/reinsertion and require exact increment by one; missing generation causes protected authority DML to fail. Capture/validation verify InnoDB storage and the exact trigger inventory. Lease state is persisted separately and the private transaction witness distinguishes rollback/new-frame reuse from the original caller frame.

## Explicit limits and remaining integration decision

`OfflineSchema::installForDisposableTests()` and uninstall require the local WordPress test environment and are manual, disposable installation tools only. No production schema deployment has been authorized or performed. Required privileges, schema deployment and security-owner source/backend qualification must be accepted before production use. This prototype does not protect against root/DDL operations, trigger replacement during a decision, unfenced deployment/configuration changes, privileged physical database restore, a malicious PHP process, or external clock rollback.

Natural authority/session/grant expiry is checked afresh, bounded by a maximum 30-second lease, and checked again before private acknowledgement. A clock can advance after precommit validation; no clock-freeze or unconditional through-COMMIT claim is made. If the caller itself changes authority inside its transaction, a final current generation read detects the change.

**M01 has an actual offline consumer positive; deployment and independent acceptance remain pending.** B `PersonalizationReadContext` captures the real producer-selected review provenance, derives the exact selected reviewer, and retains this component's authorization lease. The pure managed material projection shares the existing typed `validateLine` rules and uses exact current locked B rows plus the retained authoritative selection. Missing bridge ABI keeps the previous managed denial. The existing local workflow and held-job enqueue consumer pin inside their verified owned transactions, supply truthful release outcomes, and call final admission after all release/signed-access SQL. Queue pin failure rolls back only that queue-owned verified frame before throwing; failed verification becomes UNKNOWN.

The actual composition tests cover a foreign reviewer different from the canonical submission reviewer, with zero reviewer sessions during B consumption, real photo selection/layout/render review, APPROVED_PACKAGE, signed local plan review, BLOCKED intent/held bundle and BLOCKED local queue job. They also deny same-pixel stale render/package/held material after a new real producer epoch, deny private committed success when the selected reviewer loses native access during the final signed read, and report UNKNOWN/nulls when actual ROLLBACK contradicts a claimed COMMIT.

This is not provider execution or native allocation acceptance. M02–M08 have explicit partial/component-only gates in `evidence/m01-m09-disposition.json`; process-crash/restore and every authorization/data backend are not claimed covered. No 151-ledger count or whole 21-case matrix has been promoted.

## Reproduction

From this repository, run the new test directory with the isolated local fixture configuration. The temporary preloader selects a separate disposable database; it does not change the existing test configuration. CI uses its normal WordPress test configuration and runs this same directory without a local preloader:

```sh
PATH=/workspace/.onboarding/bin:$PATH \
WP_TESTS_DIR=/workspace/digiforge-platform-sprint/wp-develop-released/tests/phpunit \
/workspace/.onboarding/bin/php \
  -d auto_prepend_file=/tmp/retained-authority-wp-preload.php \
  /workspace/digiforge/vendor/bin/phpunit -c phpunit.wordpress.xml.dist \
  services/native-authority/wordpress/tests
```

Keys and signed host/delegate fixture files are generated only in the existing private temporary fixture locations. No credentials, cookies, private keys or raw lease IDs are exported in evidence.
