# Full-product Finance integration candidate — 2026-10-30

The owner reauthorized Finance development and disposable testing; live Finance operations remain unauthorized. Based directly on audited `ade008c88b4cbd97f1ebdca0034da26449b7bbc4` (#4822), isolated branch `integration/full-product-finance-20261030`. The previous non-Finance branch is not overwritten. Exact resulting SHA, hosted audit and executed totals are attached to issue #1209 after certification.

## Historical reconciliation

Main independently remains `844107ab9baf7e1303cd47708e9eab3bc700b348`. Finance PR #1147 is open at `175fc7238584d5b3c9c10dacac1ab2850d23f982`. Its ten changed paths already exist in the cumulative candidate. `OrderCostEvidence`, `SourceCompletenessReadModel` and three original regression files are byte-identical; Repository, Validator, OperationsReadModel and remaining tests contain later changes. No old branch is merged or cherry-picked. FinanceSchema (additive schema 13) and explicit local-only NativeFinanceEvidenceSchema (v1) already exist; this candidate introduces no tables, migration, DDL startup hook or external API.

Authority traces: Blueprint source `word/document.xml/p148`, `/p149`, `/p170`, `/p235`, `table23/r13/c3/p1` and their full-source hashes in `docs/engineering/original-source/full-source-requirement-trace.tsv`; existing source inventory and Master 500 mapping are reused. Current repository operating guide is `docs/operations/PRODUCTION_OPERATIONS_GUIDE.md`; its historical deployed versions are not current installed-site proof.

## Requirement → implementation → executable evidence

Source/test references establish software coverage only, not authentic financial acceptance.

| Required capability | Existing implementation / test | Current qualification |
| --- | --- | --- |
| Etsy payment/refund/fee/adjustment observations | `Finance/EtsyFinancialSourceParser.php`, pinned eight-schema OpenAPI extraction; `EtsyFinancialSourceParserTest`, `EtsyNativeObservationStageTest` | Strict offline parsing implemented; settlement/category/completeness recognition BLOCKED |
| Actual Printify/POD production and shipping charges | `Finance/OrderCostEvidence.php`, `SourceCompletenessReadModel.php`; `OrderCostEvidenceTest`, `FinancePeriodApprovalIntegrityTest` | Missing actual evidence blocks; paid-invoice/order-export parser contract and original settled records unavailable; no fabricated adapter |
| Immutable source bytes and scoped ownership | `NativeEvidenceStore`, `NativeEvidenceAccess`, `DigitalSourceBinding`; `FinanceNativeEvidenceStoreTest`, `FinanceDigitalBindingTest` | Encrypted append-only Digital/test custody and revocation implemented; production custody and POD Etsy seller-account binding BLOCKED |
| Imports, idempotency and correction/reversal history | `Repository`, `DeclarationContract`, `DeclarationMatcher`; `FinanceDeclarationImportTest`, `FinanceDeclarationMatchingTest`, `FinanceDeclarationSnapshotGraphTest` | Internal typed declarations remain UNRECONCILED; native observations never become declarations automatically |
| Currency, FX, rounding and time boundaries | `ExactDecimal`, `DeclarationReferences`, native parser; `FinanceDeclarationContractTest`, `FinanceDeclarationImportTest`, `FinancePeriodHashReadModelTest` | Exact four-place internal money/ten-place FX conventions; native divisors retained separately; unsupported source conversion/mapping BLOCKED |
| Refunds, reversals and cross-period revisions | `DeclarationMatcher`, `AllocationReview`; `FinanceDeclarationSnapshotGraphTest`, `FinanceAllocationReviewReversalTest` | Explicit signed declaration/inverse and source-history rules implemented; authentic refund finality UNKNOWN |
| Order/variant/product/listing/shop profitability | `DeclarationReadModel`, `AllocationProfitabilityReadModel`, `ProvisionalProfitMetrics`; `FinanceAllocationProfitabilityTest`, `FinanceAllocationProfitabilityPortalTest` | Exact PROVISIONAL explicit subset; missing populations and totals are not certified zero |
| Period review/close and source completeness | `Repository::transition/validatePeriodApproval`, `SourceCompletenessReadModel`; `FinancePeriodApprovalIntegrityTest` | Active periods remain blocked. Empty local observations are not provider completeness certification. Write and replay now use current source eligibility |
| Immutable audit and safe persistence | `Repository::atomic/creationAudit`, existing `Security/Logger`; `FinanceAtomicAuditTest` | Mutation/audit rollback, savepoint ownership, original actor/unique replay witness enforced; missing historical audits deny rather than backfill |
| Tax/GST/export boundary | `createTaxClassification/createIntent`, `DeclarationReadModel` evidence row arrays; `FinancePolicyTest`, `FinanceAtomicAuditTest` | Review-only classification; inert export/tax intents and evidence rows, not a statutory filing or certified CSV/report. Jurisdictional policy remains BLOCKED |
| Authorization and shop isolation | `REST/FinanceController`, `Portal/FinanceNativeSourceWorkflow`, `FinanceAllocationReviewWorkflow`; `FinanceRestShopScopeTest`, `FinanceNativeSourceWorkflowTest`, `FinanceAllocationReviewPortalWorkflowTest` | Authenticated scoped portal/all-four global Finance permissions, revocation and foreign denial implemented |
| Digital/POD/portal integration | Existing Digital and POD order/source references, reviewed allocations and portal panels; full WordPress and browser profiles | Existing integrations reused; synthetic journeys do not certify real apps, provider ownership or installed-site operation |

## Reproduced defects fixed

1. Four approval/closure cases admitted late order/ledger activity between source validation and the lifecycle write. Two more cases admitted the same race on idempotent replay. Current-read source predicates now accompany the period CAS/replay; a real second database connection verifies newly committed activity cannot be hidden by an earlier transaction snapshot.
2. Six Finance write/review/lifecycle cases returned success with failed audit persistence. The existing repository now uses checked InnoDB transactions or nested savepoints and mandatory `Logger::write`. Failure rolls back the owned operation without committing caller work; unknown/nontransactional engines and transaction failures deny. No schema is changed automatically.
3. Four idempotent replays accepted missing, duplicated, misattributed or unreadable creation audit. Unique original actor/context evidence is now required; no historical audit is invented. Failed declaration readback now leaves neither row nor success audit.

Legacy string-only audit assertion is updated from optional `Logger::audit` hook presence to mandatory checked audit writers plus rollback presence, backed by database behavior tests. Declaration fixtures isolate their audit namespace with their temporary ledger/FX/period tables to avoid fabricated duplicate IDs. The two-connection fixture commits only its disposable baseline and explicitly removes its owned synthetic rows; no production connection is used.

## All 23 Finance-dependent acceptance criteria

Every status below remains **UNVERIFIED**. Existing detailed implementation/tests and original source locators remain in `distinct-nonfinance-acceptance.tsv`. Row presence is not certification.

| Exact criterion | Required subject | Acceptance |
| --- | --- | --- |
| DERIVED-BP-ai-no-finance-authority | refund authority, tax filing authority or money movement. | UNVERIFIED |
| DERIVED-BP-ai-commercial-kpis | Required commercial metrics include AI cost per opportunity researched, per qualified opportunity, per developed product, per approved listing, per sale, and AI cost as a percentage of revenue and gross/contribution profit. | UNVERIFIED |
| BP-template-cost | Cost evidence | UNVERIFIED |
| BP-template-shipping | Shipping evidence | UNVERIFIED |
| BP-template-margin | Margin evidence | UNVERIFIED |
| BP-portal-6-profitability | Live-site portal supports profitability. | UNVERIFIED |
| BP-portal-16-ledger | Live-site portal supports ledger. | UNVERIFIED |
| BP-portal-16-profitability | Live-site portal supports profitability. | UNVERIFIED |
| BP-portal-16-tax-readiness | Live-site portal supports tax readiness. | UNVERIFIED |
| BP-portal-16-unresolved-reconciliation | Live-site portal supports unresolved reconciliation. | UNVERIFIED |
| BP-portal-16-no-automatic-filing | Live-site portal supports no automatic filing. | UNVERIFIED |
| BP-portal-17-revenue | Live-site portal supports revenue. | UNVERIFIED |
| BP-portal-17-margin | Live-site portal supports margin. | UNVERIFIED |
| BP-finance-148 | Canonical finance should normalize revenue, refunds/reserves, marketplace fees, COGS, shipping, ads, AI variable cost, tax and adjustments. Profitability must be available by order, product, listing, shop and business. Product/listing/production gates may require margin evidence. | UNVERIFIED |
| BP-finance-149 | GST/tax automation remains evidence/preparation-only until separately authorized. Filing, money movement or irreversible tax actions require a separate explicit authorization boundary. | UNVERIFIED |
| BP-finance-170 | Operational evidence → normalized ledger entries → FX evidence → revenue/refund/fee/COGS/shipping/ads/AI/tax classification → contribution/operating profit → profitability gates → period review → analytics/reporting/GST evidence. | UNVERIFIED |
| BP-process-80-8 | cost/shipping/margin | UNVERIFIED |
| BP-process-80-19 | finance/support/quality feedback | UNVERIFIED |
| BP-process-164-9 | profitability | UNVERIFIED |
| BP-process-166-21 | finance/support/quality analytics | UNVERIFIED |
| BP-process-168-14 | finance/supplier-quality feedback | UNVERIFIED |
| BP-digital-semantic-commercial | Commercial QA | UNVERIFIED |
| NF-template-commercial-economics | Provider switching revalidates actual commercial economics | UNVERIFIED |

## Local executed validation

Focused Finance integration: 236 tests / 4,420 assertions. Full unit: 1,842 / 9,984 (one existing skip), with legacy, configured static and standards checks. Full WordPress: 1,538 / 26,620 (two existing skips). Catalog: 54 / 971. Offline: 194 passed. Credential scanner regressions: six; Google HTTP-boundary regressions: three. Changed Finance runtime also passes PHPStan level 5 without suppressions; Printify smoke suites pass. Sixteen failing behavioral cases were observed before their fixes; additional controls cover transaction setup/finalization failures, positive replay, nested caller rollback and genuine second-connection source races.

Initial command-environment failures were resolved by selecting the existing PHP/Python runtime and configured offline processor, with no dependency or assertion changes. A declaration fixture needed its own audit namespace alongside its temporary ledger, FX and period tables; normal production IDs are not reused that way. Earlier failed logs remain private evidence. Hosted exact-head browser, Action Scheduler, dependency and packaging results are recorded in the final issue receipt, not presumed from local runs.

Genuinely unimplemented but contract-blocked functionality includes raw paid-POD-source ingestion, authoritative native-to-accounting recognition, full authentic source-population completeness/finality, production custody integration and jurisdiction-specific tax/export rules. Existing provisional declarations and evidence rows do not substitute for those contracts. No further independently reproducible P0/P1 supported by the inspected current contracts was established after independent review.

## Precise remaining boundaries

- D01: authentic seller/account/shop/integration ownership and appropriate POD Etsy seller binding; shared credentials/Printify ownership cannot grant Etsy Finance authority.
- D02: production custody/readers/privacy/key recovery/backup and retention/legal-hold/erasure policies. No deletion, expiry, erasure or cleanup capability is added.
- D03/D04: original correction chains plus reviewed category/sign/native-divisor/precision/FX/attribution/completeness/finality rules; avoid payment/ledger double counting. These are missing contracts, not permission to invent accounting semantics.
- D05: original private Etsy records/population context and authoritative paid POD invoice/order-export schemas and charge records. No paid orders is not evidence of zero expenses.
- D06: original AI settled charge/invoice and account/project/environment/shop relations; aggregate usage is not settled billing.
- Unsupported artistic transformations remain 140 contract/input-blocked concepts; 360 bounded recipes, 500 identities and 20 review waves preserved. No authentic supplier geometry/rights/native-personalization or installed-site evidence added.

Non-Finance census remains 246 implementation candidates / 268, 22 UNVERIFIED. Finance adds 23 distinct dependent criteria, all UNVERIFIED. No acceptance is promoted. Desktop-only work remains UNVERIFIED. Local validation and exact-head audit certify this engineering candidate only, not complete-product accounting, tax, provider or commercial acceptance.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No production/staging writes, real financial imports, financial approvals, provider requests/mutations, publishing, actual orders, merges, deployment or activation.

Full-product acceptance and operational/commercial release: **NO-GO** until the identified source contracts, authentic evidence and independent approvals exist.
