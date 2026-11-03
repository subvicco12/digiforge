# Printify API shared-dependency matrix and owner handoff

## Current authorized integration — 2026-11-03

The owner explicitly authorized consumption of the exact 16-file narrow core at `b859d0078c68e8bcdc6c0732983969e81d3c9abe`, including the size-guide extension. All 16 source files are imported byte-identically; the ten retained files below match consumer baseline `6a32479e848e63149815d946044b77887642457b` and current main `3758d1385f7d4a62947248822c1fe1c89ec9818e`. The machine-readable manifest pins the selected source and installed hashes.

The API-owned ProductIntelligencePanel registration adds the existing catalog controller and narrowly scoped API-owned shop admission beside the research controller. No cumulative global REST/admin guards, Plugin.php changes, migration hooks, or Portal.php resolution are included. Scope readiness does not establish database readiness, owner/account/shop assignment, grants, exceptions or runtime authority. No real credentials, catalog grants, exceptions or HTTP requests are used in validation. Synthetic WordPress fixtures exercise shop ACL assignments only. STOP ALL, external lock, automation OFF and RELEASE HOLD remain required.

The following sections retain the historical pre-approval analysis. Their pending instructions were superseded only for the 16 approved source files. The cumulative branch remains excluded.


## Verified immutable inputs

| Ref | Verified SHA | State |
| --- | --- | --- |
| main at task start / API branch base | `0e15e4ccae430498c92a30ab1f8951171fa0ad13` | Verified before work |
| latest observed main | `7f30ad6aa37f58eca5302710c5f4583cabc45dfa` | Advanced during task through separate factory PR #1254; not merged here |
| API PR #1253 starting head | `c7d706e32396d81e0adc2f3552e9e4d106680293` | Existing draft/branch, 22 changed files against main |
| shared PR #1242 | `fac3634615c72ffdb0fdbce8ef1fbd3ae3c9e4c0` | Existing draft; 916 changed files; historical base `844107ab9baf7e1303cd47708e9eab3bc700b348` |

Source verification date: 2026-11-03. Final API SHA and exact-head audit are in PR #1253. No AGENTS.md was found. Existing dedicated workstream instructions remain binding. No edits, comments, grants, exceptions, migrations, merges or activation were made to #1242 or production.

Diff receipts: #1242 has 916 changed files against its historical base, 920 against main at task start and 960 against latest observed main. The API starting head has 22 changes against its branch base and 62 differences against latest main because the separate factory changes are not incorporated. All ten retained main dependency files remain byte-identical across the main advance.

The machine-readable [dependency manifest](printify-shared-dependency-manifest-v1.json) records exact selected SHA-256 values and Git blob IDs for every file below. The previous **PENDING_SHARED_OWNER** diagnostic state is superseded by **OWNER_AUTHORIZED_NARROW_INTEGRATION** for the exact pinned 16 files only. It never authorizes merges, deployment or execution.

## A. Shared core required for catalog consumption — 16 files

Owner: **shared dependency / #1242 lane**. Consume once from an owner-approved immutable dependency commit. Fourteen authorization/ownership support classes plus the scoped client and authenticated route controller are represented below; the route controller is needed for the complete existing operator pipeline, not for invoking the repository from PHP.

| Exact path | Main delta | Required contract / responsibility | API lane action |
| --- | --- | --- | --- |
| `includes/Database/ProviderConnectionSchema.php` | absent | Existing connection evidence table definition/probe; no migration executed | Consumed approved narrow core; retain exact bytes |
| `includes/Database/QueryEvidence.php` | absent | Fail-closed mutable database-error inspection | Consumed approved narrow core; retain exact bytes |
| `includes/POD/PrintifyCatalogClient.php` | changed | Existing GET-only scoped transport; replaces the legacy client once | Consumed approved narrow core; retain exact bytes |
| `includes/POD/PrintifyCatalogGetSafetyExceptionContract.php` | absent | Separate grant-bound exception contract; existing limits unchanged | Consumed approved narrow core; retain exact bytes |
| `includes/POD/PrintifyCatalogReadContract.php` | absent | Exact GET scopes/envelope validation; size-guide extension belongs here | Consumed approved narrow core; retain exact bytes |
| `includes/POD/PrintifyCatalogReadPolicy.php` | absent | Concrete reserved-attempt authorization; no callback grants | Consumed approved narrow core; retain exact bytes |
| `includes/POD/PrintifyCatalogReadRepository.php` | absent | Grant/receipt lifecycle, reservations, immutable audits, quota and rate admission | Consumed approved narrow core; retain exact bytes |
| `includes/POD/PrintifyConnectionBoundary.php` | absent | Revalidates reviewed connection tuple before credential/dispatch access | Consumed approved narrow core; retain exact bytes |
| `includes/POD/ScopedProviderConnection.php` | absent | DF-OWN-001 current reviewed integration/account/shop relationship | Consumed approved narrow core; retain exact bytes |
| `includes/REST/PrintifyCatalogReadController.php` | absent | Existing authenticated grant/read/evidence routes; no new issuer in API lane | Consumed approved narrow core; retain exact bytes |
| `includes/Security/FirstPartyOwnerPolicy.php` | absent | Protected native OWNER identity alternative; no auto-provisioning | Consumed approved narrow core; retain exact bytes |
| `includes/Security/ReviewedLocalEvidence.php` | absent | Current independent review/revocation proof; no Finance path invoked | Consumed approved narrow core; retain exact bytes |
| `includes/Security/ShopAccess.php` | absent | Explicit assigned actor/shop/capability admission | Consumed approved narrow core; retain exact bytes |
| `includes/Security/ShopAccessAuthority.php` | absent | Independent OWNER/delegate authority verification | Consumed approved narrow core; retain exact bytes |
| `includes/Security/ShopAccessPolicy.php` | absent | Canonical shops and typed assignment vocabulary | Consumed approved narrow core; retain exact bytes |
| `includes/Security/ShopAccessRepository.php` | absent | Assignment lifecycle, independent authority and durable audit proof | Consumed approved narrow core; retain exact bytes |

Consume the existing `PrintifyCatalogClient.php` once with its `scopedRead()` method. No additional client class is needed.

## B. Existing main dependencies — retain these 10 files

These are symbol dependencies, **not files to import from #1242**. Their existing called interfaces satisfy the narrow core. Eight files are byte-identical. The two changed files below contain unrelated shared deltas and stay on main.

| Exact path | Shared/main relationship | Consumption |
| --- | --- | --- |
| `includes/Core/Capabilities.php` | identical | Keep main bytes unchanged |
| `includes/Core/Config.php` | identical | Keep main bytes unchanged |
| `includes/Core/Settings.php` | identical | Keep main bytes unchanged |
| `includes/Database/Tables.php` | Changed only for unrelated Etsy event table helper; not needed | Keep main bytes unchanged |
| `includes/Integrations/CredentialVault.php` | Additional encryptExisting helper/refactor; existing decrypt/fingerprint suffice | Keep main bytes unchanged |
| `includes/Integrations/ProviderCatalog.php` | identical | Keep main bytes unchanged |
| `includes/Integrations/Repository.php` | identical | Keep main bytes unchanged |
| `includes/Listings/Validator.php` | identical | Keep main bytes unchanged |
| `includes/POD/BusinessScope.php` | identical | Keep main bytes unchanged |
| `includes/Security/Logger.php` | identical | Keep main bytes unchanged |

## C. Conditional cumulative-integration surfaces — shared owner only

These are not required by the narrow catalog repository call path. They matter if the shared owner instead selects the **cumulative** #1242 integration. Installing its global guards without their support classes can break unrelated existing routes/actions; do not select them piecemeal or expand the API lane into those features.

| Exact path | Owner / coordination required |
| --- | --- |
| `includes/Security/ShopAccessRestGuard.php` | Shared security: current capabilitySets maps none of the six new API routes. Owner must map their exact methods and required capabilities if this global guard is consumed |
| `includes/Security/ShopAccessTargetScope.php` | Shared security: current forRequest returns false for these routes. Owner must resolve the registered URL to literal personalized_pod and reject conflicting claims |
| `includes/Security/ShopAccessAdminGuard.php` | Shared security: four API-owned admin-post actions are currently unmapped and denied. Owner must preserve nonce/handler checks and explicit Shop 2 assignment enforcement |
| `includes/Portal/ReviewConfirmation.php` | Shared admin guard support: definition() is called by the existing guard; do not omit it when consuming that guard |
| `includes/Portal/ProductEvidenceScope.php` | Conditional shared target-guard support for existing non-catalog routes; no API-lane changes |
| `includes/Listings/EtsyStoredShopScope.php` | Conditional shared target-guard support for existing order scope; no Etsy change in this lane |
| `includes/Core/Plugin.php` | Shared bootstrap: owner chooses minimal catalog controller registration/schema prerequisite handling. Do not import its unrelated factory/Finance/Etsy registrations or run migrations |
| `includes/REST/ShopAccessController.php` | Shared assignment provisioning surface if needed by the selected package. This task neither invokes it nor grants access |

The API-owned readiness probe now distinguishes **missing core**, **compatible narrow core**, and **cumulative guards present but unmapped**. Installed ShopAccess still must authorize every API/admin/panel capability directly. Absence of cumulative global guards does not bypass these handler checks. The proposed `ShopAccessAdminGuard::scopeForAction(string): ?array` is an optional pure owner handoff interface to report exact action scope; it is **not implemented or imposed on #1242 here**. If the owner chooses a different approved admission interface, adapt only the API-owned diagnostic consumer after agreement. Unverified cumulative admin admission keeps the UI collection control disabled.

## D. API-lane ownership and overlap

All original #1253 files are listed below. The current dependency task edits only API-owned diagnostics, scoped wrappers, tests and documentation. It does not change the two pre-existing shared-file overlaps.

| Path | Ownership |
| --- | --- |
| `assets/portal.css` | Shared surface: retain both lanes’ changes; no change in this task |
| `bin/product-intelligence.py` | Printify API / #1253 |
| `docs/PRINTIFY-PRODUCT-INTELLIGENCE.md` | Printify API / #1253 |
| `docs/PRINTIFY-WORDPRESS-API.md` | Printify API / #1253 |
| `docs/superpowers/plans/2026-11-03-product-intelligence.md` | Printify API / #1253 |
| `docs/superpowers/specs/2026-11-03-product-intelligence-design.md` | Printify API / #1253 |
| `includes/POD/PrintifyResearchBatch.php` | Printify API / #1253 |
| `includes/POD/PrintifyResearchGateway.php` | Printify API / #1253 |
| `includes/POD/PrintifyResearchMapper.php` | Printify API / #1253 |
| `includes/POD/PrintifyResearchReader.php` | Printify API / #1253 |
| `includes/POD/ProductIntelligence.php` | Printify API / #1253 |
| `includes/POD/ProductIntelligenceRepository.php` | Printify API / #1253 |
| `includes/Portal/Portal.php` | Shared surface: retain both lanes’ changes; no change in this task |
| `includes/Portal/PrintifyResearchControls.php` | Printify API / #1253 |
| `includes/Portal/ProductIntelligencePanel.php` | Printify API / #1253 |
| `includes/REST/PrintifyResearchController.php` | Printify API / #1253 |
| `tests/browser/product-intelligence.mjs` | Printify API / #1253 |
| `tests/offline/test_product_intelligence.py` | Printify API / #1253 |
| `tests/unit/PrintifyResearchMappingTest.php` | Printify API / #1253 |
| `tests/unit/ProductIntelligenceTest.php` | Printify API / #1253 |
| `tests/wordpress/PrintifyResearchTest.php` | Printify API / #1253 |
| `tests/wordpress/ProductIntelligenceTest.php` | Printify API / #1253 |

Main advanced through #1254 while this task ran. GitHub reports #1253 `mergeable_state=dirty`; the new main delta overlaps the API branch at `includes/Portal/Portal.php`. No merge/rebase or conflict resolution was performed because this is a cross-workstream surface. The branch package must not be deployed as a replacement for current main. Automatic PR CI does not run on the conflicted PR; the existing engineering audit is dispatched against the exact API branch SHA instead.

Current task adds `PrintifyResearchDependencies.php`, the read-only checker, manifest, this matrix and focused tests; it adjusts only the existing API-owned gateway/panel/controls/batch wrappers. `assets/portal.css` and `includes/Portal/Portal.php` are the only starting changed-path overlaps. For later integration, preserve both scoped stylesheet additions and the API lane's two existing panel-registration/mount lines alongside the shared portal. Do not overwrite the full portal or cherry-pick unrelated factory work.

## Selected integration and safety boundary

Consume exactly the 16 approved files from `b859d0078c68e8bcdc6c0732983969e81d3c9abe`; do not edit their bytes. Retain all ten main files. No cumulative #1242 code or guard mappings are imported.

The API-owned `PrintifyCatalogShopAdmission` attaches only to the imported catalog REST routes. It requires all four assigned Shop 2 research capabilities and a REST nonce before inspecting target identity. It resolves the exact existing grant or descriptor through the shared repository and requires `personalized_pod`, including for revocation. This supplies admission previously provided by the excluded global guard, without modifying the shared controller or installing a global policy. The original controller/repository continue to enforce owner permissions, body/query validation, grant/exception binding, reservations, audit, expiry and rate limits. Direct application consumers still require their own scope checks; registration does not create authority.

The API-owned panel registration connects that admission, existing catalog controller and research controller. No Plugin.php or Portal.php edits, schema hooks, owner provisioning, catalog approvals or live collection are performed. Provider-discovery batches remain bounded to one explicitly submitted existing providers scope per step, with at most ten requests per grant. Size-guide GET is available under the same governed catalog pipeline; its receipts use the existing mapper/importer. Costs, tax, missing rates and geometry certification remain unknown unless separately evidenced.

## Reproduction

```sh
python3 bin/check-printify-shared-dependency.py --repository . --profile narrow-core --php php
python3 -m unittest discover -s tests/offline -p 'test_*.py'
composer test
WP_TESTS_DIR=/path/to/wordpress-tests-lib composer test:wordpress
```

The read-only dependency probe checks the pinned source hashes and invokes no bootstrap, database schema, grants, exceptions, credentials or HTTP. `OWNER_AUTHORIZED_NARROW_INTEGRATION` records source-consumption permission only; all runtime/release policy booleans remain false. Offline tests also verify the installed 26 file hashes. WordPress tests use explicit synthetic signed shop ACL fixtures, never a provider credential or catalog grant/exception. REST denial tests cover unassigned administrators, foreign shop assignments, missing/invalid nonces, unsupported shop paths, forged reservations and privileged revocation denial before target access. Existing batch interruption/resume, receipt integrity, quota-denial and export tests remain in place.

## Remaining boundaries

- Main has advanced to `3758d1385f7d4a62947248822c1fe1c89ec9818e`; its independent Portal.php conflict is not resolved here. The designated portal/factory owner must handle integration with main.
- Source readiness does not verify installed schema, reviewed owner/provider connection, current shop assignment, live provider evidence or production readiness. No migrations or provisioning are authorized by this change.
- Research/provider gaps remain gaps; no new live evidence is collected. Drive access/import limitations recorded by the existing workstream remain unchanged.
- STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD. No merge, deployment, Finance activation, publishing, orders or live Printify authorization.
