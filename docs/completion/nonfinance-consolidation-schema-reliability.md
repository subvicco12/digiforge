# Non-finance consolidation: startup schema reliability

## Verified integration baseline

Main `844107ab9baf7e1303cd47708e9eab3bc700b348` was fetched. The open draft dependency chain and its successful exact-head audits were reverified:

| Draft | Exact head | Existing audit |
| --- | --- | --- |
| #1194 | `135a3dc2bfd6602dc620ca6ea79f1072c4c3f024` | #4721 PASS |
| #1195 | `02a67a61b7247457546a4530d26ca971d3645ec5` | #4725 PASS |
| #1196 | `9fab24a5ecd0538ea18e70cde3b79b6151643eab` | #4726 PASS |
| #1197 | `d381d0a10251c89caa37c069d069f4aa892a1d08` | #4727 PASS |

Each is an ancestor of the next. Read-only `git merge-tree --write-tree` simulations of main and #1197 in both orders produced #1197's exact tree `c1aba5cb0b649e2db787501a6c72c58cf5d2608e`; no conflict resolution or loss of either candidate was necessary. This branch extends #1197; earlier branches, Finance, transport authorization, CI and release hold are unchanged. Dependency order remains #1194 → #1195 → #1196 → #1197 → this candidate. Do not independently integrate divergent earlier source branches.

## Confirmed defect and repair

The prior authenticated browser evidence recorded 255 startup ALTERs across its requests. A native regression reproduced five identical `dbDelta` ALTERs on every POD reconciliation: template version, blueprint/provider/creator IDs, and target DPI. Their SQL types omitted integer display widths, while MariaDB reports `int(10)`, `bigint(20)` and `smallint(5)`; WordPress interpreted the mismatch as drift on every boot.

`includes/Database/PodSchema.php` now specifies those widths. Integer storage ranges, signedness, defaults, global version handling, outcome-claim backfill, capabilities and failure handling are unchanged. No marker/cache skips reconciliation: real column drift still invokes repair and failed SQL still denies success, with a subsequent clean retry recovering.

`tests/wordpress/PodSchemaReconciliationTest.php` covers repeated no-ALTER reconciliation, stored draft-row/global-version preservation, actual type-drift repair and SQL-failure/recovery. Its first test failed with all five ALTERs before the fix. Focused native migration/lifecycle verification passes **43 tests / 407 assertions**. Existing negative race tests emit expected duplicate-key diagnostics; PHPUnit reports no failures.

The unchanged authenticated native browser harness passes **64 checks / 194 requests** at 390/1280 widths: **zero startup DDL** (previously 255), zero external requests, zero business-row mutations, all 44 snapshotted non-finance business tables unchanged. Sanitized output is in `pod-schema-reconciliation-browser-evidence.json`. This measures statement elimination, not a claimed latency speedup or production migration certification. Local Chromium evidence is separate from hosted CI, which does not run Playwright. Existing consent branch-coverage limitations remain as documented in `authenticated-local-browser-acceptance.md`.

## Remaining 22 criteria: no synthetic promotion

The persistent source-linked TSV remains authoritative. It has 268 distinct atomic mandatory non-finance criteria, **246 IMPLEMENTED candidate / 22 UNVERIFIED (91.79% implementation coverage)**. Source records are not distinct requirements, and candidate coverage is not verified-on-main or overall acceptance. The schema fix strengthens an existing aggregate boundary; it closes no additional atomic criterion.

| Category | Exact criteria | Smallest missing input / next action |
| --- | --- | --- |
| Owner approval (3) | BP-seam-panel-render; BP-ai-value-routing; BP-template-qa-spelling | Approve panel continuity expectations, objective added-value selection rules, and actual buyer-text/locale review evidence respectively. Existing generic parity cannot establish these judgments. |
| Owner approval (1) | BP-portal-4-provider-connections | Approve the environment-bound integration/provider-shop ownership tuple; global connector metadata remains insufficient. No binding activation follows. |
| Owner approval (7) | BP-portal-7-supplier-readiness; BP-portal-17-conversion; BP-portal-17-defect-quality; BP-portal-17-supplier-performance; BP-portal-17-product-performance; BP-portal-17-shop-performance; BP-quality-feedback | Supply reviewed metric definitions, defect taxonomy, denominators and attribution/provenance rules. Then assess only the concrete implementation delta; do not invent scores or replace UNKNOWN with zero. |
| External (2) | BP-distinct-provider-shops; BP-etsy-shop-identity | Authentic approved provider-shop identity/current Etsy app ownership evidence. Standard POD/Jewelry remain deferred, not implicitly approved. |
| External (1) | BP-secret-no-chat | Continued operator handling evidence; technical tests cannot certify all future disclosure behavior. Never submit credentials in chat. |
| External (4) | BP-digital-semantic-content; BP-digital-semantic-visual; BP-digital-semantic-ip; BP-digital-semantic-policy | Authorized content/art/IP/policy review of actual artifacts; existing technical gates do not manufacture semantic approval. |
| Composite (1) | BP-printify-shop-scope | Authentic environment/account/Etsy/Printify-store/integration tuple and approved binding, then separately authorized verification. Dashboard-supported store 28930593 is not API ownership certification. |
| Composite (1) | BP-portal-7-mockups | Licensed assets with display/derivative rights plus exact approved variant/panel/UV/seam geometry. Synthetic rejection coverage is already present. |
| Composite (1) | BP-portal-11-production-authorization | Actual separate action/scope/expiry/revocation authority and supplier evidence. Local approval/replay/lock-denial tests do not authorize production. |
| Composite (1) | BP-live-portal-normal-work | Authentic ownership, mockup and production prerequisites plus separately authorized installed-site acceptance. Local authenticated read-only journeys already pass; full normal commercial workflow remains unverified. |

These classifications do not prove that future approved contracts require no engineering; they identify why implementing additional behavior now would invent authority or evidence. No additional independently specified defect was established among these 22 by this reconciliation. Full non-finance acceptance and overall final-product acceptance remain incomplete. Authentic supplier IDs, geometry, shipping and rights remain UNKNOWN; Finance authenticity and installed release evidence are outside this batch.

STOP ALL ON, external lock ON, automation OFF. No provider calls, production/staging writes, activation, orders, publishing, merges or deployment. The final PR must retain draft/release hold and receive one successful exact-head Engineering & Safety Audit before handoff.
