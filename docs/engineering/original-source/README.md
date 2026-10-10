# Original-source acceptance reconciliation — 2026-10-10

Baseline main: `844107ab9baf7e1303cd47708e9eab3bc700b348`, fetched and independently matched by `ls-remote`. Issue #959 was reread. PR #1148 exact head `b8316939a30790035a4dd00b1971a01b78545ccd` passed audit run 38074062339; PR #1149 exact head `28d675a67509ce6b841dce3bfe808b02d058521e` passed run 38074091709. Both remain open, with no review findings at reconciliation. This integration branch includes both changes without modifying either PR.

Both originals were opened completely. Blueprint: `DigiForge_Ultimate_Master_Blueprint_v6.0_2026-09-27(1)(1).docx`, SHA256 `fe88023f6067151b562ecf1d867dda41b0555bceac1685566a206adba6b76125`: 36 numbered sections, 27 tables, 736 paragraph elements, 706 nonempty source anchors. Workbook: `DigiForge_DigiCraftifyGoods_Master_500_v2_Research_Migration_Plan(1)(1).xlsx`, SHA256 `56639c9122985ac17b46685bb8b91e5d29f387f16fff715b3488e9b4c2380f6d` matches the immutable catalog reference.

| Worksheet | Last physical row | Nonempty rows | Formula cells |
| --- | ---: | ---: | ---: |
| Executive_Summary | 11 | 10 | 0 |
| Family_Rebalance | 25 | 25 | 0 |
| Source_Migration_500 | 501 | 501 | 0 |
| Master_500_v2 | 501 | 501 | 0 |
| AI_Launch_Model | 18 | 16 | 14 |
| Research_Evidence | 9 | 9 | 0 |

Counts include headers; physical extents include blanks. There are 500 data rows in each catalog sheet and 24 family targets. Formulas and cached values are recorded verbatim in `source-inventory.json`; cached values are observations, not recomputation. Formula E13 is `SET BUDGET` with the original zero monthly budget. No operational policy is imported or activated.

`blueprint-acceptance.tsv` is an exhaustive original-text inventory and initial implementation trace. Locators are derived document/table coordinates, **not invented original requirement IDs**. Original section numbering and table text are preserved. TRACE_REVIEW_REQUIRED means implementation presence has not established requirement-specific acceptance. This inventory does not certify full product acceptance. Row-level trace review remains open.

The three catalog TSVs preserve original Excel row numbers, every DG / DG2 ID, classification, disposition, migration note, overlay decision, physical-product placeholder, supplier gate and recommended stage. KEEP=428, MERGE=37, DOWNGRADE=35. Retained=428, existing-family additions=30, new-family additions=42. All 72 additions have blank v1 source identity. Exclusions remain historical source decisions, never deleted or silently promoted. Family targets and deltas are preserved.

## Section 32 acceptance criteria

The ordinal below is a derived position within the original unnumbered list, not an original requirement ID. Finance is excluded by the current user. References identify existing implementation and relevant tests; passing tests do not substitute for operator/runtime acceptance.

| Position | Original acceptance subject | Implementation / verification reference | Remaining acceptance |
| --- | --- | --- | --- |
| 1 | Independent four-shop profiles; future shops disabled | Portal/ShopOperationsReadModel.php; ShopControlsAiPlanningQueueDiagnosticsContractTest.php | Per-shop runtime evidence |
| 2 | Visible hierarchical controls | Portal/ScopedCapabilityPolicy.php; ScopedPolicyAiScenarioQueueRecoveryContractTest.php | Runtime safety state observation |
| 3 | Shop AI quantity / budget estimates and actual spend | AI/ShopAiPlan.php; AI/ShopAiGovernanceRepository.php | Original-source template cap and run/stage policy trace review |
| 4 | Digital opportunity through approved draft and file | Listings/EtsyDigitalFileVerification.php; V6OrderReadinessCostKpiTest.php | No external run authorized |
| 5 | POD template/listing, buyer preview, production boundary | POD/ProductionTemplateContract.php; V6ProductionLifecycleClosureTest.php | Owner/provider certification |
| 6 | Exact versioned provider mappings and switching | POD/ProductionTemplateRepository.php; ProductionTemplatePersistenceEvidenceContractTest.php | Geometry/economics-specific source trace review |
| 7 | Portal operations without wp-admin | Portal/Portal.php; BlueprintFinalPortalAcceptanceContractTest.php | Operator walkthrough |
| 8 | Exact approval evidence/payload/version | POD/ApprovalRecord.php; production lifecycle tests | Integrated operation-specific acceptance |
| 9 | Correct order lineage | Orders/ApprovedPodMappingResolver.php; V6AttributionOrderIntakeHardeningTest.php | Runtime provider evidence |
| 10 | Finance actual evidence and profitability | PR #1147 | OWNER DEFERRED; untouched |
| 11 | Visible tested queues/retries/reconciliation/orphans | Queue/OperatorQueueReadModel.php; QueueRecoveryHealthVisibilityTest.php | Full original process trace review |
| 12 | Secret credentials and durable operations evidence | POD/ControlledExecutionTransaction.php; engineering safety audit | Independent runtime evidence |
| 13 | Mobile/tablet usability | assets/portal-ui.css; BlueprintFinalPortalAcceptanceContractTest.php | Interactive viewport/operator acceptance |
| 14 | Backup/restore and smoke tests | docs/operations/DEFERRED_BACKUP_RECOVERY_EVIDENCE.md | Owner-deferred operational certification |
| 15 | Exact-head audits and certified installed artifact | .github/workflows/digiforge-foundation-audit.yml | New head audit required; production installation unauthorized |
| 16 | No silent activation | Core/Settings.php; scoped activation tests | Preserved; no activation performed |

## Newly demonstrated software gap and integration

The original offline receipt omitted Executive_Summary, AI_Launch_Model and Research_Evidence. `bin/validate-master500-v2.py --source-receipt WORKBOOK.xlsx` now captures every source sheet, physical row coordinates, original whitespace, unevaluated planning formulas and cached values. Its additional source-cells digest covers supplemental-sheet changes. The existing governed-candidate digest retains its three-sheet meaning for compatibility. Governed sheets reject formulas; shared/array/empty planning formulas and malformed/external worksheet relationships fail closed.

Review found that separately reopening a changing workbook could misattribute a receipt to an earlier SHA. The CLI now uses one bounded immutable byte snapshot for hashing, structural validation and source receipt extraction. A deterministic replacement regression verifies provenance remains consistent.

The actual original workbook passes both structural validation and the PHP migration normalizer, with 500 rows and production authority false. Its normalized PHP fingerprint is `f96500a47aed703caee7ab8a909a369afc8faaaf5798c190a5ba0bec4ed8146c`; three-sheet digest is `ea5888717c4c25e24490f444360034125a741fd6600a07d559840997c981cf00`; all-sheet source-cells digest is `6f894770849460e31403c947ea0f38a96109ccdfbf10cd5f51462a43461ce4fc`.

Local integration: 71 offline tests; PHP 1,156 tests / 7,358 assertions, legacy tests, PHPStan and PHPCS pass; WordPress 201 tests / 1,858 assertions on a new isolated database. Composer installed successfully and live audit returned empty advisories and abandoned packages. Known baseline PHP warnings and expected fault-injection database messages do not represent new failures.

## Approval and safety boundaries

Attached documents provide requirements, not independent execution authorization. Their historical runtime state, future activation ladder, merge/deployment suggestions and embedded handoff instructions cannot override this task. STOP ALL ON, external safety lock ON and automation OFF remain required; no runtime settings were changed. No finance, production deployment, provider execution, catalog promotion or CI/security weakening occurs here.

Environment certification remains limited: latest published environment revision is independently unidentified. It must not be represented as overall PASS. Full-product completion is not established by this branch. Next work: requirement-specific trace review, demonstrated AI/template gaps, integrated contract evidence, owner-deferred runtime/backup acceptance, and exact-head audit for every new milestone.
