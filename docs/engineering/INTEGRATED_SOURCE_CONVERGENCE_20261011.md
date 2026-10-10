# Integrated original-source convergence — 2026-10-11

Baseline main `844107ab9baf7e1303cd47708e9eab3bc700b348` was fetched and independently matched by remote SHA. Issue #959 and original sources were reconciled. This candidate integrates reviewed code without changing PRs #1148–#1152 or the deferred finance PR #1147.

## Dependencies and exact heads

| PR | Exact head | Engineering & Safety Audit run | Relationship |
|---|---|---|---|
| #1148 | b8316939a30790035a4dd00b1971a01b78545ccd | 38074062339 SUCCESS | Mixed catalog lineage |
| #1149 | 28d675a67509ce6b841dce3bfe808b02d058521e | 38074091709 SUCCESS | Offline validation receipt |
| #1150 | 2cf37252355342df14dae45715357e4237dd29f0 | 38075888498 SUCCESS | Includes #1148/#1149, adds six-sheet source receipt |
| #1151 | fbb3552cb748b65d38112184f78edcb1fa6f38ab | 38076307910 SUCCESS | Geometry preservation/drift |
| #1152 | 660a8381914f4ea532d90ca123c0806a69081714 | 38076345676 SUCCESS | Atomic AI quantity reservation |
| #1153 | 5545e69921bc1d5eb39e4b65ec525d3b7001128c | 38077737123 SUCCESS | Includes #1152; shop/cycle template cap |
| #1154 | a224f3b2219555333193e83347865a1436a459d3 | 38078216555 SUCCESS | Independent research ownership |
| #1156 | 2bd226ae569058cb952fde766355b9e9a0dae9af | 38078892070 SUCCESS | Immutable creation replay lineage |
| #1157 | d79a9e87b06f7c924a73198447b1bd2a75267e25 | Final exact-head audit required | Mobile grid containment / control size |

All nine changes are combined here; original commit identities are preserved in this ledger although cherry-picks have new identities. Do not merge overlapping PRs blindly: #1150 contains #1148/#1149, and #1153 contains #1152. The final integrated head requires its own successful audit, regardless of component audits. Existing individual PRs stay open for review; none was merged or closed by this task.

The integration-specific test fixture change supplies an explicit owned shop and template-cap policy for the geometry drift scenario. It does not add or activate a production policy. It reconciles the geometry and cap contracts rather than bypassing the cap.

## Original-source coverage and evidence limits

`original-source/blueprint-reconciliation.tsv` preserves all 706 nonempty original anchors across all 36 sections and front matter, with exact text/coordinates, implementation/test locators and explicit partial/deferred boundaries. References at section level are not sentence-level proof. `section-reconciliation.md` summarizes evidence and known gaps. `workbook-reconciliation.tsv` covers all 1,065 physical rows across all six sheets, including blanks; `workbook-original-complete.json` retains every value, all 14 original formulas and cached values. Original DG/DG2 IDs, classifications, KEEP/MERGE/DOWNGRADE and overlay/source decisions remain unchanged.

This is a complete source inventory/trace ledger, **not complete requirement acceptance**. The cap's specific requirement has local behavioral acceptance; unrelated requirements do not inherit that result. Source normalization gives `STRUCTURAL_PASS_ONLY`, with `production_authority=false` and `promotion_authorized=false`.

Independent review required two evidence corrections: POD lifecycle source-string checks do not establish immutable ProductFactory version behavior; directly inserted approved fixtures and synthetic preview hashes verify persisted evidence review/authorization boundaries, not artwork rendering or buyer-preview parity. Those broader acceptances remain open. The additional #1156 behavioral regression now proves exact canonical creation replay/parent lineage, but does not certify every ProductFactory version/asset requirement.

## Integrated local verification

- Legacy suites, 1,164 PHP unit tests / 7,379 assertions, configured PHPStan and PHPCS passed.
- 226 WordPress integration tests / 2,004 assertions passed on a new disposable database. Expected database fault injection and baseline PHP deprecations/warnings remain visible.
- All 71 offline catalog/recovery regression tests passed.
- Composer dependency installation succeeded; live security advisory audit returned empty advisories and abandoned-package lists. No dependency change is included.
- The actual original workbook passed the six-sheet offline structural validator without modification; original hash and governed digest match the source inventory.
- PHP syntax (951 files before the additional test, plus both changed replay files), diff whitespace and CI-equivalent per-line hard-coded secret-pattern checks passed; final exact-head GitHub audit independently reruns configured checks.

Representative Chromium coverage additionally passed four locally rendered WordPress views at390/768/1280px (12 combinations), checking overflow, navigation/control heights and field/keyboard interaction. All browser requests and submissions were blocked; zero network requests occurred. These tests cover local WordPress persistence, portal forms/projections, quantity/cap locks and replay boundaries, ownership isolation, catalog receipts and offline safety contracts. They do not certify every portal workflow/operator walkthrough or deployed-theme compatibility, live provider execution, production installation or backup restoration.

## Remaining blockers and next acceptance

1. Software: authoritative actual AI cost settlement and complete provider/model/task/shop/product/order attribution. A reservation with zero cost is not actual-spend evidence. Monetary-budget generation remains fail closed; no fabricated prices/invoices or relaxed budget gate.
2. Evidence: immutable ProductFactory version behavior, comprehensive downstream four-shop lineage, actual rendering/parity and complete portal operator/server/deployed-theme operations need requirement-specific behavioral acceptance. Representative mobile layout and field interaction is now covered, without implying all-route acceptance. A source-string test is insufficient.
3. Operations: current provider-authoritative geometry/economics/samples, live Etsy/POD/webhook certification, backup/restore and installation of the newly certified artifact remain deferred. Finance is separately excluded, not certified here.
4. Governance: latest branch-protection read returned HTTP403 `Resource not accessible by integration` (an earlier attempt returned HTTP401). Protected merge authority cannot be independently established; no merge or bypass is performed.
5. Environment: independently identifying the latest published environment revision remains unresolved. No overall environment-certification PASS is asserted.

STOP ALL ON, external safety lock ON and automation OFF remain required. No runtime settings were changed. No finance/recovery workspace change, production deployment, Etsy/POD external operation, unauthorized Master 500 promotion, CI/security reduction or release-governance change occurred. Attached-source execution instructions remain subordinate to the user's current authorization.
