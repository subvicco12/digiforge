# Bounded CSV source inspection and full-product Finance integration

Baseline `8cb4020a365dd1ef6a626717e9a65a5bab589b25` / audit #4827; existing isolated `integration/full-product-finance-20261030`. Final exact SHA and audit are attached to issue #1209 after certification. P01–P08 directions are reused; no duplicate owner approval requested.

## Accessible source evidence

Issue #1209 comments [6295401014](https://github.com/subvicco12/digiforge/issues/1209#issuecomment-6295401014), 6296170984, 6297644815 and 6298335367 establish source collection locations and the existing pinned eight-schema Etsy OpenAPI parser. All 105 available issue comments and repository completion records were checked for the asserted newer Finance dossier. No original authenticated CSV/header/unit definition or paid-invoice schema was available there. Comment6502170339 records limited shop/account corroboration, not paid financial source records or permission to substitute a different Printify store. No newer authenticated raw Finance dossier is claimed acquired. No external supplier research or provider requests were repeated.

## Implemented boundary

`Finance/CsvSourceStage` implements a strictly offline structural inspector, reached through existing `SourceAdmission` and the authenticated Finance source workbench. It is not a provider CSV parser, importer, accounting classifier or durable store.

- Existing exact shop/environment/business/store/external-shop/account/integration/version context checks are reused. These are declared scope assertions, not verified account ownership. Existing native JSON uses the identical context checks and pinned Etsy version.
- CSV mode uses the internal profile `digiforge-finance-csv-structure-v1`; its SHA-256 identifies this syntax contract, never a provider schema. Caller-declared exact ordered headers and delimiter are checked, without inventing real source headers or units.
- At most20 documents,64KiB each,256KiB original bytes total,500 physical data rows total,64 columns and4096 bytes per cell. UTF-8/BOM, doubled quotes, LF/CRLF, quoted embedded newlines and exact whitespace/decimal text are preserved in original hashes and exact parsed-cell digests. Malformed quotes, header/column conflicts, invalid encoding/control bytes, overflow and duplicate original files fail closed. There is no permissive recovery or truncation.
- Nullable declared row counts expose UNKNOWN, declared matches/conflicts and duplicate-value review. Physical rows are not transaction identities or financial activity. A count match, empty file or repeated values cannot prove complete source populations, zero expenses, fees, settled charges or period authority. Formula-like cells are counted conservatively and never rendered, executed or altered.
- Portal status is explicitly STRUCTURE_VALID_PROVIDER_FORMAT_BLOCKED. Inputs are cleared; only hashes/counts/status are displayed. Authentication, nonce, active shop, all-four Finance assignments and post-processing authorization remain mandatory. Unsupported native formats remain blocked.
- Existing encrypted custody still accepts only its pinned native contracts. No new raw-document persistence, table, schema, credential, transport, retention/erasure or accounting mapping is added. All execution, accounting-import, period-approval and durable-replay flags remain false.

Tests first established the missing class/workbench behavior. Development also reproduced a retry fingerprint changing solely with JSON layout-key order; explicit versioned field ordering fixes it while preserving exact field bytes. Source-independent syntax support does not certify any Etsy/Printify/Gelato/AI source format.

## Executable integration and verification

New tests cover strict syntax, row/file/aggregate bounds, exact decimal text, Unicode/whitespace, BOM/newlines/quotes, duplicate files/values, formula markers, future/hash/version/context drift, foreign/deferred/revoked access, final authorization loss, redaction and cleared inputs. Digital and POD scenarios prove CSV inspection cannot create declarations, change provisional profitability, confer completeness or revive revoked reviewed lineage. Existing explicit declarations remain separate synthetic inputs; no CSV column is mapped into accounting. Existing correction, exact FX, allocation, audit persistence and period-denial suites are reused.

Local focused native/parser/CSV unit selection:124 tests/307 assertions. Expanded Finance/custody/allocation/FX/audit WordPress selection:205 tests/3510 assertions, including new Digital/POD cases. Full unit/legacy/configured static/standards:1887 tests/10130 assertions (one existing skip). All four changed runtime files pass supplemental PHPStan5 without suppression. Actual native browser:245 checks,462 same-origin requests, widths390/768/1280, zero external requests/business-row mutations,47 business tables unchanged. This is not installed-site/provider acceptance. An initial login timeout was preserved and the fixture cleaned using the existing guarded disposable procedure; no timeout or auth checks were weakened. Final full WordPress/catalog/hosted audit results are recorded in the issue receipt; passing unchanged earlier audits are not relabelled as current certification.

## Individual Finance acceptance reconciliation

Statuses below are the authoritative ATOMIC/kind census, not the coarse requirement_scope tally. All23 remain UNVERIFIED. Three existing rows gain bounded CSV implementation/test references only.

| Criterion | Current bounded software | Remaining qualifying dependency | Acceptance |
| --- | --- | --- | --- |
| DERIVED-BP-ai-no-finance-authority | LOCAL DENIAL BOUNDARY | Review evidence cannot authorize refunds, filing or money movement. | UNVERIFIED |
| DERIVED-BP-ai-commercial-kpis | LOCAL CLAIM VALIDATION; RATIOS CONTRACT-BLOCKED | Original invoice-line witness required; authoritative revenue/profit populations and reviewed mapping missing. | UNVERIFIED |
| BP-template-cost | LOCAL EVIDENCE GATE; RAW SOURCE CONTRACT-BLOCKED | Paid POD native schema and actual charge/shipping records missing; estimates are not settled costs. | UNVERIFIED |
| BP-template-shipping | LOCAL EVIDENCE GATE; RAW SOURCE CONTRACT-BLOCKED | Paid POD native schema and actual charge/shipping records missing; estimates are not settled costs. | UNVERIFIED |
| BP-template-margin | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-portal-6-profitability | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-portal-16-ledger | PARTIAL; ACCOUNTING CONTRACT-BLOCKED; CSV STRUCTURE INSPECTION ONLY | Pinned Etsy native schemas supported; other native charge profiles denied; category/finality mapping missing. | UNVERIFIED |
| BP-portal-16-profitability | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-portal-16-tax-readiness | LOCAL REVIEW EXPORT; TAX POLICY-BLOCKED | Current versioned review package/verifier implemented; jurisdiction/version/calculation provenance remains unapproved. | UNVERIFIED |
| BP-portal-16-unresolved-reconciliation | LOCAL EXCEPTION PROJECTION | Conflicts/missing sources block; broken AI witnesses demote and redact without rewriting history. | UNVERIFIED |
| BP-portal-16-no-automatic-filing | LOCAL DENIAL BOUNDARY | Review evidence cannot authorize refunds, filing or money movement. | UNVERIFIED |
| BP-portal-17-revenue | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-portal-17-margin | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-finance-148 | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED; CSV STRUCTURE INSPECTION ONLY | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-finance-149 | LOCAL DENIAL BOUNDARY | Review evidence cannot authorize refunds, filing or money movement. | UNVERIFIED |
| BP-finance-170 | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED; CSV STRUCTURE INSPECTION ONLY | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-process-80-8 | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-process-80-19 | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-process-164-9 | LOCAL PROVISIONAL; ACTUALS EVIDENCE-BLOCKED | Explicit declared/subset amounts only; actual fees/costs, complete populations and reviewed mapping required. | UNVERIFIED |
| BP-process-166-21 | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-process-168-14 | PARTIAL; SOURCE/MAPPING CONTRACT-BLOCKED | Existing source-bound declarations/attribution/review supported; authentic recognition, completeness and finality missing. | UNVERIFIED |
| BP-digital-semantic-commercial | LOCAL GATE; OWNER/RIGHTS EVIDENCE-BLOCKED | Exact-file commercial/content/rights review and authentic economics remain separate. | UNVERIFIED |
| NF-template-commercial-economics | LOCAL GATE; SUPPLIER EVIDENCE-BLOCKED | Provider changes cannot inherit economics certification; exact actual evidence required. | UNVERIFIED |

## Individual non-Finance acceptance reconciliation

No new authentic non-Finance evidence was acquired. All22 remain UNVERIFIED. Existing implementation/test references are preserved, not promoted by the Finance tests. Noto Serif text-only fixture licensing evidence is separate from native provider typography, actual artwork rights and independent linguistic approval.

| Criterion | Retained local software scope | Remaining authentic acceptance | Status |
| --- | --- | --- | --- |
| BP-seam-panel-render | IMPLEMENTED exact panel/bleed declarations, reviewed alignment and artifact parity | BLOCKED: exact supplier geometry, licensed actual artifacts and independent alignment review | UNVERIFIED |
| BP-ai-value-routing | IMPLEMENTED deterministic-first/original fallback; AI remains REVIEW_REQUIRED | BLOCKED: resolved measured-benefit rule/permission and authentic findings; no threshold invented | UNVERIFIED |
| BP-template-qa-spelling | IMPLEMENTED exact template/text/locale hashes, code-point/glyph/overflow gates | BLOCKED: licensed fonts, actual native glyph/render behavior and independent linguistic approval; en-US remains proposed | UNVERIFIED |
| BP-printify-shop-scope | IMPLEMENTED scoped independent binding and cross-transport revocation checks | BLOCKED: authentic current environment/account/shop/integration ownership and installed-site proof | UNVERIFIED |
| BP-distinct-provider-shops | IMPLEMENTED foreign/deferred/shared-material denial | BLOCKED / OWNER DEFERRED: distinct approved external Shop3 mapping when onboarding is separately authorized; no implicit authority | UNVERIFIED |
| BP-etsy-shop-identity | IMPLEMENTED OAuth/PKCE and exact verified-shop identity machinery | BLOCKED: authentic current application/OAuth/shop evidence; no token renewal or activation | UNVERIFIED |
| BP-portal-4-provider-connections | IMPLEMENTED scoped read-only status, durable relation and guarded boundaries | BLOCKED: authentic provider ownership and installed connection workflow | UNVERIFIED |
| BP-portal-7-mockups | IMPLEMENTED stored byte/panel/derivative rights validation | BLOCKED: licensed customer-display assets and exact reviewed variant/UV/seam geometry | UNVERIFIED |
| BP-portal-7-supplier-readiness | IMPLEMENTED UNKNOWN/BLOCKED/REVIEW_REQUIRED; declarations cannot manufacture READY | BLOCKED: authentic product/provider/variant/geometry/rights/fulfillment facts and review | UNVERIFIED |
| BP-portal-11-production-authorization | IMPLEMENTED scope/expiry/revocation/replay and independent lock enforcement | BLOCKED: authentic separately granted production authority and supplier/provider evidence; no activation | UNVERIFIED |
| BP-portal-17-conversion | IMPLEMENTED deduplicated attributable orders / eligible visits and matching cohort | BLOCKED: authentic eligible visit/order population and completeness | UNVERIFIED |
| BP-portal-17-defect-quality | IMPLEMENTED confirmed distinct defective units / eligible fulfilled units | BLOCKED: authentic fulfilled population and independently reviewed defects; unknown is not zero | UNVERIFIED |
| BP-portal-17-supplier-performance | IMPLEMENTED separate attributed timeliness/defect/exception metrics | BLOCKED: exact eligibility semantics and authentic observations; no weighted score | UNVERIFIED |
| BP-portal-17-product-performance | IMPLEMENTED version-pinned order/conversion/fulfillment/quality cohorts | BLOCKED: authentic scoped versioned facts and completeness; no inferred profit | UNVERIFIED |
| BP-portal-17-shop-performance | IMPLEMENTED explicit selected-shop aggregation and foreign denial | BLOCKED: authentic scoped observations and installed-site acceptance | UNVERIFIED |
| BP-live-portal-normal-work | IMPLEMENTED bounded local listing/review/reconciliation/policy/connection leaves | BLOCKED composite: authenticated installed-site normal work with authentic ownership, licensed assets and authority; local browser proof is separate | UNVERIFIED |
| BP-secret-no-chat | IMPLEMENTED containment/redaction and namespace-bound opaque retrieval | BLOCKED process obligation: code cannot certify all current/future human disclosure; no credential collection requested | UNVERIFIED |
| BP-quality-feedback | IMPLEMENTED independently reviewed pseudonymous facts and hash-linked corrections | BLOCKED: authentic linked order/product/supplier findings plus privacy/retention authority | UNVERIFIED |
| BP-digital-semantic-content | IMPLEMENTED exact named semantic approval gates | BLOCKED: authentic content judgment and authorized review | UNVERIFIED |
| BP-digital-semantic-visual | IMPLEMENTED named visual/consistency gates and technical artifact summaries | BLOCKED: authentic artistic/visual judgment and review | UNVERIFIED |
| BP-digital-semantic-ip | IMPLEMENTED named IP gate; arbitrary/duplicate/missing checks reject | BLOCKED: authentic IP/rights evidence and authorized review | UNVERIFIED |
| BP-digital-semantic-policy | IMPLEMENTED named prohibited-content/policy gate | BLOCKED: authentic policy assessment and authorized review | UNVERIFIED |

Census:246 implementation candidates /268 non-Finance mandatory criteria,22 UNVERIFIED;23 Finance-dependent criteria all UNVERIFIED. All500 catalog identities,20 review waves,360 bounded recipes and140 artistic-contract-blocked concepts remain unchanged. Reference presence and synthetic tests are not authentic acceptance. Desktop-only work remains UNVERIFIED.

## Remaining dependencies and decision

Exact original export headers/dialect/units, timestamps, revision/correction/finality semantics and independently attributable account/shop/source populations remain required before any provider-specific CSV parser or financial recognition. Source collection references are not those contracts. Existing Etsy OpenAPI JSON support is distinct and unchanged. Paid POD/AI source records and settled-billing semantics, reviewed detailed accounting/FX/allocation/finality rules, production custody/privacy/key/retention decisions and jurisdiction-specific statutory review remain blocked. No paid Etsy orders does not establish zero expenses. Missing product rights, supplier geometry/native personalization, human content reviews, installed-site evidence and postlaunch populations remain separate gates. Do not create transactions to obtain evidence.

No further reproducible supported P0/P1 was established in the exercised scope after these corrections. Contract-dependent capabilities are not implemented speculatively. Offline boundary engineering can be independently tested/reviewed; full-product engineering acceptance and operational/commercial release remain NO-GO.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No real financial import, provider request/mutation, payment, filing, financial approval/closure, production/staging write or migration, publishing, order, merge, deployment, activation, retention job or deletion functionality.
