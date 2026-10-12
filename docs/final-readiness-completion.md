# FINAL READINESS — Completion, Release & Activation Guardrails

## Objective
Complete DigiForge as a production-ready local-first WordPress platform without enabling any live Etsy, POD, AI, fulfillment, payment, refund, tax/GST, accounting, advertising, worker, schedule, webhook, or other external side effect.

## Release posture
- Plugin release: 1.0.103.
- Core plugin database version: v23; additive catalog acceptance schema: v24.
- STOP ALL remains ON.
- `automation_armed` remains internal and non-user-writable.
- Add `activation_authorized`, internal and non-user-writable, default false.
- Effective automation requires all three conditions: `activation_authorized=true`, `automation_armed=true`, and `stop_all=false`, plus the relevant feature switch and its dedicated scoped authorization where required.
- No normal REST or admin endpoint may write internal activation gates.

## Deterministic readiness report
Provide a local-only readiness report that checks at minimum:
- current schema equals expected schema
- STOP ALL is active
- activation authorization is false
- automation is unarmed
- no feature switch is effectively enabled
- audit health has no unresolved failure
- queue has no expired running leases
- operational retention policy is fail-closed
- recovery drill configuration is available

The report must expose only non-secret status metadata and return an overall state of `READY_LOCKED` only when the platform is healthy and externally locked.

## Admin and REST surfaces
- Add an authenticated `GET /digiforge/v1/readiness` endpoint.
- Extend the main DigiForge admin page with release version, schema version, lock state, readiness state, and component checks.
- No endpoint may activate external execution.

## Final verification
Hosted CI must cover:
- version 1.0.103 with core database version v23 and additive catalog acceptance schema v24
- internal activation gates are non-writable
- effective switches remain false while required internal/scoped gates are false or STOP ALL is true
- deterministic readiness report
- no external HTTP clients outside the audited allowlist
- no secret exposure
- all prior WordPress/MariaDB migration tests remain green
- PHP syntax, PHPUnit, PHPStan and PHPCS
- deterministic ZIP/checksum/release manifest and package boundaries

## Completion boundary
Release packaging and bounded software checks do not establish full original-spec completion or readiness for deployment. The current mandatory requirement denominator and integrated final acceptance remain unverified. Separate explicit deployment and activation authorization is required later.


## Current final-convergence certification posture
DigiForge remains on plugin version 1.0.103 with core database version v23 and additive catalog acceptance schema v24 while final convergence changes are certified through the standard Engineering & Safety Audit. Master 500 v2 remains a governed MIGRATION_CANDIDATE with exact source identity, immutable v1 parent evidence and immutable human ACCEPT/REJECT evidence; acceptance does not promote the catalog or authorize production/external execution.

Portal analytics now fails closed when KPI, lifecycle-denominator or analytics-snapshot evidence is unavailable, and persisted analytics snapshots are presented through the bounded hash-verifying finance operations read model. Queue/recovery and integration surfaces remain observational unless separately authorized.

Database-backup recovery certification is deferred by owner instruction and must remain recorded as deferred rather than inferred or fabricated. Production deployment and every external activation remain separate explicit authorization boundaries.

## FACE_REPEAT cutout visual QA follow-on

Experimental SAM Small + PyMatting cutouts now have bounded actual-pixel alpha measurements and deterministic light/dark previews. Authenticated shop/order-scoped QA and fresh immutable operator review bind stored source, mask, styled result and preview hashes; canonical rendering recomputes the same evidence. Existing approvals remain preserved and require a separate versioned QA review. Critical empty/opaque/clipped masks fail closed; approved manual alpha fallback uses the same gates.

The fixture/reference inventory verifies source/license hashes, fully decoded reference geometry and structured claimed review evidence without promoting claims to authorized review. Human and cat source fixtures exist; dog/multi-subject sources, all reviewed mattes, designated reviewer authority, customer consent and supplier certification remain missing. Actual prior human cold/warm predictions reproduce 20,535 visible and 4,728 soft-alpha pixels with zero canvas boundary contacts. No measured semantic accuracy, artistic threshold approval or commercial certification is asserted. All fifteen commercial engine fixture statuses remain MISSING, and original 1,771 source-row categories remain unchanged. See `docs/engineering/face-cutout-visual-qa.md` and its report for precise scope and reproduction.

Overall final-product acceptance remains INCOMPLETE. STOP ALL ON; external lock ON; automation OFF. Finance #1147 and earlier branches are untouched. No merge, deployment, live calls/spending or catalog promotion.

## Independent visual evidence and review follow-on

FACE_REPEAT now binds protected source/reference rights and consent declarations, actual asset hashes, measured alpha comparisons and independent reviewer assignment to immutable shop/order-scoped cases. APPROVE/REJECT/REVISE decisions and bounded correction chains are transactionally persisted with audit evidence. Critical defects, replay, stale evidence, self-approval and mismatched or nondeterministically certified product previews fail closed. SOURCE_PHOTO behavior and prior evidence remain preserved. See `docs/engineering/cutout-independent-visual-review.md` for exact contracts and behavioral references.

This closes the local independent-review software boundary only. Synthetic regression fixtures do not certify licensing, reference truth, artistic quality or supplier readiness. Real coverage and all fifteen MISSING commercial fixture statuses remain unchanged. Finance #1147, safety controls, external operations and overall INCOMPLETE acceptance posture are unchanged.

## Visual-certification pilot v1

The owner-supplied 12-case pilot pack is preserved with verified original member hashes. A bounded read-only CSV checker maps case groups to existing intake contracts and rejects invalid scope, file/hash/geometry claims, self-review and fabricated certification. All twelve supplied cases remain MISSING: the pack contains procedures/templates only, with no photos, mattes or authenticated reviews. Proposed thresholds remain unapproved, actual pilot accuracy metrics remain null and supplier certification remains absent. Existing benchmark sources are not reassigned to these cases. See `docs/engineering/visual-certification-pilot-integration.md` and the generated report. Existing runtime and original source-ledger categories are unchanged; overall acceptance remains INCOMPLETE.

## Customer personalization editor follow-on

An authenticated, operator-bound customer/order editor now supports transparent manual uploads, experimental offline extraction with manual replacement, immutable originals/revisions, typed draft fields, accessible mobile cutout inspection and separate digital product previews. Product display reuses certified canonical artwork and exact current supplier geometry plus an independently approved rights-bound orthographic mockup mapping. Customer approval records digital preference only; source/template/variant/mapping drift invalidates it. Stale, concurrent, oversized, cross-account and unauthorized requests fail closed. Private operator reference/rights evidence is not projected to customers.

Behavioral references, route/document schemas and precise owner inputs are in `docs/engineering/customer-personalization-editor.md`. Existing original-source categories, missing licensed fixtures/reference mattes, artistic/supplier certification, finance deferral and release blockers are preserved. The shortcode is implemented; no production page was published. Overall acceptance remains INCOMPLETE.

## Reusable product mockup integration

Product mockup infrastructure now registers immutable bounded PNG assets, verifies rights/license-document hashes and independent review, binds versioned recipes to exact current supplier/template/variant/panel evidence, and projects the approved canonical artwork using supplied UV16/coverage/lighting maps. Rejected recipe corrections retain history while advancing separately from active approvals. Legacy owner declarations cannot bypass the reviewed rights contract; synthetic previews are restricted to local offline test orders. Variant/color presentation and stale-approval behavior are regression-tested.

The owner confirmed no verified licensed mockup assets or approved placement maps are available. Socks, mugs, shirts and pajamas are supported as local recipe families; every real product preview remains evidence-blocked. See `docs/engineering/product-mockup-integration.md` for exact inputs, routes and source/test references. Original source categories, commercial/pilot missing statuses, finance/release deferrals and overall INCOMPLETE posture are preserved. No production activation or external operations occurred.

## Persistent acceptance matrix — local integration, 2026-10-12

The persistent matrix is `docs/engineering/original-source/distinct-nonfinance-acceptance.tsv`. Its original 45 derived IDs, bounded requirement text and original classifications are retained; source locators are extended by precise matching anchors. Three distinct obligations have been added after targeted source review, bringing the matrix to 48 rows. Added columns distinguish candidate software, pinned main, requirement scope, remaining classifications and current evidence limits. The classification vocabulary is IMPLEMENTED, MISSING, DEFECTIVE, DEFERRED, EXTERNAL, OWNER APPROVAL and UNVERIFIED. IMPLEMENTED describes a selected software boundary with valid implementation/test references; it does not certify its external or commercial remainder. No demonstrated behavioral defect is inferred from missing acceptance evidence.

Candidate matrix counts: 42 IMPLEMENTED bounded local requirements, three DEFERRED rows (finance, future providers and installed backup/restore), one EXTERNAL row (authentic webhook configuration), and two UNVERIFIED rows (integrated digital QA artifact coverage and final release/installed artifact). The selected mandatory local software denominator is 43, giving 42/43 = **97.67% implementation/reference coverage for this bounded ledger only**. This is not the mandatory original-spec completion percentage and is not a newly executed criterion-specific acceptance run.

**Mandatory original-spec completion: UNVERIFIED; percentage unavailable; overall final-product acceptance INCOMPLETE.** The exhaustive source inventory has 1,771 records: 706 Blueprint anchors and 1,065 workbook physical rows across six sheets. Those records contain headings, context, planning, repeated catalog observations and requirement fragments; they are not 1,771 distinct requirements. The 48-row matrix references 285 unique Blueprint anchors, including all 16 section 32 acceptance clauses and all section 26 named priority requirements. The existing partial requirement-links ledger covers 424 anchors, including 163 context/planning entries and 261 requirement prose/components, and does not establish an exhaustive workbook requirement mapping. No reviewed exhaustive distinct mandatory requirement denominator is present. Neither 42/42 nor any source-record ratio may be presented as full-product completion.

Pinned main is `844107ab9baf7e1303cd47708e9eab3bc700b348`. Main contains 35/43 listed local implementation paths and nine/43 complete row-specific behavioral reference sets; 34/43 candidate behavioral reference sets are absent from that main tree. All 43 main local acceptance rows remain UNVERIFIED here because this check compares committed references rather than executing main criterion-specific acceptance. A missing candidate-specific main reference does not alone establish a missing feature. The local unmerged candidate includes the earlier audited non-finance work and current authorized integration; no merge or deployment is represented by these counts.

Current five-engine status supersedes the historical “missing approved specification” wording in the preserved matrix: the v1.2 index records owner approval of numerical thresholds, review procedure and sample sizes. Executable style/model/reference evidence, licensed real fixtures, designated reviewer signoffs, provider/supplier certification and separate live authority remain outstanding. The original raw source ledgers and their historical categories remain unchanged. Finance PR #1147 and installed backup/recovery certification remain owner-deferred. Authentic provider/order/webhook evidence and deployment/operator certification remain external evidence; owner designations, approvals and release authorization remain separate gates.

`python3 bin/check-final-acceptance-matrix.py` validates IDs, source provenance, classification vocabulary and actual candidate references, compares references against pinned main, and explicitly emits null for the full original-spec denominator and completion percentage. Its offline regressions reject fabricated source locators, duplicate derived IDs, invented behavioral methods and inherited unmerged evidence. It reads committed indexes without reopening the originals or regenerating inventories. Exact final candidate audit, full original-source mandatory requirement census and authentic operational acceptance remain outstanding; this document makes no hosted-CI or production certification claim for the local integration.

Targeted sections 25/26/32 review distinguishes repeated acceptance targets from new obligations. Section 26 named P0/P1/P2 requirements already match existing bounded rows; matching section 25 next-acceptance targets and previously unlinked section 32 clauses now have explicit source links. Section 25 current-state checkpoints and table labels remain historical/context, not fresh software evidence. Digital QA coverage is a distinct local acceptance obligation from p77 and table23/r6/c3: persisted QA state summaries exist (`tests/unit/DigitalQaAdminSummaryTest.php:7::testSummarizesOnlyPersistedValidCheckStates`), and separate QA gate tests exist, but integrated evidence for every supported artifact type and portal presentation is UNVERIFIED. This evidence gap is locally actionable through a reviewed artifact-type acceptance census and focused behavioral verification; no missing implementation is asserted. Installed backup/restore (p239) is explicitly owner-deferred; exact-head release audit plus installed certified artifact (p240) remains UNVERIFIED/external/owner-authorized. Follow-on code inspection found a concrete bounded portal defect: the generic first-eight-field table projection hid ninth-column stored download QA details. The strict non-secret summary fix and actual TXT/PNG QA-to-portal tests close that visibility defect; broader artifact-type and semantic/IP acceptance remains UNVERIFIED. Original-spec mandatory coverage outside this subset remains unverified, rather than being attributed wholesale to owner inputs.

Local integration verification reported by the root validator: full unit suite 1,298 tests / 7,741 assertions (one optional skip); local WordPress suite 468 tests / 5,952 assertions (two optional skips), plus production-profile 16 tests / 242 assertions. Subsequent portal affected tests passed: eight tests / 612 assertions, adding three portal regressions. The current offline full suite passed 114 tests with the configured PHP and image-tool environment, including all six matrix regressions and the scoped partial-link compatibility checks. Browser verification passed 228 portal cases plus three editor cases. The final isolated serial optional real SAM boundary rerun ran six tests / 19 assertions and still failed the strict 18-second time bound during repeat inference; some first-inference assertions passed. This remains a runtime blocker and no limits were changed. Root also reports static/standards checks passed and the actual Composer audit returned zero advisories. These are local test results, not a final exact-head hosted audit or installed production certification.

Pinned-main comparison now checks Git object availability first: shallow checkouts lacking the pinned revision report UNAVAILABLE with null reference counts, never false missing implementation or zero coverage. The matrix regression explicitly checks this distinction. Bounded TXT/PNG evidence is recorded in `tests/wordpress/PortalArtifactCoverageAcceptanceTest.php:96`, with secret/XSS and unavailable/unauthorized cases at lines 119 and 144. It supports the visibility fix without marking universal QA coverage complete.
