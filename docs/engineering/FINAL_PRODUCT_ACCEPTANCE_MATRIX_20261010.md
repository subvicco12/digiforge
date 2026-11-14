# Preliminary source-to-code matrix — historical pre-attachment baseline

**Superseded source-access limitation:** both originals are now read. See [original-source reconciliation](original-source/README.md), the complete original-text inventory and catalog/formula records. The statements below about missing attachments describe the earlier baseline only.

Baseline: main `844107ab9baf7e1303cd47708e9eab3bc700b348`, independently matched by fetch and remote lookup. Authority: issue #959 (read 2026-10-10), repository v6 convergence and Master 500 contracts. Environment certification remains FAIL for independently unverified latest published configuration; GitHub push and exact-SHA verification passed.

## Coverage boundary

The original Ultimate Master Blueprint v6.0 and Master 500 v2 XLSX are absent from this task. Connected Drive discovery found older blueprints and v1 workbooks only. This is the complete matrix of requirements exposed by #959 and current repository contracts, **not a claim of complete original-source coverage**. Blueprint sections 25, 26 and 32 must be reconciled line by line when the source becomes accessible. Existing implementation references establish presence, not fresh runtime acceptance. Finance remains deferred and PR #1147 is untouched.

| Requirement | Classification at baseline | Concrete code / tests / evidence |
| --- | --- | --- |
| Readiness separate from fulfillment authority | IMPLEMENTED | Orders/Repository.php; tests/unit/OrderReadinessProjectionTest.php; #959 P0 |
| Paid webhook local VALIDATED/REVIEW_REQUIRED; no autoapproval | IMPLEMENTED | Listings/EtsyOrderWebhookLifecycle.php; tests/unit/V6AttributionOrderIntakeHardeningTest.php |
| Exact approved listing-bound POD mapping | IMPLEMENTED | Orders/ApprovedPodMappingResolver.php; #959 P0 |
| Digital delivery bound to confirmed immutable file-operation evidence | IMPLEMENTED | Orders/Repository.php; Listings/EtsyDigitalFileVerification.php; tests/unit/V6OrderReadinessCostKpiTest.php |
| Deterministic personalized render and buyer-preview parity | IMPLEMENTED | POD/RenderEvidenceRepository.php; tests/unit/V6ProductionLifecycleClosureTest.php |
| Immutable production template and Printify preparation boundary | IMPLEMENTED | POD production contracts; tests/unit/V6PrintifyPreflightDryRunTest.php |
| Portal-first required operating areas | IMPLEMENTED | Portal/Portal.php; tests/unit/BlueprintFinalPortalAcceptanceContractTest.php |
| Mobile/tablet navigation and bounded tables | IMPLEMENTED | assets/portal-ui.css; assets portal scripts; BlueprintFinalPortalAcceptanceContractTest.php |
| Current action vs historical attention failures | IMPLEMENTED | Portal/OperationalDepthReadModel.php; tests/unit/V6LifecycleAttentionDenominatorTest.php |
| Stale/current fulfillment plan diagnostics | IMPLEMENTED | Portal/OperationalDepthReadModel.php; #959 P1 |
| Digital-file read-only operator evidence | IMPLEMENTED | Listings/EtsyDigitalAttachmentReadModel.php; #959 P1 |
| Webhook signature-first replay-bounded deduplicated local intake | IMPLEMENTED | Listings webhook contracts; docs/operations/ETSY_WEBHOOK_PROVIDER_CERTIFICATION_CHECKLIST.md |
| Provider-side webhook delivery/signing certification | CONFIGURATION OR OWNER APPROVAL | Same checklist; no provider activation authorized |
| Shop-scoped AI quantity and truthful monetary budget gates | IMPLEMENTED | AI/ShopAiPlan.php; AI/ShopAiGovernanceRepository.php; Launch/AiActivationPreflight.php; #959 PR #987 |
| Governed v1 immutable source identity | IMPLEMENTED | POD/PersonalizedCatalogReference.php; POD/MasterCatalogImportContract.php |
| 500 v2 candidates with 428 retained / 72 added concepts | MISSING SOFTWARE | POD/MasterCatalogV2MigrationContract.php rejects blank new-concept source IDs; offline contract records actual mixed distribution |
| 500 source dispositions: 428 KEEP / 37 MERGE / 35 DOWNGRADE | MISSING SOFTWARE | PHP contract requires successor on every source; docs/releases/MASTER_500_V2_CANDIDATE_VALIDATION_20261009.md |
| Full disposition preservation during v2 persistence | MISSING SOFTWARE | POD/MasterCatalogV2IngestionService.php only maps direct successors; new concepts rejected and excluded sources not persisted in metadata |
| Mixed-lineage exact immutable replay | MISSING SOFTWARE | GovernedCatalogRepository.php supports metadata comparison but service cannot construct documented candidate; WordPress end-to-end regression needed |
| Source/family counts and cross-sheet offline lineage | IMPLEMENTED | bin/validate-master500-v2.py; tests/offline/test_master500_validator.py |
| Reject unknown recommended-stage vocabulary | MISSING SOFTWARE | Offline validation contract requires it; CLI only checks nonempty stage |
| Canonical row digest for candidate review | MISSING SOFTWARE | Offline validation contract requires it; CLI only emits raw workbook SHA |
| Human ACCEPT/REJECT does not promote | IMPLEMENTED | POD/MasterCatalogV2AcceptanceRepository.php; tests/unit/MasterCatalogV2AcceptanceContractTest.php |
| Production catalog promotion | CONFIGURATION OR OWNER APPROVAL | docs/operations/MASTER500_V2_PROMOTION_GATE.md |
| Analytics unavailable reads never become zero | IMPLEMENTED | Analytics/KpiSnapshot.php; #959 PR #983 |
| Queue/recovery views are observational | IMPLEMENTED | Portal/Portal.php; BlueprintFinalPortalAcceptanceContractTest.php |
| Current artifact identity and exact-head audit | EVIDENCE UNVERIFIED for new candidates | .github/workflows/digiforge-foundation-audit.yml; new PR heads require mandatory audit |
| Fresh staging acceptance for new artifact | EVIDENCE UNVERIFIED | V6_RELEASE_EXIT_EVIDENCE_MATRIX.md; prior package evidence does not certify later source |
| Backup / recovery certification | CONFIGURATION OR OWNER APPROVAL | Owner-deferred; docs/operations/DEFERRED_BACKUP_RECOVERY_EVIDENCE.md |
| Finance workstream | DEFERRED BY OWNER | PR #1147 excluded; no finance changes |
| Shop 3 / Shop 4 / Gelato | DEFERRED BY BLUEPRINT | #959 owner policy requires future business/provider decisions |
| Originality/licensing/economics/demand/provider availability | CONFIGURATION OR OWNER APPROVAL | Offline structural pass grants no nonstructural certification |
| STOP ALL / external lock / automation OFF | IMPLEMENTED, preservation required | Core/Settings.php; Launch preflights; unit safety contracts; no runtime-setting edits |
| Production deployment and external Etsy/POD operations | CONFIGURATION OR OWNER APPROVAL | Explicitly unauthorized by current task |
| Original blueprint section-by-section completeness | EVIDENCE UNVERIFIED | Original v6.0 document unavailable; older versions are not substitutes |

Paths without a prefix above are under includes/ unless explicitly identified otherwise. No already audited feature is scheduled for rebuild. The two demonstrated catalog gaps form separate coherent PRs; all other source completeness remains subject to original-source reconciliation.

## Verified implementation milestone

Mixed-lineage normalization and persistence now have behavioral regressions: 1,156 unit tests / 7,358 assertions; 201 WordPress tests / 1,858 assertions on a fresh isolated database. Configured PHPStan and PHPCS passed. The legacy all-KEEP contract remains accepted, but exact prior persisted replay metadata is never silently rewritten; conflicting history requires reconciliation. Source-to-original completeness remains EVIDENCE UNVERIFIED. No production migration or catalog promotion was performed.
