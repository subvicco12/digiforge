# Independent FACE_REPEAT visual evidence review

## Implemented local boundary

Stacked on audited PR #1166, `CutoutVisualReviewRepository` v1 retains a reference alpha PNG and binds its actual SHA-256, decoded geometry, source-photo SHA, cutout receipt, subject declaration (`dog`, `cat`, `human`, `multi_subject`), rights/consent evidence and measured QA. Source uploader identity comes from the existing immutable ingestion audit, never caller input. Reference images are canonical bounded PNGs (at most 1 MP / 4.5 MB), stored through the existing protected shop artifact store. Missing, changed, cross-shop, malformed or stale evidence fails closed. Subject type is an operator declaration, not automatic classification.

Authenticated `manage_digiforge_pod` REST POST routes:

- `/digiforge/v1/pod/visual-review/evidence`: exact keys `shop_key`, `case_key`, `cutout_key`, `source_sha256`, `subject_type`, `reference_base64`, `reference_sha256`, `reference_kind`, `rights`, `supersedes` (null or parent case key).
- `/digiforge/v1/pod/visual-review/assign`: `shop_key`, `case_key`, `case_sha256`, integer `reviewer_id`.
- `/digiforge/v1/pod/visual-review/decision`: `shop_key`, `case_key`, `assignment_sha256`, `outcome`, `flags`, `annotations`.
- `/digiforge/v1/pod/visual-review/inspect`: `shop_key`, `case_key`, optional integer `render_id` and `position`.

All operate exclusively in the existing `personalized_pod` shop and validated order scope. Intake is capped at 8 MiB JSON; other requests at 32 KiB. No customer-supplied file paths are accepted for reference images.

## Rights and consent evidence contract

`rights` contains exactly `source_rights`, `source_consent`, `reference_rights`, `reference_consent`. Each points to an operator-staged protected JSON document with `storage_reference` and SHA-256. The permitted path is `digiforge/product-factory/pod-review-evidence/shop-personalized_pod/<safe-name>.json`. Each document is at most 64 KiB with exact fields:

`schema: 1`, `shop_key: personalized_pod`, integer `order_id`, actual `asset_sha256`, `kind` equal to the corresponding rights key, `local_review_permitted: true`, `evidence_kind`, nonempty bounded `declaration`.

Source evidence kind must match the stored cutout's rights evidence; reference kind is `OWNER_SUPPLIED` or `SYNTHETIC_TEST_ONLY`. The document hashes, identities, asset/order/shop bindings and declared permission are verified on every read. This verifies evidence integrity, **not legal authenticity**, consent authority or commercial licensing. No document upload API or legal approval is invented. Owners must provide genuine documents and fixtures through the existing protected storage process.

## Independent review and correction

Only the case creator assigns an authorized reviewer. The reviewer must differ from both case creator and original cutout uploader. Assignments and decisions are immutable and hash/audit bound. Only that reviewer may decide `APPROVE`, `REJECT` or `REVISE`; replay, wrong assignment SHA, revoked capability and self-approval are rejected.

Flags use the existing manual/GrabCut/SAM defect contracts. At most 32 annotations specify defect, CRITICAL/MINOR severity, bounded integer rectangle and bounded comment. APPROVE requires all flags false, no unresolved annotations and no critical reference-mask geometry defects. Decisions do not auto-certify artwork. Non-photo cutout approval and subsequent rendering require the current independent APPROVE decision and identical flags. SOURCE_PHOTO retains its prior behavior and receipt namespace. Old cutout approvals remain intact but cannot substitute for fresh independent review.

REVISE permits a new immutable case linked to the same order and parent decision, with a changed cutout or reference hash. There is one child per parent and at most eight revisions. Old decisions remain visible; REJECT and APPROVE cannot be overwritten. Shop/order advisory locks, unique option keys, compare-and-swap head updates and an InnoDB transaction spanning evidence plus audit prevent competing writes and roll back failed audit persistence. Busy or unavailable transactional storage fails closed; no security fallback is enabled.

## Visual evidence

Inspection returns original photo, raw mask, reference matte, styled cutout and deterministic light/dark composites, along with actual-pixel alpha metrics and reference comparisons. A final product preview is returned only for the same order, approved canonical payload and matching cutout slot, with a certified **deterministically reproduced** stored render receipt. A receipt made before recipe approval cannot become canonical merely by approving a matching recipe later. Unavailable product renders are explicitly `REQUIRES_APPROVED_CANONICAL_RENDER`.

References remain `SUPPLIED_UNVERIFIED`; metrics are reproducible comparisons, not proof of an approved ground truth. `commercial_certified`, `supplier_certified` and external execution authorization remain false. No live calls, activation, spending, promotion or safety-control changes occur.

## Behavioral evidence and actual coverage

`tests/wordpress/RenderArtifactCertificationTest.php` covers separate reviewer identities, strict rights/consent/source/image bindings, idempotency, cross-shop denial, source/reference tampering, immutable decisions, REJECT/REVISE/APPROVE, annotation bounds, unique correction children, a second database connection holding the actual lock, audit-write rollback and retry, unchanged source-photo behavior, expired legacy approvals, canonical FACE_REPEAT output and rejection of nondeterministic preview receipts. Fixtures and review users are explicitly synthetic; no commercial artwork or real review authority is certified.

Existing real source coverage is unchanged: rights-cleared human and cat sources; dog/multi-subject source fixtures and all independently reviewed reference mattes remain missing. Prior SAM/PyMatting benchmark measurements are reused, not reclassified as reference accuracy. All 15 commercial engine fixture statuses remain MISSING. Owners must supply real asset-bound rights/consent evidence, reviewed reference mattes, designated independent reviewers, artistic acceptance and actual supplier print certification. Overall final-product acceptance remains INCOMPLETE.

## Consolidated local validation

- Full WordPress acceptance with configured real offline SAM Small/PyMatting: 448 tests, 5,553 assertions, PASS.
- Unit suite: 1,278 tests, 7,657 assertions; one explicit model-runtime skip in this suite (the configured real path is covered by WordPress). Legacy suites passed.
- All 100 offline tests passed across the initial run and the three manifest-only retries after correcting PHP PATH; the eight affected source-ledger linkage tests also passed after the evidence update.
- Configured static/standards checks, supplemental matting/QA boundary analysis, PHP syntax, controlled HTTP/secret scans and diff check passed. Installed Composer and pinned image/SAM dependency advisory audits reported zero advisories.
- Portal browser code is unchanged; prior audited 228-case desktop/mobile evidence is reused, not presented as a new run.

The initial static worker socket restriction was resolved by running the same check with local-socket permission; no production validator or test assertion was weakened. Deliberately broken audit-table writes in the rollback regression are expected negative-case errors. GitHub exact-head audit and committed package verification are recorded in the follow-on PR after publishing.
