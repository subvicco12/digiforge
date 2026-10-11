# DigiForge integrated non-finance candidate — 2026-10-11

Later gap-closure evidence is in `FINAL_GAP_CLOSURE_20261011.md`; it supersedes the remaining-renderer assertions below while preserving this prior milestone record.

Overall: **NOT FINAL-PRODUCT COMPLETE**. This report supersedes the earlier header-only raster and unavailable-KPI assertions. The batch continues PR #1161 from independently verified `7a1b87026fdbd5b65a1a9dfa96383d7d68c05467`; main was fetched and verified at `844107ab9baf7e1303cd47708e9eab3bc700b348`. The final exact candidate SHA, independent remote verification and Engineering & Safety Audit run are recorded on [PR #1161](https://github.com/subvicco12/digiforge/pull/1161). Local acceptance does not replace that publication/audit gate.

Finance PR #1147 and its recovery workspace are untouched. STOP ALL ON, external lock ON, automation OFF remain the authorized state. No merge, deployment, provider activation, Etsy/POD external execution, catalog promotion or CI/security/release weakening occurred. Runtime production safety has not been independently observed.

## Implemented and behaviorally verified boundaries

| Original source locator | Status within stated boundary | Implementation and behavioral evidence |
|---|---|---|
| Blueprint §7 p58; table14 rows8–11; §8 p65–p70; §32 p228 | SOFTWARE COMPLETE / ACCEPTANCE VERIFIED: reviewed monetary bounds, source-preserving settlement, immutable attribution and commercial cohort projection | `MonetaryReservationRepository`, `ProviderEvidenceRepository`, `CostAttributionRepository`, `CommercialCostKpiReadModel`; `AiMonetaryReservationTest`, `AiProviderSettlementTest`, `AiEvidenceWorkflowTest`, `CommercialCostKpiTest` |
| Blueprint §12 p80; §14 table19 rows9–11; §24 p166; §32 p230/p231 | SOFTWARE COMPLETE / ACCEPTANCE VERIFIED: bounded local raster, actual glyph-font and canonical raster-PDF boundary | `RasterArtworkCodec`, `BitmapFontAdapter`, `RasterPdfAdapter`, `LocalArtworkAdapter`, `DeterministicPersonalizationRenderer`, `RenderArtifactVerifier`, `RenderArtifactStore`; `LocalArtworkAdapterTest`, `RenderArtifactCertificationTest`, `FinalConvergenceScenarioTest` |
| Blueprint §20 p143/p146 and table22; §32 p232/p238 | ACCEPTANCE VERIFIED for tested local portal/REST/operator paths | `AiEvidenceController`, `AiEvidenceWorkflow`, `DeterministicRenderWorkflow`, `CommercialKpiView`, `Portal`; `CommercialCostKpiTest`, `AiEvidenceWorkflowTest`, `PortalWorkflowAcceptanceTest`, `PortalEvidenceAvailabilityTest`, `tests/browser/portal-layout.cjs` |
| Blueprint §7 revenue/gross/contribution ratios; §21; §32 p235 | FINANCE DEFERRED | No finance implementation or calculations added. KPI ratios explicitly null with FINANCE_DEFERRED. |
| Blueprint §14 provider-specific geometry/economics/samples; §15–§18; §29 restore/installation | EXTERNAL CERTIFICATION DEFERRED | Local fixtures do not certify current live supplier, provider, shop, fonts, buyer artifacts, installed protection rules or recovery operations. |

The complete generated original-source TSVs contain exact implementation/test file and method references. These rows certify the bounded contracts described here, not every sentence of an entire section.

### Raster, font and PDF

PNG QA now checks actual chunk CRCs, complete bounded zlib expansion, row filters and reconstructed pixels. Supported capability is noninterlaced 8-bit grayscale, RGB, grayscale-alpha and RGBA, up to 1,048,576 pixels and 16 MiB source bytes. Corrupt, truncated, oversized, unsupported color-profile/indexed/interlaced/ancillary formats fail closed. JPEG requires an available bounded GD decoder; it is unavailable in this local environment and is rejected rather than certified from headers. No shell, renderer subprocess or network fallback is used.

Actual reviewed versioned bitmap glyph data is stored in the immutable recipe and bound by its canonical recipe hash. Missing glyphs, excessive drawing work and host-font substitution fail closed. Rendering emits explicit bounded rectangles; text certification no longer depends on the host monospace font. Existing approved text recipes without actual glyph data cannot silently gain certification: the owner must review a new template/recipe version. No immutable historical evidence is rewritten.

Approved stored canonical personalization and exact current template/mapping/ownership evidence produce reproducible SVG, PNG and single-page self-contained raster PDF bytes. PDF creation requires explicit DPI (72–1200) in the exact approved template; no physical resolution is invented. PDF QA verifies reconstructed pixels, physical resolution, full canonical structure and exact bytes, including the cross-reference table. Arbitrary PDFs, scripting, attachments and external resources are outside this supported format and rejected. This is local deterministic QA, not a claim of full PDF/provider certification.

Artifacts are retained in protected content-addressed storage. Certification rereads actual output and preview files, every required panel, approved recipe, current canonical personalization and exact template fingerprint. Caller hashes never suffice. Preparation remains UNREVIEWED, with separate human visual and production gates. The authenticated preview strips storage paths; PDF inspection is labeled separately rather than rendered as an image.

### Commercial AI cost KPIs

The new projection joins retained reviewed provider-statement bytes, their computed source hashes, immutable metering/charge identities and matching actual-cost projection to existing opportunity/product/version/listing/order records. Human-reviewed opportunity attribution works before a product exists; shop ownership and shared product/order/opportunity lineage must agree. Original reservation/metering history is not rewritten.

An exact canonical shop, one currency and UTC half-open usage period are required. The cohort uses outcomes created in that same period, listings approved in it and paid orders with verified processed ORDER.PAID webhook evidence linked by the original order idempotency key. Qualified opportunities additionally require an actual authorized human review receipt in the period. Developed products require current READY/ACTIVE state. Each metric uses distinct attributable outcome IDs, never global portfolio counts; the evidence records usage, provider response, original invoice/line hash, amount, actor and entity identities. Lifecycle states are current cohort states, not an invented historical transition timeline.

The projection reports missing or ambiguous lineage, missing charges, source conflicts, outside-period outcomes, unavailable reads, busy accounting, bounded-cohort overflow and currency conflicts explicitly, with null uncertified values. Reviewed actual zero is AVAILABLE with a nonzero verified denominator. Known exact zero-cost administrative template-cap rows do not contaminate provider-cost cohorts; unknown or billable non-generation rows remain unavailable. No estimates become actual costs, no currencies are converted and no received order is assumed to be a sale. Revenue and gross/contribution-profit ratios remain FINANCE_DEFERRED.

AI & Budget exposes this read-only evidence to an AI operator without finance capability. GET `/digiforge/v1/ai/commercial-kpis` requires authentication/capability, exact shop, explicit UTC period and currency. Anonymous/subscriber/invalid-shop paths are tested. No external HTTP or business action is needed to render or inspect these records.

## Integrated local validation

- Composer legacy checks and full unit suite: **1,172 tests / 7,397 assertions PASS**.
- Fresh WordPress/MariaDB full integration: **314 tests / 2,980 assertions PASS**. Existing deprecation notices and deliberately fault-injected database errors remain visible; earlier test-double errors were fixed and the full suite rerun.
- Offline source/catalog/recovery regression: **75 tests PASS**.
- Offline Chromium portal/operator layouts: **228 cases, 76 fixtures, four shops, widths 390/768/1280; no failures or HTTP requests**. Includes actual stored multi-panel artwork preview and reviewed commercial KPI operator evidence. This does not certify deployed themes, live browser sessions, every accessibility criterion or every original business process.
- Configured PHPStan level 8: six pre-existing configured files PASS; configured PHPCS: one Core Config file PASS. Additional level-5 static analysis of the three new codec/font/PDF adapters PASS. Do not interpret this as whole-project static analysis.
- All **982 PHP files** syntax checked; exact CI legacy-name, secret-pattern and external-HTTP scans PASS; `git diff --check` PASS. No CI files or finance files changed.
- Actual Composer security audit: advisories `[]`, abandoned `[]`. No runtime dependency added and no local environment-specific Composer lock committed.
- Packaging is bound to the committed exact head and checked before push. GitHub exact-head audit and independent SHA verification remain required final publication evidence; see the PR run and package manifest.

Independent code review found two important defects (template-cap KPI contamination and SVG host-font substitution); both received failing regressions and fixes. Further regressions require retained qualification review evidence, explicit PDF template DPI, half-open periods, reviewed zero charges, pre-product opportunity attribution and exact opportunity/order lineage.

## Original-source preservation and reconciliation

Blueprint filename: `DigiForge_Ultimate_Master_Blueprint_v6.0_2026-09-27(1)(1).docx`, SHA-256 `fe88023f6067151b562ecf1d867dda41b0555bceac1685566a206adba6b76125`. All 36 sections, 27 tables and 706 nonempty original anchors remain verbatim.

Workbook filename: `DigiForge_DigiCraftifyGoods_Master_500_v2_Research_Migration_Plan(1)(1).xlsx`, SHA-256 `56639c9122985ac17b46685bb8b91e5d29f387f16fff715b3488e9b4c2380f6d`. All six sheets and 1,065 physical rows preserve IDs, classifications, migration decisions, formulas and cached values: Executive_Summary 11; Family_Rebalance 25; Source_Migration_500 501; Master_500_v2 501; AI_Launch_Model 18; Research_Evidence 9. All 14 original formulas remain unevaluated. W1/W2/W3 are planning; zero budget remains SET BUDGET. KEEP 428 / MERGE 37 / DOWNGRADE 35 and 428 retained / 30 existing-family / 42 new-family entries remain unchanged.

`bin/reconcile-source-ledger.py` regenerates exact code/test references across `blueprint-reconciliation.tsv`, `workbook-reconciliation.tsv`, `section-reconciliation.md` and `catalog-engine-reconciliation.tsv`. The Blueprint matrix still has **479 INCOMPLETE_REQUIREMENT_COVERAGE**, 200 context, 25 deferred and two narrowly verified cycle-cap anchors. Original-source inventory is complete; requirement-by-requirement acceptance is not complete. All 500 DG2 catalog identities remain NOT_CERTIFIED_OR_PROMOTED. The five generic recipe engine identities cover 244 entries; eight specialized engine groups cover the other 256 and remain missing specialized semantics. No catalog is promoted by codec support.

## Remaining software and operational distinctions

**MISSING SOFTWARE / incomplete acceptance:** PET_PORTRAIT, FACE_REPEAT, WATERCOLOR, HANDWRITING, MEMORIAL, CHILD_ART, LINE_ART and AI_TRANSFORM specialized engine semantics; full supplier safe-zone/bleed/seam/clipping/spelling/visual QA; broader image/color-profile/interlaced raster and complex font shaping where required; complete all-criterion four-shop lifecycle/operator permissions and process acceptance; comprehensive original-source behavioral proof. Ambiguous or absent KPI lineage remains unavailable; no synthetic denominator is supplied. The local adapters close a bounded software gap, not the entire artwork or final-product requirement.

**EXTERNAL CERTIFICATION DEFERRED:** authentic current provider bounds/statements; licensed/approved production font data; current supplier geometry/economics/samples; live OAuth/webhook/digital/POD/buyer evidence; deployed protected-upload access, themes, private previews and installed artifact/restore certification. No provider activated or production deployed.

**FINANCE DEFERRED:** PR #1147, revenue/profit ratios and finance settlement/tax/refund/contribution certification remain excluded.

**BLOCKED:** main protection GET still returns HTTP403 `Resource not accessible by integration`, explicitly requiring `administration=read`. The repository owner's administrative role does not confer that scope on the connected integration. Owner action: grant repository Administration READ to the connected fine-grained token/GitHub App, or have an authorized owner inspect protections/rulesets and exact-head approvals/checks. No merge requested or attempted here. Required controls must remain in force.

Previous CI package downloads reach GitHub artifact metadata successfully but redirected Azure Actions storage returns HTTP403. This read limitation does not imply Git push failure; local exact-head packaging and CI build/upload evidence are separate from remote ZIP byte comparison. The latter remains unverified unless a fresh artifact download succeeds. Signed artifact URLs, credentials and local environment paths are not committed. Owner/network administrator must diagnose access to the redirected Actions artifact storage; this evidence does not establish that granting extra GitHub scopes alone fixes Azure access.

The latest published managed-environment revision still cannot be independently identified. Overall environment certification is not declared PASS.

## Integration and next actions

PR #1161 retains consolidated/adapted #1148–#1160 changes and this batch. Earlier independently verified heads/audits remain in `PR_HEAD_AUDITS_20261011.json`; only current #1155 and #1159 heads are direct ancestors, with the others consolidated/cherry-picked/adapted. All original branches/heads remain published. Do not blindly merge overlapping component branches. No finance/component branch changed.

Next engineering: complete specialized engine/visual QA contracts and original-source criterion-specific acceptance without duplicating audited features; obtain owner-supplied operational evidence separately; resolve protection/artifact read limits; require successful final exact-head audit and independent SHA verification. No final-product PASS is justified while original-source acceptance remains incomplete.
