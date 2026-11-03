# Owner-directed acceptance contract definitions — engineering only

Base: #1201, `2e934ebfe93c4f27a47aa482a8c9365145e2b7cf`, audit #4733 PASS. Its head was verified unchanged. The original-source 268-criterion ledger and existing Blueprint/Master 500, operating-manual and DF-OWN evidence are reused. The owner's 2026-10-18 approval covers principles and engineering direction, not concrete thresholds, formulas, supplier evidence or production authority.

## Implemented API and reuse

`includes/Portal/ReviewedAcceptanceContract.php` adds typed schema v1 declarations to the existing signed `ReviewedLocalEvidence` policy store. This is not another authorization/review store. No migration, shared REST/controller/portal edits, Finance changes or Codex A transport-file changes. The internal API exposes `propose`, `decide`, `current` and exact reviewed `matchesText`. No operator UI or execution-route activation is added in this batch.

Keys are namespaced by schema, criterion and caller-declared scope; the existing review stream additionally binds the shop. Only Digital and Personalized POD can hold these declarations; POD seam/text criteria are not Digital fulfillment dependencies. Shops 3/4 remain denied. Existing assigned writer/reviewer authority, separate actors, revisions, replay protection, effective dates, expiry, revocation, signed history and atomic audit/state persistence remain mandatory. Typed validation is repeated on approval and read, so generic policy payloads cannot borrow this namespace.

All current contracts explicitly return evidence UNVERIFIED and metric value UNKNOWN, with supplier approval, publishing, AI execution and external execution flags false. Reference hashes are declarations, never proof of source contents, rights, ownership or supplier/artistic acceptance. The service does not open files, fetch URLs, query provider data, calculate scores or infer zero. It does not replace existing artifact, budget, certification or admission gates.

## Ten source criteria and concrete unresolved decisions

| Criteria | Implemented declaration | Still required; not invented |
| --- | --- | --- |
| BP-seam-panel-render | Nonempty bounded shop-bound fixture manifest, geometry reference and seam/panel/bleed review checklist | Exact approved native panels, continuity/bleed expectations, actual licensed fixtures, review decisions and supplier geometry. No numeric tolerances assumed. |
| BP-template-qa-spelling | Locale, approved text digest, spelling-review reference; exact UTF-8 text/locale parity against a current independently reviewed contract | Actual approved text, locales, permitted exceptions and human linguistic review. Matching a digest is not spelling certification. No dictionary or autocorrection invented. |
| BP-ai-value-routing | Named measure/unit, baseline/candidate/benefit-review/decision-rule references and explicit ORIGINAL or DETERMINISTIC fallback | Concrete measurable-benefit definition, decision rule and reviewed measured records. No threshold, comparison formula, automatic AI selection or live-call permission supplied by this approval. |
| BP-portal-7-supplier-readiness; BP-portal-17-conversion; BP-portal-17-supplier-performance; BP-portal-17-product-performance; BP-portal-17-shop-performance | Defined numerator, denominator, population, attribution, workflow, absolute bounded period and shop-bound source-record references; optional explicit formula reference | Actual definitions and source records, join/eligibility/window rules and separately reviewed formula where a rate/score is desired. No conversion rate or supplier score calculated. Missing formula stays null and value UNKNOWN. Even a supplied formula reference does not certify a measurement. |
| BP-portal-17-defect-quality; BP-quality-feedback | Same attributed-period/population contract plus required severity-policy reference | Approved severity taxonomy, workflow/product/order/shop attribution, reviewed-population denominator rules, actual defect records and any scoring formula. No severity labels/weights invented. |

Metric source lists reject duplicates, foreign shops, invalid digests, unsafe/nonlocal reference syntax and excess entries. Definitions reject missing/extra fields and wrong types. Absolute timestamps reject timezone-less, invalid calendar and nonpositive periods. These are technical schema/resource bounds, not business thresholds.

The next owner action is to supply concrete definition/evidence packets for independent review. Coordinator-owned REST/portal consumption and runtime metric/AI decision integration must be assessed against those approved values, not silently assumed complete. Real referenced-record verification remains required before any certification. No existing AI routing decision or metric projection is retroactively certified by this registry.

## Validation and acceptance

The new test suite first failed because the contract class was absent. Focused WordPress **42 tests / 299 assertions PASS** covers all ten contract schemas plus independent lifecycle, foreign/deferred shops, malformed periods/references, text/locale mismatch, replay, revoked/future reviews and generic-policy namespace bypass. Configured review/controller/portal tests are reused. Related unit tests **72 / 410 PASS**; an initially unconfigured optional offline processor was resolved using the existing isolated runtime, without changing tests. Additional WordPress-aware level-5 static analysis and source acceptance-reference validation PASS. Full exact-head hosted evidence belongs in the draft PR handoff to avoid changing an audited head solely to record its audit.

All ten criteria remain UNVERIFIED. The persistent matrix remains **246/268 implemented candidates and 22 UNVERIFIED**; approved principles do not establish final acceptance. No authoritative source IDs, formulas or catalog migration decisions changed. The coordinator-owned ledger and existing PRs are untouched.

STOP ALL ON; external safety lock ON; automation OFF; release hold ACTIVE. Synthetic local fixtures and reviews only. No real credentials, provider calls, production/staging writes, Finance changes, publishing, orders, activation, merges or deployments. Production impact: NONE.
