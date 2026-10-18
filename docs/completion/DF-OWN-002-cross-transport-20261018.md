# DF-OWN-002 draft-only cross-transport enforcement

Owner approved implementation and synthetic testing on 2026-10-18. Based on #1199, ff7181ee793fb1c6732727dabbfa4aaea1c91ef2. This supersedes the earlier approval-blocked transport scope, not the authentic-evidence requirements. No Finance edits, real credentials, provider calls, activation or safety changes.

## Boundaries

- `PrintifyConnectionBoundary`: reuse DF-OWN-001 relational evidence, signed review and immutable binding hash. Exact installation/environment/business/store/program/shop/provider/account/external-shop/integration/capability context; no authority from credential sharing.
- `PrintifyScopedCredentialRetriever`: binding and runtime gates before ciphertext lookup, material fingerprint check, pre/post callback revalidation and credential-result redaction. Missing scope denies legacy callers.
- `PrintifyLiveTransportInterlock` and `PrintifyControlledTransport`: retain independent user/order/production/STOP ALL/external-lock checks; verify consumed authorization against durable nonce, package/mapping approvals, action, expiry and request fingerprint. A new atomic dispatch receipt uses existing InnoDB options/audit records and a database lock. Existing receipt OR audit anchor denies replay, even if the receipt is deleted. Caller transactions, unavailable audit/storage/lock and uncertain commits deny dispatch.
- `ProductionExecutionPermitConsumer`: add original authorization payload/action to its internal result for dispatch-time expiry verification. No change to approval authority or nonce consumption.
- `PrintifyReconciliationLookup`: pinned operation identity and scope before credentials and every page; old UNKNOWN records remain historical and cannot acquire authority.
- Legacy catalog sync/client: concrete binding checks before/after every attempt, including retries; optional callbacks cannot bypass them. Scoped catalog repository retains its narrower existing grant/exception controls.
- `ConnectionTester`: separately reviewed connection-read capability and exact external-shop response; unscoped administrative calls deny. No readiness inferred from account-wide or foreign results. Etsy/Gelato behavior unchanged.

Post-dispatch drift/revocation/expiry produces an uncertain network-attempted result, never successful evidence or automatic retry. Revocation cannot recall a request already accepted by a provider; authentic outcome reconciliation remains necessary. Raw low-level clients are not authorization grants; mapped application entry points above must provide reviewed context. No new portal activation action.

## Schema and compatibility

No new schema/migration or startup DDL. Existing reviewed capability records may now explicitly approve connection read/order submission/production authorization; catalog review never implies those capabilities. Legacy requests retain their old fingerprint when no scope exists, but cannot execute. Scoped fingerprints include immutable connection context. Historical records are not backfilled or deleted. Digital stays independent; deferred shops remain denied.

## Local evidence

All transports mocked; persisted safety controls remain ON/ON/OFF. Focused WordPress: **81 tests, 1,691 assertions PASS**. Production catalog profile: **39 tests, 618 assertions PASS**. Affected unit tests: **132 tests, 491 assertions PASS**. Fault-injection audit-write error output is expected and asserted. Coverage includes both mutation actions, all identity fields, deleted receipt replay, concurrent lock contention, missing nonce, expired consumed authorization, audit failure rollback, credential drift/leakage (including secondary WP_Error history), in-flight revocation, per-page/per-retry revocation, role loss and foreign shop responses. Existing tests explicitly review synthetic scopes; UUID fixture names avoid confirmed duplicate-integration collisions.

Additional WordPress-aware static validation (13 affected classes, level 5), configured repository static analysis, coding standards and source acceptance-matrix validation PASS. The local PHPStan sandbox cannot open a worker socket, so it was run serially with --debug and a 1 GiB analysis memory limit. Hosted exact-head audit is required before review readiness. Existing browser evidence is inherited, not freshly executed for this backend-only change.

## Acceptance and remaining evidence

No criterion promotion: 246/268 implemented candidates and 22 UNVERIFIED remain conservative, not final acceptance. DF-OWN-002 closes the approved local enforcement batch, not actual Etsy/Printify identity, integration assignment, PP-011 selected variant, supplier geometry/shipping, mockup rights, production authority or installed-site acceptance. Supply authentic environment-bound evidence for independent review; do not activate or bypass global locks. Release hold remains active. Optional future engineering should be driven by demonstrated defects, not fabricated supplier records.

Final review reproduced a secondary-error-history credential leak (one failing synthetic regression before correction). Redaction now examines every error code and all retained data, not only primary/latest data. Final focused rerun and exact-head audit cover the corrected candidate.

One repeated local run hit a pre-existing fixed-key preflight fixture persisted by earlier transaction tests; the fresh 80-test run used a fresh isolated prefix and passed without test/production assertion changes.

A second negative regression reproduced credential lookup from a reviewed but explicitly disabled connector. Shared runtime checks now separately require current CONFIGURED/enabled state after exact binding validation, including the mutation interlock; catalog GET exceptions retain their existing separate path. Local ownership review is not connector enablement.

Final connector-state correction: focused WordPress 81/1,691 PASS, catalog profile 39/618 PASS, affected units 132/491 PASS, additional static PASS. After wiring the mutation interlock to the same runtime guard, the complete new boundary class was rerun: 31 tests/672 assertions PASS.
