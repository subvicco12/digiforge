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
Merging this release means DigiForge is code-complete, tested, packaged, documented and ready for controlled deployment. It does not authorize enabling integrations or external automation. Separate explicit activation authorization is required later.


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
