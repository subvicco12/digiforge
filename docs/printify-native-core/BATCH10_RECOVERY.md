# Batch10 native integration recovery — partial software delivery

Starting core SHA: d154c601b3c5ed0ec93eaaac268bc4bba26758f4. Companion fixed at8e669c1aac5bd916769a6d25b07de9a620a17a9c and unchanged. Batch6 contract digest remains6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434. No production/staging installation, real credentials or live provider calls.

## Implemented actual native path

Native GET intents now resolve an immutable core-owned binding to an existing sealed independently approved PrintifyApiReadWorkflow plan and authorization. Existing account/shop/integration/credential association, owner/email proof, permission, policy and transport/vault controls remain authoritative. No caller-supplied grant in sourceReferences, no new sender, approval system, secret store or acceptance-policy exception.

One intent binds one authorization, exact request endpoint and current actor/session. Both aliases and the existing one-shot read plan claim use strict SQL insert, not WordPress add_option's upsert. Lost/partial index or audit persistence denies; a grant cannot be reassigned. Every attempt rechecks issuance/expiry (maximum300seconds), live session, exact current approval revision/hash and current provider binding. Stored minimized receipt and audit are reread before output. After-dispatch failures preserve UNKNOWN attempt evidence and prohibit automatic grant replay.

Actual native positive/negative tests exercise existing core approvals and mock HTTP, never fake missing native mutation authority. The expiry-during-response regression demonstrated3attempts under the old check versus1after correction. The deterministic concurrent-winner injection demonstrated WordPress upsert overwriting a prior claimant; strict SQL preserves that claimant and denies. Provider responses and all credential material in tests are synthetic.

This supports only the seven operations already covered by the governed generic read service: SHOPS_READ, PROVIDERS_READ, PROVIDER_READ, PRODUCTS_READ, PRODUCT_READ, ORDERS_READ, ORDER_READ. Native blueprint/variant/shipping and owned-upload grant adapters remain missing. Aggregate GET_READ remains unadvertised. The normal business read path cannot run under STOP/external lock and inherits existing bounded safe-read retries. It is not the separately approved acceptance exception and grants no operational activation.

## Batch9 failure reconciliation

- Missing workbook fixture: exact tracked docs/engineering/original-source/workbook-original-complete.json was omitted from the test artifact scaffold. Direct exact checkout includes it; affected12tests177assertions pass. No workbook data was invented or modified.
- PublishReview competing connection: test incorrectly treated a Unix socket as numeric port. It now uses WordPress parse_db_host including socket and IPv6 behavior; all original lock/no-dispatch assertions remain.
- Native held journal workers: priming wp_salt then rolling back generated salt options reproduces crash exits0instead73/74 and history_integrity. The test bootstrap now defines unique deterministic synthetic WordPress salts, consistent in parent/children. Production salts/seal verification unchanged. Fault-injected and normal29test563assertion runs pass.
- Catalog39failures: the existing composer gate already runs phpunit.wordpress.catalog.xml.dist in a separate production-policy process. Running those files in local mode was the wrong harness. The canonical48test11746assertion gate passes without source-policy changes or new exclusions.
- Document/parser and image failures: reuse existing Poppler and exact pinned Python/Pillow/NumPy/OpenCV dependencies read-only inside the isolated runner. No software installed, provider request, credential use, or parser assertion relaxed. Full results are recorded against the final exact head in PR1264; do not infer a passing full suite from individual fixes.

## Verification boundaries

Frozen native read suite:11tests41assertions. Fixed logical database first pass:40tests604assertions; identical WordPress namespace repeated with WP_TESTS_SKIP_INSTALL=1:40tests597assertions. Different assertion totals are actual fixture branching; same40testcases pass. These are process/database tests, not hosted power-loss certification. The explicit all-root rollback counterexample and false independent-authority flags are preserved.

Configured PHP unit suite with pinned processor:2193tests10770assertions,1existing skip. Offline suite198tests passes using the pinned interpreter and PHP on PATH. Configured PHPStan/PHPCS, lint and legacy tests pass. Final full WordPress, catalog, extracted artifact, repository-security and exact-head CI results belong in the consolidated PR receipt; missing local scanner/tooling must be reported rather than claimed passed.

Independent source review found no additional concrete defect after the per-attempt authority, UNKNOWN and strict-claim fixes. Review is not live acceptance or hosted durability proof.

## Explicit remaining engineering blockers

Do not claim complete native integration. Current core records still lack authoritative canonical product/order identities and versions, native producer/fence/receipt sequencing, approved allocation quantities, consistent review inventory and independent checkpoint/revocation/drain authority. NativeHeldJournal remains candidate evidence with approval_verified=false, dispatch_permitted=false and independent_checkpoint_verified=false. Returning it as a canonical registered operation would fabricate guarantees. Mutation, publication, allocation, canonical snapshots and recovery ports therefore stay denied. See BATCH10_PORT_JOIN_GAPS.md for source-backed prerequisites.

No new native schema/migration, operational permit, portal route, background scheduler, source-pin update or provider capability is silently enabled. Full source/package certification and separate installation/binding/credential-custody/operational approvals remain mandatory. STOP ALL ON, external safety lock ON, automation OFF and RELEASE HOLD.
