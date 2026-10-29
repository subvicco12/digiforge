# Integrated non-Finance MVP executable journeys

Baseline: `750048fc0a5e96cafdb72276f34e5ac751e5d18a` on `cloud/nonfinance-migration-20261028`. This report accompanies runtime corrections and 24 new WordPress regression cases. The final commit and hosted audit receipt belong in issue #1209; the baseline audit #4819 does not certify these changes.

## Reproduced defects and corrections

* All eight legacy Digital registry types accepted an existing idempotency key before validating the new request. Changed content or ancestry could therefore return the old record as a successful replay. Creation now validates required fields and current relationships first, then compares typed, canonical request intent and existing schema defaults, including the internal license fingerprint. Initial and concurrent-winner lookups reject conflicting intent with HTTP 409. Exact/default-equivalent retries and canonical JSON ordering remain supported. No new persistence schema or historical request snapshot is claimed.
* Single-shop Digital operators received HTTP 403 for their own legacy registry items because target resolution omitted those routes. Single-item **GET** now resolves stored product ancestry through the existing scope policy. Global collection/write admission remains unchanged. A second admission check immediately before the response also rejects an actual assignment revoked between REST admission and item retrieval. Foreign-shop and misleading query claims remain denied.
* The approval inbox counted seven arbitrary or duplicate semantic rows as PASS while the authoritative Product Approval boundary rejected them. The inbox now calls that existing named-check policy rather than maintaining a weaker duplicate rule. An empty required-asset set also contributes a blocking QA failure. A semantic pass remains distinct from Product Approval and commercial certification.

No Finance, Google transport/recovery, schema, migration, workflow or external-operation authority changes are included. The small query-state cleanup uses the existing `QueryEvidence` helper and passes broader changed-file PHPStan level 5 without suppressions.

## Executable behavior map

| Required behavior | Existing implementation reused | Executable evidence |
| --- | --- | --- |
| Digital source identity, immutable new version, retained originals | `ProductFactory/Repository.php`, `ProductFactory/ExternalDocumentAdmission.php` | `DigitalEditedDocumentJourneyTest`: actual DOCX XML and equal-length PDF fictional edit, new hashes/version/spec, conflicting old operation rejected, old version/bytes unchanged |
| Admission integrity, malformed source and completeness | `ProductFactory/ExternalDocumentAdmission.php` | `ExternalDocumentAdmissionTest`, `ExternalDocumentListingIntegrityTest` |
| Exact retries and changed-intent denial across eight registry types | `DigitalFactory/Repository.php` | `DigitalFactoryIntentScopeTest`: exact replay, canonical ordering, omitted fields, malformed checksum, lineage conflict, concurrent insert winner |
| Scoped item visibility and revoked assignment | `Security/ShopAccessTargetScope.php`, `REST/DigitalFactoryController.php` | `DigitalFactoryIntentScopeTest`: own item, foreign item, false scope claim, revoked-after-admission race; collection/PATCH still denied |
| Semantic QA, independent human decision boundary | `ProductFactory/ProductReview.php`, `Portal/U3ApprovalInbox.php` | `DigitalFactoryIntentScopeTest`: arbitrary/duplicate/missing named checks, empty assets; `DigitalEditedDocumentJourneyTest`: new version cannot borrow approval, distinct synthetic reviewer |
| Delivery candidate and controlled local listing plan | `Listings/ListingFactory.php`, production repositories | `ExternalDocumentListingIntegrityTest`, `OfflineListingOrderLineageTest`, `DigitalEditedDocumentJourneyTest`: REVIEW_REQUIRED, no Etsy API invocation or external draft creation |
| Master 500 source identities and governed mappings | `POD/GovernedCatalogRepository.php`, `POD/MasterCatalogV2Reference.php` | `MasterCatalogV2PersistenceTest`, `MasterCatalogStoredSourceAcceptanceTest`, catalog profile |
| POD fields, deterministic rendering, native packet, geometry and production review | `POD/FC01NativeEvidencePacket.php`, `POD/FC01ProductionArtifact.php`, existing FC01 renderers | `FC01MultiProductDraftJourneyTest`, `FC01ProductionArtifactTest`, `FC01NativeEvidencePacketTest`: supported synthetic journey and fail-closed missing/foreign/revoked evidence |
| Queue dependency, cancellation, retry, recovery and leases | `Queue/JobRepository.php`, `Queue/QueueFailurePolicy.php` | `QueueOperatorJourneyAcceptanceTest`, `QueueLeaseRecoveryAcceptanceTest`; existing full-suite Drive coverage reused |
| Portal projections and approval states | existing portal services and shared REST authorization | `PortalArtifactCoverageAcceptanceTest`, full WordPress suite; hosted synthetic browser gates separately required |

The new editing test exercises local source bytes and real server-side admission/review/listing services. It is **not** Microsoft Word, Google Docs, genuine buyer-editability, installed-site or provider acceptance. Synthetic approvals exist only in the disposable test fixture. No outbound provider calls occur in the new journeys; each new case asserts the safety locks remain held.

## Validation and fixture limits

Focused integrated selection: 113 tests / 3,491 assertions. Full unit: 1,842 tests / 9,984 assertions, one existing skip. Final full WordPress: 1,467 tests / 25,044 assertions, two existing skips. Offline regressions: 194. Configured PHPStan, coding standards, three legacy scripts and three Printify catalog smoke scripts passed. Final catalog: 54 tests / 971 assertions. Exact-head hosted audit evidence is recorded in the final receipt after completion.

Local WordPress is a disposable 7.2-alpha fixture, not an installed production compatibility claim. Suites use a reset disposable database and a private process-specific upload root: earlier retained tamper-test files remain intact instead of contaminating later runs. Missing Python configuration in an initial run, a strict static-source expectation, and test-construction/retained-fixture issues were corrected without weakening gates. Independent read-only review found no remaining important findings.

Reproduce the new regressions with the repository's normal isolated WordPress prerequisites:

```sh
vendor/bin/phpunit -c phpunit.wordpress.xml.dist --filter 'DigitalFactoryIntentScopeTest|DigitalEditedDocumentJourneyTest'
vendor/bin/phpunit -c phpunit.wordpress.xml.dist
vendor/bin/phpunit -c phpunit.wordpress.catalog.xml.dist
```

Configure the pinned cutout Python runtime and `WP_TESTS_DIR` as required by the existing audit. Do not run database profiles concurrently or reuse retained tampered upload fixtures between independent runs. Security scanner regressions require the existing `DIGIFORGE_TEST_GITLEAKS` prerequisite; with it configured all six passed, alongside three HTTP-boundary regressions and a zero-finding changed-file scan.

## Acceptance and remaining boundaries

All 500 catalog identities and 20 review waves remain unchanged. The 360 bounded recipes do not certify the 140 blocked artistic concepts. The 246/268 implementation-candidate baseline and 22 UNVERIFIED non-Finance criteria remain unchanged; 23 Finance-dependent criteria are excluded from this batch.

No additional independently reproducible P0/P1 defect was established in the reviewed journeys after these corrections. This does not claim every Blueprint requirement has authentic acceptance evidence. Existing external gates remain: exact supplier/variant/placement and applicable geometry; genuine native-personalization parity; artwork/font/mockup rights and independent content/linguistic review; actual Wedding Guide source/buyer-application and owner approvals; verified OWNER enrollment and installed-site desktop/mobile acceptance. Postlaunch attributable metrics remain separate from prelaunch evidence, without waiving their original criteria.

Engineering decision is conditional on the final exact-head audit. Operational and commercial release remain **NO-GO**. STOP ALL ON; external lock ON; automation OFF; Finance excluded; RELEASE HOLD. No production writes, external provider operations, real orders, publishing, merges or deployments were performed.
