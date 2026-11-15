# Shop 2 software-first integration sprint — 15 November 2026

Owner-authorized maximum-parallel engineering, existing draft PR1264 only. Baseline core `78e7f973455a3c89319be06f0907caf2103658a7`; main observed `3758d1385f7d4a62947248822c1fe1c89ec9818e`. Preserve all ancestor implementation and successful predecessor Audits5018/5019. This document is an early sprint checkpoint, not a48-hour completion certificate.

## Scope and source receipts

Blueprint6.0 was retrieved from connected Drive (`1isN_2m7eu5DTjykY7X67woiPx7wluhPc`). Read with the Four-Day MVP Blueprint and First Launch Master Plan; current software-first directive overrides product-campaign priority. The original151-row independent audit receipt is linked and hash-verified in CAPABILITY_RECONCILIATION_20261115.json; its document anchors include Blueprint, MVP and Operating Manual requirements. No fresh full reread of the Operating Manual is claimed in this batch.

LaneA date-policy commits `422cd01071489a06594552f26aeb78169e78a2b5` and `fc997474a80629a7f969f346199b24dc478f8a75` are integrated only as their exact additive10-file delta from `a52e02bb93927d10bfa7e3a235fbafa4bcfc859f`. Core78's protected-option dependency observer remains intact. The older LaneA branch is not used as a replacement core tree. Accepted W03 export, commercial worksheet, supplier evidence, artwork assets, native contracts, auth-read/scanner repairs and immutable records are preserved.

## Implemented repairs

- Paid webhook quantities retain raw validation semantics. Missing quantity keeps default1; null/Boolean/container/fractional/malformed/out-of-bounds quantities cannot become fabricated valid lines. Valid siblings survive; the order persists REVIEW_REQUIRED and signed evidence remains available. No automatic approval or fulfillment.
- Missing line currency inherits the persisted order currency instead of silently inventing USD for a zero/no-price line. Explicit line currency remains explicit; nonzero amounts still require currency evidence.
- Hybrid reconciliation uses listing-bound physical classification consistently: a digital registry entry cannot hide a missing POD mapping for a listing with a POD binding. Summary and discrepancy views fail closed when binding evidence is unavailable.
- Active business ownership requires an existing provider mapping with the same product version and exactly one stored owner. Missing provider rows, version drift and conflicting ownership deny instead of selecting the first plausible owner.
- Generic queue transitions cannot enter RETRY. Retry requires existing classified failure admission, attempt ceiling and backoff; UNKNOWN stays non-retryable.
- Stored-order held preparation composes exact shop/module authorization, a current independently reviewed local provider connection, approved order/plan, frozen line/material/ownership snapshot and typed personalization. Current evidence is re-read before returning. A scoped operator panel exposes stored IDs, review states and held preparation; it does not dispatch or certify render files. Legacy plans without the new snapshot require new preparation and review; immutable old plans are not rewritten.
- Reviewed English date display is explicit, source-versioned and opt-in through persisted schemas/proofs/portal. Canonical input remains ISO; old immutable schemas/proofs are not upgraded. Display policy receipt is not supplier/native typography or production approval.

## Acceptance and counts

The authoritative baseline stays151 total /100 implemented /43 partial /8 missing /31 independently accepted. Repairs and date integration are sub-requirement evidence, not automatic capability promotions. S2-ART-07 remains PARTIAL until exact order/production/version joins and independent acceptance are complete. The JSON retains all51 originally partial/missing rows and original owner assignments. In particular ORD06 stays LaneA, ORD13 separate API and OPS08 infrastructure.

Focused behavior tests and new actual-core W03 date integration tests are recorded by the final GitHub handoff. Full local unit verification and configured static/style checks are additional evidence. Initial integration diagnostics used old WordPress7.2-alpha; final targeted acceptance uses separate official7.1.4 core and matching released test files, with a distinct disposable database and private config; broad WordPress/audit cycles are not repeatedly restarted. Initial bootstrap/environment failures and preexisting constant warnings are not described as product passes. New source requires fresh exact-head CI; predecessor audit success does not certify this delta.

## Remaining gates and next work

CP04 held identity/date-policy/immutable export integration is the next bounded acceptance target, followed by S2-OPS-03/S2-ORD-12 buyer-specific held preparation/tracking/reconciliation composition. Native AUTHORIZE/REGISTER/CLAIM, allocation/publication/receipt/recovery positives remain blocked on core authoritative identity/version joins and separately owned authenticated producer, witness, final sender, usable-credential custody, revocation and hosted durability. No local hashes, test IDs or counters substitute for independent authority.

The separate API1260 owner remains at observed `200e6101bf8635453b2186be2f45ec87a5946465`; its StageA offline infrastructure factory is not installed/native dispatch authority. No separate-owner source edited. Companion1263 remains `4da802a1568b13bcf1bbca28eba009b8b6148ef1`, default pinned to core78. Every new core source commit requires separate companion review/pin promotion; no pin relaxation or new-pair qualification is claimed.

Hosted exact-artifact installation, physical account/shop/credential association, webhook signing/subscription setup, actual supplier/native parity and independent operational acceptance remain unverified. Software scope stays the complete151-capability platform; FL03/FL04 are regression fixtures, not launch campaigns. No current installed-candidate qualification established.

## Estimate and safety

The prior50–80 engineering-day full-scope estimate remains a provisional effort baseline, not a delay instruction or a proven calendar forecast. Parallel repair/integration work is underway; an honest replacement estimate depends on the remaining51 row-level acceptance requirements and external owners' exact interface receipts. This batch does not justify claiming all software can finish in48hours. Remaining external approval/hosted waiting time is unbounded.

STOP ALL ON; external lock ON; automation OFF; manual production approval; RELEASE HOLD. No merge, deployment, production database change, real credentials, provider writes, publication, orders, finance operations or Digital Shop development.

## Reproduce focused acceptance

Use the repository released-WordPress installer with a disposable database and matching core/test-library tag. No production configuration is needed. Set WP_TESTS_DIR and existing Composer polyfills. Run the classes listed in SPRINT_DELIVERY_OVERLAY_20261115.json through phpunit.wordpress.xml.dist, plus existing EtsyWebhookPersistenceAcceptanceTest and OrderCurrencyAcceptanceTest. The two complete held export/ZIP cases deliberately reconstruct current authority repeatedly and can take several minutes each; keep per-test bounds instead of removing rechecks. Run QueueRetryAdmissionTest and OfflineDateDisplayProofTest with phpunit.xml.dist. Root final logs are separate execution artifacts; no raw private test configuration is committed.

## Fresh local verification

- Entire PHP unit suite:2268tests/12058assertions, zero failures/errors; one existing experimental matting skip because owner-supplied weights/runtime are absent.
- Configured PHPStan (single-process debug mode to avoid sandbox loopback-listener restrictions) and PHPCS:PASS. Changed PHP syntax and whitespace:PASS.
- Released WordPress7.1.4 integrated targeted suite:93tests/2080assertions PASS, in7m52s. Includes signed intake/dedup/persistence, malformed quantities, currency identity, ownership, hybrid reconciliation, stored-order held preparation, date policy portal/intake and both exact business-bound JSON/ZIP journeys. All provider responses/source approvals are synthetic disposable fixtures, not substitutes for missing independent native authority.
- A subsequent exact-Etsy stored-channel fence is verified by the complete HeldFulfillmentPreparationTest separately; 9tests/816assertions PASS. Native standalone contracts/projection/journal suite:17tests/95assertions PASS.
- Independent source review found two held-preparation issues before push (scope ownership and post-approval line drift); both corrected and covered by real-core negatives. Final channel-specific persisted-evidence check is also explicit.

No source/hosted exact-artifact certification is implied by these local results. Retain one new exact-head audit after pushing, and classify its terminal result separately from preserved5018/5019.
