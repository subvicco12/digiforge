# Non-Finance software gap closure

Baseline: `f5262a308a4bd53682b0cb22fe2657a44b061841`, audit #4820, on `cloud/nonfinance-migration-20261028`. This batch changes two runtime files and adds native REST regressions. Its final exact commit/audit receipt is recorded in issue #1209, rather than borrowing certification from #4820.

## Requirement audit and authority

The existing `distinct-nonfinance-acceptance.tsv` remains the criterion-by-criterion map: 268 atomic SOFTWARE_MANDATORY entries, 246 implemented candidates and 22 UNVERIFIED. All 268 implementation/test paths and named PHP/Python test references were checked against current source; none are missing. Reference existence is not execution or authentic acceptance. Three independent read-only domain reviews inspected the Digital/admission/Drive/delivery, POD/Orders/Listings, and portal/REST/queue implementations and the assertions in their relevant behavioral tests. Source-string transport tests were not used as substitutes for behavioral dispatch evidence.

Source pins were reused and checked: Blueprint v6.0 `fe88023f6067151b562ecf1d867dda41b0555bceac1685566a206adba6b76125`; original Master 500 v2 workbook `56639c9122985ac17b46685bb8b91e5d29f387f16fff715b3488e9b4c2380f6d`; Operating Manual `1b654d34dfe21e66a73e3ac2b892cc5c04aaf4e2ee18ee187ce617bd9bdc8194`. The issue #1209 coordination contract and current user restrictions govern execution; embedded historical deployment suggestions do not grant authority.

No Master 500 identity, source decision or review wave changed: 500 unique DG2 identities and 500 historical DG identities, with 428 KEEP / 37 MERGE / 35 DOWNGRADE preserved. The 360 bounded recipes do not certify the 140 artistic-execution-blocked concepts. Finance is excluded.

## Reproduced failures and corrections

Primary criterion: `DERIVED-BP-shop-permissions`; supporting scope is Product Factory lineage and portal access, without installed-site certification.

1. Canonical Product Factory GET allows either `manage_digiforge_products` or `manage_digiforge`, but the first nonempty capability alternative vetoed a valid grant in another shop. With Digital product authority and Personalized POD general read authority, all four canonical entity GETs wrongly returned 403. The existing guard now resolves one stored target across its existing alternatives, then requires one complete capability set for that exact shop. AND capabilities and global collections are never authorized by pooling grants across shops or alternatives.
2. Assignment revocation completed after initial REST admission could still expose canonical opportunities/families/products/versions and a global collection, create an opportunity, or change entity state. Callback boundaries now reuse the same current admission policy after reads and before mutations. This addresses the reproduced request-stage window; it does not claim new database-atomic revocation/transaction infrastructure or alter independent provider dispatch controls.
3. A failed canonical entity SELECT returned a `WP_Error` that the controller wrapped as HTTP 200. The original 503 is now propagated, without presenting failed lookup evidence as a successful record.

Twelve initial read/error cases and six subsequent collection/mutation cases were observed failing before their fixes. The complete suite adds 22 real REST cases, including foreign/claimed-shop denial, ambiguous ancestry, split-global/AND denial, complete alternate global read without write escalation, and query failure that cannot fall back into authorization. It asserts zero outbound HTTP and unchanged safety locks. Existing ordinary permissions, four-shop/deferred architecture, global write/collection restrictions and approval controls are preserved. No schema, migration, provider transport, credential, Finance or workflow file changed.

## Executable journey evidence

| Software behavior | Implementation and actual behavioral tests |
| --- | --- |
| Digital edit, independent version/source hashes, secure admission, QA, fresh review and local draft plan | `ProductFactory/ExternalDocumentAdmission.php`, `ExternalDocumentRevision.php`, `ProductReview.php`, `Listings/ListingFactory.php`; `DigitalEditedDocumentJourneyTest`, `ExternalDocumentAdmissionTest`, `ExternalDocumentListingIntegrityTest` |
| Retained originals, customer ZIP selection, delivery integrity, stale approval and listing lineage | `EtsyCustomerDownloadResolver.php`, `EtsyCustomerDownloadSelector.php`, `EtsyMultipartDigitalFileRequest.php`; `OfflineListingOrderLineageTest::testDigitalDesignReleaseToListingPreservesReviewedShopAndSandbox` verifies actual package bytes and native review transitions |
| Google OAuth/account/source authority, expiry/revocation and queue reconciliation | Existing `DriveGoogleOAuth.php`, `DriveGoogleAuthority.php`, durable Drive services; `DriveGoogleOperationalTest`, `DriveGoogleCallbackQueueTest`, existing Drive recovery tests reused, not reimplemented |
| POD identity/mapping, typed buyer payload, actual deterministic artifacts/parity, mockup rights and production review | `ProductionTemplateContract`, `RenderArtifactVerifier`, `ProductMockupRegistry`, production authorization services; `FC01MultiProductDraftJourneyTest`, `FC01ProductionArtifactTest`, `RenderArtifactCertificationTest`, `ComposedPersonalizedOrderAcceptanceTest` |
| Denied dispatch under locks, current ownership, revocation, replay and unknown outcomes | Existing Printify interlocks/dispatch claims; behavioral `PrintifyCrossTransportOwnershipTest`, `PrintifyEnabledStopAllIsolationTest`, `ComposedPersonalizedOrderAcceptanceTest` |
| Durable dependency/lease/retry/orphan and operator visibility | `Queue/JobRepository.php`, `QueueFailurePolicy.php`, existing read models; `QueueLeaseRecoveryAcceptanceTest`, `QueueOperatorJourneyAcceptanceTest` |
| Canonical REST alternatives, exact stored scope, revocation and unavailable evidence | `ShopAccessRestGuard.php`, `ProductFactoryController.php`; new `ProductFactoryScopedRestAcceptanceTest` |

Focused integrated selection: 146 tests / 4,409 assertions. Final new REST suite: 22 / 361. Full unit: 1,842 / 9,984 with one existing skip. Legacy, configured analysis/standards and broader changed-file PHPStan level 5 passed without suppressions. Final full WordPress: 1,489 tests / 25,405 assertions, two existing skips; catalog: 54 / 971; offline: 194; security scanner regressions: six; HTTP-boundary regressions: three; acceptance-matrix integrity: 16. The first offline attempt lacked the PHP wrapper on PATH; rerunning with the configured existing runtime passed, without a source change. Exact-head hosted browser/security/package evidence belongs to the final receipt.

## Actual authenticated local browser acceptance

Current browser setup contains unrelated Finance allocation operations, so it was not run as a non-Finance journey. Instead, the existing original non-Finance harness was read from immutable commit `d381d0a10251c89caa37c069d069f4aa892a1d08`. A private copy points its application repository path to this checkout and adds the 768px tablet viewport to the existing 390/1280 loop; no existing assertion, route restriction or safety guard changed, and no historical runtime implementation was substituted. No Finance fixtures were executed in that browser run.

Runtime: WordPress 7.1.3, Chromium 154.0.8037.57, Node v24.19.0, local PHP wrapper and an independently named disposable loopback database. Actual native login, session cookies, REST nonces, selected-shop portal/REST and protected consent requests passed 85 checks across 390/768/1280 viewports (the initial two-viewport run passed 64). Results: 236 same-origin requests, zero external requests, zero business mutations, all 44 snapshotted business tables unchanged, STOP ALL/external lock true and automation false. The runner removed its own private fixture and isolated table prefix after verification. Sanitized source-bound receipt: `evidence/nonfinance-scoped-rest-browser.json`.

The consent browser assertions prove denial under the safety locks; they do not claim the lock-dominated receipt-failure branch was reached. Its actual downstream expiry/replay/receipt-failure verification remains in native `PortalResearchRunAcceptanceTest`. This is genuine local HTTP browser testing, but not installed-site acceptance, Google Docs/Word compatibility, real ownership, native Printify behavior or commercial certification. Full WordPress tests separately use the existing disposable 7.2-alpha fixture.

## Completion boundary

No additional independently reproducible P0/P1 defect was established by the three domain audits after these corrections. This conclusion is bounded to inspected source/contracts and executed local journeys, not a proof of every possible UI combination or authentic Blueprint acceptance.

The 22 existing UNVERIFIED entries retain their IDs/statuses. The ten already approved policy directions do not require duplicate approval, but their exact source evidence and independent review remain necessary. Seven external ownership/provider/security subjects and five composite portal/Digital acceptance subjects remain separately unverified. Metrics require actual attributable operating populations; missing postlaunch observations are not new software defects, and their criteria are not waived. The 23 Finance-dependent entries remain separate and excluded.

Remaining external gates: authentic supplier/variant/placement and applicable geometry; native font/layer/optional-text/preview parity and commercial rights; Wedding Guide exact-file content/instructions/rights and actual buyer-application reviews; genuine OWNER identity/enrollment and installed-site authenticated workflows. None were substituted with synthetic fixtures. No production investigation, backup research or provider calls were repeated.

Engineering decision depends on the final exact-head audit for this changed candidate. Operational and commercial decisions remain **NO-GO**. STOP ALL ON; external lock ON; automation OFF; Finance excluded; RELEASE HOLD. No production/staging writes, actual provider operations, publishing, real orders, merges, deployments or activation.
