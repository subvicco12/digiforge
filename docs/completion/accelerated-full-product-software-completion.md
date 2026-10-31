# Accelerated full-product software completion

Audited baseline: `e4b6f11a8dd942103f00479e7dde8f4f524de26b` / Audit #4831. Existing `integration/full-product-finance-20261030` branch; main remains separate. Final resulting SHA, full-suite counts and exact-head audit are recorded in issue #1209 after certification. No source branch merge or production operation.

## Demonstrated engineering corrections

| Requirement / behavior | Reproduction and correction | Executable evidence |
| --- | --- | --- |
| Scoped artistic mode and job authority (`NF-personalization-engine-coverage`, four-shop isolation) | Global POD capability previously permitted mode registration and foreign-shop job reads/download/cancellation. Reuse explicit signed shop assignments for every service gate; pin actor and recheck before completion and artifact return. | `FiveEngineArchitectureTest::testTransformationCapabilityAloneCannotRegisterAReviewedShopMode`, `testForeignActorCannotReadCancelOrDownloadAnotherShopTransformation`, `testAuthorityLostDuringModeWriteCannotCommitOrReturnSuccess` |
| Immutable mode provenance and replay | Unattested modes could be registered without audit, and altered registered prompt revisions did not invalidate existing AI jobs. New registrations are audit-bound to exact mode hash/actor. Creation and reads revalidate those receipts and frozen mode hash; no approval is manufactured for historical unreceipted modes. | `testModeAuditFailureCannotLeaveAnUnauditedRegistration`, `testChangedRegisteredModeInvalidatesAnExistingAiJob` |
| Atomic journal/audit and recoverable failure | Failed journal audit previously left an orphan option and prevented exact retry. InnoDB journal/audit writes now share a transaction/savepoint. Failed writes roll back their own records; protected retained files are not destructively cleaned. Unknown commit/rollback/lock results fail closed. | `testFailedTransformationAuditRollsBackJournalAndAllowsExactRetry` |
| Concurrent revocation and nested caller transactions | New transaction safeguards are tested against an independent disposable DB connection. The existing ACL snapshot row is locked before consistent reads; locked/current snapshots must agree. Savepoints prevent implicitly committing a caller's unrelated data. | `testTransformationSerializesWithTheExistingShopRevocationRow`, `testTransformationNeverCommitsItsCallersDisposableTransaction`, `testConcurrentAclDriftCannotBeHiddenByAnExistingReadSnapshot`; existing shop-access suite |
| Finance exact coverage identities (`BP-finance-170`, `BP-finance-148`) | Overflowing decimal IDs collapsed to PHP's maximum integer; booleans/floats could be counted as source IDs. Strict positive integer/decimal-string validation now rejects unsupported values before casting/matching. The maximum representable exact integer remains supported. No money/category/finality semantics changed. | `FinanceSourceCompletenessReadModelTest` adds maximum-boundary, overflow, boolean and float order/revenue cases; six failures observed before correction |

Runtime changes: `TransformationWorkflow`, `ShopAccessRepository` and `SourceCompletenessReadModel`. The shared ACL helper uses the repository's existing key/lifecycle evidence; no duplicate grant registry, new table, API, credential, provider adapter, migration, setting, approval privilege or cleanup policy. Mutable database error checks reuse `QueryEvidence`; no static-analysis suppression.

The initial four transformation failures, missing-mode-audit failure, mode-drift failure, independent-connection lock failure caller-transaction failure and stale-read exposure were observed before their corrections. Ordinary positive fixtures now explicitly establish the tested POD shop assignment. The service does not enroll real OWNERs or infer production authority.

## Cross-workflow software matrix

| Scope | Existing implementation and verification reused/executed | Result / remaining requirement |
| --- | --- | --- |
| Digital source/edit/version/admission/QA/delivery/local Etsy preparation | `DigitalEditedDocumentJourneyTest`, `ExternalDocumentAdmissionTest`, `ExternalDocumentListingIntegrityTest`, `OfflineListingOrderLineageTest` | Implemented and locally tested; buyer-application compatibility, exact-file rights/content approval and actual Etsy acceptance remain separate. Historical Product #25 is unchanged. |
| POD fields/artwork/preview/draft planning and provider denial | FC01 multi-product journey, five-engine architecture, Printify cross-transport ownership tests | Local synthetic execution tested. Supplier identity/geometry, native provider behavior, mockup rights and production approval not certified. |
| Finance staging/provisional reporting/allocations/reversals | Native-source workflow, allocation read/review/profitability/portal tests, period integrity and atomic-audit tests | Existing supported source-independent paths tested; source recognition, finality, completeness and tax decisions remain blocked. Certified Etsy/Printify header profiles unchanged. |
| Queue failure/cancellation/revocation/reconciliation | Drive lease/dispatch and queue operator journey tests | Existing audited recovery reused and executed; no new generic worker, activation or transport. |
| Portal/authorization | Explicit disposable shop grants, real WordPress services, native authenticated loopback browser | Local permissions and responsive views tested. No installed-site or real first-party mailbox/enrollment claim. |
| Artistic generation | Reconciled existing source-derived catalog and visual pilot | 140 concepts remain genuinely unimplemented/contract-input-blocked for artistic generation. Import/review safety does not supply a transformation algorithm or quality approval. |

Integrated selection passed 268 tests / 6,520 assertions before final transaction refinements; the affected final transformation/ACL selection passed 49 / 854. Full unit passed 1,946 / 10,413, one existing skip; configured legacy/static/standards and changed-runtime level-5 analysis passed. Final full WordPress/browser/offline/catalog/security/Action Scheduler/package and hosted audit results belong to the exact-head receipt, not a pending claim.

The first integrated run used a reused disposable database and hit two retained listing quotas; both passed after resetting only `digiforge_tests`. An initial offline command omitted the local PHP PATH, causing subprocess prerequisites to fail; rerun uses the existing configured runtime, with no source/assertion weakening. Neither diagnostic is represented as a provider acceptance failure.

## Artistic execution inventory and precise blocks

[Source-derived inventory](evidence/accelerated-artistic-contract-inventory.json) retains all 500 unique identities DG2-001–DG2-500. Counts remain 360 bounded recipes and 140 artistic concepts: 79 PET_PORTRAIT, 38 WATERCOLOR, 12 LINE_ART, 11 AI_TRANSFORM. VINTAGE_PORTRAIT has no assigned Master 500 entries; first product/style remains unapproved. Twenty review waves are unchanged, not newly certified.

- PET_PORTRAIT: single-subject/style direction is not a likeness-preserving algorithm. Need licensed/consented source portraits, exact approved reference outputs and independent identity/style reviews.
- WATERCOLOR: neutral palette and paper/transparent background options do not define a reproducible approved painting transform. Need source/result pairs, style definition and permitted processing rights.
- LINE_ART: monochrome direction does not establish line extraction or supplier stroke geometry. Need approved references, minimum stroke/placement evidence and licensed sources.
- AI_TRANSFORM: named modes, immutable prompt/fixture identities, provider terms, source consent, cost limits and human review remain necessary. No live model execution or paid charge is inferred from imported output.

The related 12-case visual-certification pilot has all evidence states MISSING, zero source hashes and zero reference-matte hashes. It does not unlock the artistic concepts. Licensed Noto Serif/FC01 deterministic typography is a separate existing capability and not a substitute for portrait/watercolor/line-art evidence. No generic filter was relabeled as completed artwork.

## Compatibility and acceptance

Previously unattested mode entries and already-orphaned journals are preserved and fail closed; no invented audit, rewritten approval, automatic repair or deletion. New writes receive atomic evidence. An owner-supplied historical mode needs separately governed qualifying evidence/revision, not an inferred sign-off. Transactional options/audit storage is mandatory. The shared helper adds no production configuration; full candidate dependencies ship together and do not certify a narrow production OWNER installation.

All 23 Finance and 22 unresolved non-Finance criteria retain UNVERIFIED status; 246/268 implementation candidates remain unchanged. Existing per-criterion classifications are in `finance-contract-implementation-closure.md` and the authoritative TSV, with bounded proof references added only. No synthetic evidence closes a commercial/provider/installed-site criterion.

Remaining source-contract dependencies: paid POD/AI native schemas, attributable originals/corrections, category/precision/FX/allocation/completeness/finality rules and tax applicability. Existing conservative P01–P08 approval is reused; no repeated policy approval requested. Supplier geometry/licensing/native-personalization, Wedding Guide content/rights/buyer-application review, authenticated Google/Etsy acceptance, installed portal and real OWNER verification remain external requirements. Postlaunch conversion/quality/performance populations cannot be manufactured prelaunch.

No additional reproducible supported P0/P1 defect was established in the other inspected/executed paths; this is bounded coverage, not proof that every Blueprint requirement is complete. Full-product engineering completion remains **NO-GO** while artistic adapters and accounting contracts are unimplemented/blocked. The bounded engineering candidate is subject to its exact-head audit. Operational/commercial release remains **NO-GO**.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No production writes, live financial imports, provider mutations, privileged OWNER enrollment, payments, filing, orders, publishing, merges, deployment or activation.

## Corrective certification pass

Audit #4832 on `49c55dfa2c1d0d432b1a10f5587c70d3eeafdd42` failed in the offline gate: eleven package errors and three CLI assertions resulted from intentional OWNER dependency-drift protection. The packager reads committed Git blobs, so a pre-commit offline pass did not certify the subsequently committed ACL source. Historical failed evidence is retained at https://github.com/subvicco12/digiforge/actions/runs/41189757128.

After reviewing the ACL changes, the dependency reference is pinned to that exact source commit and the existing `QueryEvidence` dependency is explicitly included. The private-capture inventory becomes 18 paths; the three payload hashes and immutable source commit remain unchanged. Tests continue to reject source drift, extras, fabricated approval and unsafe capture paths. This is dependency-review metadata, not production installation or rollback approval; raw preimages and restore evidence remain absent. The 22 narrow-package regressions pass without weakening safeguards.

The same corrective pass first reproduced acceptance of a valid foreign-shop mode receipt copied into the selected registry. Exact selected-shop binding and a positive typed original reviewer are now mandatory even when the receipt hash is genuine. `testAValidForeignShopModeReceiptCannotAuthorizeTheSelectedShop` covers this independent source-substitution case.

The preceding local full WordPress run passed 1,580 tests / 27,104 assertions with two existing skips; the final receipt-scope correction is covered by the 49-test focused suite and final exact-head full hosted suite. No earlier full run is relabeled as certification of changed code. Final audit/tests/artifacts are recorded in issue #1209 after completion.

## History-preserving certification candidate

Final candidate branch: `integration/full-product-certified-20261031`, based directly on the same audited `e4b6f11a8dd942103f00479e7dde8f4f524de26b` baseline. Original integration branch/commits `49c55d…` and `d1549d…` and failed CI evidence are preserved, with no force push or rewritten history.

The default full candidate-history credential scan flagged browser evidence at commit `49c55d…`: a public source checksum keyed by `includes/Security/ShopAccessRepository.php` matched the generic credential pattern. Independent recomputation proved the value is the exact SHA-256 of that public Git blob, not a credential. No scanner rule, ignore mechanism or waiver was introduced. Since an ordinary subsequent commit cannot remove a historical match, the clean candidate contains the complete verified code/test delta on the audited baseline and expresses checksum evidence as separate path/sha256 records. Runtime and tests are byte-identical to `d1549d…`; only report/evidence representation differs. Existing source-pinned OWNER dependencies remain explicitly referenced to their reviewed immutable Git object, available in the preserved branch.

The final candidate must pass the same default scanner over its entire reachable history and a new exact-head audit. Other branches are not represented as credential-certified by this candidate-scoped gate. The original metadata finding and failed audit receipts remain visible and are not described as an actual credential disclosure or silently discarded.
