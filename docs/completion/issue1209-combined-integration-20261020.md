# Issue #1209 — combined offline integration acceptance

## Exact sources and composition

Verified main: `844107ab9baf7e1303cd47708e9eab3bc700b348`. Verified unchanged draft sources: #1208 `2a234de16cd9dfb9573f16d51bafb44dfdfd5f50` / audit #4740 PASS; #1210 `90128fefa15b3c54afc0e734fa78917db621b23d` / #4741 PASS; #1211 `be12e901821e213bda15372f2e1de1c75c979b12` / #4743 PASS. #1210 and #1211 share #1208 as their merge base.

This isolated candidate is based on #1210 and applies the exact #1208→#1211 patch, then enhances only the composed acceptance test and evidence references. Both patch orders were conflict-free. #1211's code is included by patch provenance; its commit is not misrepresented as an ancestor. No source PR/branch or main is modified or merged. Source runtime code is reused unchanged: FC01 source-offset bounds from #1210, separate draft planner from #1211. No new API, table, adapter, migration, renderer or production binding.

Root AGENTS.md is absent. Available manual/agent/execution-ledger snapshots remain proposed until adopted. The actual original-source ledger and owner-approved contracts govern; the task authorizes offline draft engineering, not real ownership, approval or release.

## Refreshed synthetic journey

`tests/wordpress/FC01MultiProductDraftJourneyTest.php` now exercises six complete cases: populated established, absent, empty, Unicode whitespace-only, exact nonblank surrounding spaces, and missing native-font evidence. All source PNG hashes are derived from actual deterministic renderer bytes for each case, rather than assuming the original sample's hash.

The journey verifies:

- Exact accented family text; complete optional-line omission; unchanged single-space prefix plus exact nonblank input; deterministic retries. Existing FC01 tests cover the 48/49-code-point and unsupported-glyph boundaries and v1 immutability.
- Missing and pending independent reviews yield zero matching packet sections; self-approval is denied. After valid synthetic independent completeness review, eight declarations match, or seven when native-font evidence is missing. Native compatibility remains UNKNOWN and rights/linguistic approval false in both cases.
- An altered foreign-shop connection context is denied. Existing revocation invalidates packet inspection and connection authorization. Runtime catalog/order/production boundaries deny execution under the unchanged locks; zero HTTP attempts occur.
- One design creates two separate per-product **local plans**, with selected template/variant/placement/download metadata, unique SKU/version references, exact questions-based personalization structure and required family/optional established fields. Legacy scalar personalization fields are absent; unsupported input rejects. This tests the repository's current typed Etsy question contract, not live Etsy API acceptance.
- Unresolved mockup rights/ownership remain UNKNOWN; no listing package is persisted or approved. A draft plan cannot borrow packet completeness review as execution authority, even before revocation. Actual template/download/mockup references remain declarations, not licensed files or supplier evidence.

## Focused verification

- Native WP selection (packet, timestamp, concept, composed journey): **43 tests / 1,055 assertions PASS**.
- Unit selection (multi-product draft and reused template geometry tests): **36 / 71 PASS**.
- Targeted WordPress-aware level-5 static analysis of both runtime classes, baseline PHPStan, PHPCS and diff check PASS.
- Read-only `bin/check-final-acceptance-matrix.py` PASS: 1,771 source records, 376 classified rows, **268 distinct atomic mandatory non-Finance criteria**. Source rows are not distinct requirements. Candidate categories remain **246 IMPLEMENTED / 22 UNVERIFIED**, with 10 owner, 7 external and 5 composite unresolved classifications. This is not verified-main or final-product acceptance.
- Four existing TSV rows gain precise composed-test references and a synthetic-only scope note: BP-concept-template-separation, BP-template-required-inputs, BP-template-qa-spelling, BP-printify-shop-scope. No status/category is changed, including BP-template-qa-spelling UNVERIFIED. Finance rows are not edited.

The consolidated published head requires its own exact-head Engineering & Safety Audit; final run/head evidence belongs in the draft PR and issue #1209 after the audit completes. Earlier source-head audits do not certify this new candidate.

## Acceptance reconciliation

| Issue #1209 gate | Local proof | Authentic/release status |
| --- | --- | --- |
| 1 Ownership, selected variant/geometry | VERIFIED synthetic scope, tuple consistency and revocation checks | BLOCKED: authentic environment/account/shop/integration and exact supplier geometry absent |
| 2 Native typography and field semantics | VERIFIED packet fail-closed declarations and deterministic local input rules | BLOCKED: native optional omission, Unicode counting, overflow, font rights and linguistic review absent |
| 3 Preview/final production parity | VERIFIED deterministic local PNG byte parity and mismatch guards | UNKNOWN: no authentic provider production-artifact parity |
| 4 Separate Etsy drafts | VERIFIED two separate non-executable plans and questions-based schema | UNKNOWN: real product/version lineage, taxonomy and native field mapping not authenticated here; no persisted/remote drafts |
| 5 Download identity/use | VERIFIED declared template/variant/placement consistency | UNKNOWN: real template file, usage rights and download mechanism |
| 6 Mockups/quality/fulfillment | VERIFIED no authority from unresolved rights declarations | BLOCKED: commercial rights, independent quality and production approvals absent |
| 7 Audit/site acceptance | Focused checks VERIFIED; candidate exact-head hosted audit required | BLOCKED: installed-site acceptance remains absent even after CI passes |
| 8 Safety/release hold | VERIFIED isolated lock/zero-network behavior; source controls unchanged | Production state not observed; no activation or release authority inferred |

VERIFIED here is narrowly scoped executed local behavior. None of these rows establishes full Blueprint acceptance. Existing global classifications and full-product INCOMPLETE state are preserved.

## Minimum next evidence / owner action

Supply read-only evidence for an existing Printify product: exact shop/product/provider/blueprint/variant/placement, native font/layer and Etsy field settings, optional-line/Unicode/overflow findings, available exact preview/final artifacts and input provenance, template-download metadata and applicable rights, plus appropriately scoped independent reviews. Missing items stay MISSING/UNKNOWN. Do not create/edit/save/publish products, call preview/configuration POSTs, place orders or activate integrations to collect evidence under this task. Installed-site and production approvals require separately authorized future work. Source draft PRs remain unmerged; any eventual release needs its own governance evidence.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. Shops 3/4 deferred. Finance excluded. No real credentials, live provider calls/mutations, production/staging writes, publishing, orders, merges or deployments. FC01 v1/v2 art, font assets, retained Option A layout and production bindings unchanged. en-US remains proposed; no linguistic, rights or acceptance certification.
