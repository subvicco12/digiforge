# Finance contract and implementation closure

Baseline `623ba4e769a1d746e2ce89216483f40a87c4ad55` / audit #4823. Existing branch `integration/full-product-finance-20261030`. Historical #1147 reconciliation, period-snapshot, audit-atomicity and replay fixes are reused unchanged. The final exact head and hosted audit receipt are recorded in issue #1209 after certification.

## Working implementation added

- **Typed source admission:** the authenticated workbench explicitly selects a provider. Etsy delegates to the pinned eight-schema parser/stage/projection; legacy requests retain Etsy behavior. Printify/Gelato/OpenAI native charge formats return `finance_source_contract_missing`. No undocumented field is parsed, and existing internally reviewed AI statements are not described as official provider-native schemas.
- **AI charge integrity:** six RED cases showed damaged/missing invoice-line claims remained reviewed. Snapshot and replay now revalidate exact usage/shop/provider/currency/response/model/amount/source hash against the original claim. Invalid receipts require reconciliation and are withheld. Evidence is not rewritten; financial authenticity/finality stay UNKNOWN.
- **Deterministic review export:** fresh declaration snapshots produce versioned JSON with exact amounts, scope, calculation version and source hashes, excluding raw native content and document-reference text. A current-source verifier rejects extra fields, tampered bytes and changed/revoked source revisions. Empty, conflicting or truncated evidence cannot become certified zero or a statutory export.
- **Website portal integration:** active-shop, all-four Finance authority, portal capability, nonce and exact bounded fields are enforced. The full authorization gate repeats after reads. Copied textarea JSON round-trips through the verifier; report filters remain in the form action. No new privilege, public sharing or raw-source endpoint.
- **Encrypted-copy inspection:** current local ownership, history, audit and existing key checks authenticate the original before an encrypted copy digest comparison. Output contains hashes only. A match does not verify a complete backup, restored database/audit/claims, key recovery or production custody. Restore, recovery, retention actions, cleanup and erasure remain false; retention/legal-hold policy is UNAPPROVED.

Actual portal capability-revocation and copied-payload failures were reproduced and corrected. Independent review also identified filter retention, now covered by the round-trip test. Existing mutable-query evidence handling is reused; no static suppression or analysis weakening.

No accounting category, tax formula, financial finality, provider identity, retention duration, deletion/cleanup, storage schema, key provisioning or external transport is introduced. The normal portal consumes the package; wp-admin is not required.

## Individual Finance criterion classification

Software labels are bounded local coverage, not acceptance. All original source anchors and detailed implementation/tests remain in `distinct-nonfinance-acceptance.tsv` and the preceding full-product matrix. No row is promoted from reference presence or synthetic evidence.

| Exact criterion | Required subject | Software classification | Remaining dependency | Authentic acceptance |
| --- | --- | --- | --- | --- |
| DERIVED-BP-ai-no-finance-authority | refund authority, tax filing authority or money movement. | LOCAL DENIAL BOUNDARY | Review evidence cannot authorize refunds, filing or money movement. | UNVERIFIED |
| DERIVED-BP-ai-commercial-kpis | Required commercial metrics include AI cost per opportunity researched, per qualified opportunity, per developed product, per approved listing, per sale, and AI cost as a percentage of revenue and gross/contribution profit. | LOCAL CLAIM VALIDATION; RATIOS CONTRACT-BLOCKED | Original invoice-line witness required; authoritative revenue/profit populations and reviewed mapping missing. | UNVERIFIED |
| BP-template-cost | Cost evidence | LOCAL EVIDENCE GATE; RAW SOURCE CONTRACT-BLOCKED | Paid POD native schema and actual charge/shipping records missing; estimates are not settled costs. | UNVERIFIED |
| BP-template-shipping | Shipping evidence | LOCAL EVIDENCE GATE; RAW SOURCE CONTRACT-BLOCKED | Paid POD native schema and actual charge/shipping records missing; estimates are not settled costs. | UNVERIFIED |
| BP-template-margin | Margin evidence | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-portal-6-profitability | Live-site portal supports profitability. | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-portal-16-ledger | Live-site portal supports ledger. | PARTIAL; ACCOUNTING CONTRACT-BLOCKED | Pinned Etsy native schemas supported; other native charge profiles denied; category/finality mapping missing. | UNVERIFIED |
| BP-portal-16-profitability | Live-site portal supports profitability. | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-portal-16-tax-readiness | Live-site portal supports tax readiness. | LOCAL REVIEW EXPORT; TAX POLICY-BLOCKED | Current versioned review package/verifier implemented; jurisdiction/version/calculation provenance remains unapproved. | UNVERIFIED |
| BP-portal-16-unresolved-reconciliation | Live-site portal supports unresolved reconciliation. | LOCAL EXCEPTION PROJECTION | Conflicts/missing sources block; broken AI witnesses demote and redact without rewriting history. | UNVERIFIED |
| BP-portal-16-no-automatic-filing | Live-site portal supports no automatic filing. | LOCAL DENIAL BOUNDARY | Review evidence cannot authorize refunds, filing or money movement. | UNVERIFIED |
| BP-portal-17-revenue | Live-site portal supports revenue. | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-portal-17-margin | Live-site portal supports margin. | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-finance-148 | Canonical finance should normalize revenue, refunds/reserves, marketplace fees, COGS, shipping, ads, AI variable cost, tax and adjustments. Profitability must be available by order, product, listing, shop and business. Product/listing/production gates may require margin evidence. | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-finance-149 | GST/tax automation remains evidence/preparation-only until separately authorized. Filing, money movement or irreversible tax actions require a separate explicit authorization boundary. | LOCAL DENIAL BOUNDARY | Review evidence cannot authorize refunds, filing or money movement. | UNVERIFIED |
| BP-finance-170 | Operational evidence → normalized ledger entries → FX evidence → revenue/refund/fee/COGS/shipping/ads/AI/tax classification → contribution/operating profit → profitability gates → period review → analytics/reporting/GST evidence. | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-process-80-8 | cost/shipping/margin | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-process-80-19 | finance/support/quality feedback | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-process-164-9 | profitability | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-process-166-21 | finance/support/quality analytics | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-process-168-14 | finance/supplier-quality feedback | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-digital-semantic-commercial | Commercial QA | LOCAL GATE; OWNER/RIGHTS EVIDENCE-BLOCKED | Exact-file commercial/content/rights review and authentic economics remain separate. | UNVERIFIED |
| NF-template-commercial-economics | Provider switching revalidates actual commercial economics | LOCAL GATE; SUPPLIER EVIDENCE-BLOCKED | Provider changes cannot inherit economics certification; exact actual evidence required. | UNVERIFIED |

## Precise remaining inputs

D01: verified seller/account/shop/integration ownership, including the POD Etsy seller relationship. D02: production custody/readership/privacy/key-recovery and retention/legal-hold/erasure policy; an encrypted-copy digest is insufficient. D03/D04: original correction chains plus reviewed category/sign/divisor/precision/FX/attribution/completeness/finality rules; payment and ledger views cannot be counted as separate revenue. D05: original Etsy population evidence, exact paid POD native schemas and actual charges. D06: actual AI billed-account/charge/finality evidence; aggregate usage and internal review are not settled-billing certification.

Tax jurisdiction/version applicability, statutory export schema and professional review remain unapproved. Review JSON explicitly records missing policy/calculation provenance, no tax calculation, no filing, no period approval and no certified profitability. This is a functioning review package, not a statutory report.

No independently justified native parser can be completed without its authoritative schema. No certified recognition can be completed without reviewed mapping and source-population/finality contracts. The admission boundaries reject these cases instead of fabricating parsers or accounting observations.

All 500 identities, 20 review waves and existing approvals are preserved. Non-Finance remains 246 implementation candidates /268, 22 UNVERIFIED; all 23 Finance-dependent criteria remain UNVERIFIED. Desktop-only work remains UNVERIFIED.

## Verification

The corrected fixture passed 50 focused Finance/authorization tests /860 assertions. Runtime and native-browser source hashes are unchanged; the corrected-head full suite is certified by the new hosted run. Hosted audit #4824 failed three new portal fixture setups on MariaDB 10.11 because temporary audit tables are not a valid replacement for the independently verified real audit store. The fixture now keeps that real InnoDB table visible, verifies its metadata, and clones temporary financial tables with database-assigned IDs above the existing numeric financial audit identities. No storage check or transaction rule is relaxed. The failed audit remains historical evidence; final certification is on the corrected head.

Local full WordPress before the fixture-only metadata correction: 1,554 tests /26,769 assertions (two existing skips); catalog: 54 /971. Unit: 1,842 /9,984 (one existing skip), legacy/static/standards passed. Finance/AI integrated selection: 297 /4,847. Offline: 194 passed; security regressions: nine passed. All six changed runtime files pass PHPStan level 5 without suppressions. Printify offline smoke suites passed. The native authenticated browser result below is separate from the hosted synthetic fixture checks.

Native authenticated local browser: 221 checks /450 same-origin requests /zero external requests, 390/768/1280, 47 business tables unchanged and zero business-row mutations. WordPress 7.1.3 and Chromium version are recorded in `evidence/finance-contract-local-browser.json`, together with exact tested source hashes. This is actual loopback login/cookie/nonce/form acceptance, not installed-site certification. Existing private fixture cleanup was used for failed prefixes; no production retention/deletion capability was added.

The browser exposed mobile overflow from the export checksum outside a panel; reusing the existing wrapping panel fixed it without changing bytes. Fixture client/router guards now admit only the two specific new read-only finance actions on the existing scoped finance page. Other routes/mutations remain denied.

The full suite exposed an audit-removal fault that used WordPress's implicit integer format for a string `object_id`. Explicit string formats and exact deletion/absence assertions make the correction-chain test deterministic; custody validation is not bypassed or relaxed.


Executed local totals and final hosted browser, Action Scheduler, security, static/dependency and package evidence are attached to the exact-head issue receipt. Previous audits do not certify changed code. RED cases and intermediate fixture diagnostics are retained privately. Source-only review is distinct from tests. No rule, assertion or safety check is weakened.

Full-product engineering acceptance: NO-GO for missing qualifying contracts/evidence. Operational/commercial release: NO-GO. STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No real imports/transactions/financial approvals, production writes, filing, payment movement, provider mutations, publishing, merges, deployment or activation.
