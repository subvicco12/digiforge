# Printify API shared-dependency matrix and owner handoff

## Verified immutable inputs

| Ref | Verified SHA | State |
| --- | --- | --- |
| main at task start / API branch base | `0e15e4ccae430498c92a30ab1f8951171fa0ad13` | Verified before work |
| latest observed main | `7f30ad6aa37f58eca5302710c5f4583cabc45dfa` | Advanced during task through separate factory PR #1254; not merged here |
| API PR #1253 starting head | `c7d706e32396d81e0adc2f3552e9e4d106680293` | Existing draft/branch, 22 changed files against main |
| shared PR #1242 | `fac3634615c72ffdb0fdbce8ef1fbd3ae3c9e4c0` | Existing draft; 916 changed files; historical base `844107ab9baf7e1303cd47708e9eab3bc700b348` |

Source verification date: 2026-11-03. Final API SHA and exact-head audit are in PR #1253. No AGENTS.md was found. Existing dedicated workstream instructions remain binding. No edits, comments, grants, exceptions, migrations, merges or activation were made to #1242 or production.

Diff receipts: #1242 has 916 changed files against its historical base, 920 against main at task start and 960 against latest observed main. The API starting head has 22 changes against its branch base and 62 differences against latest main because the separate factory changes are not incorporated. All ten retained main dependency files remain byte-identical across the main advance.

The machine-readable [dependency manifest](printify-shared-dependency-manifest-v1.json) records exact selected SHA-256 values and Git blob IDs for every file below. **PENDING_SHARED_OWNER** is a diagnostic state, not permission to install, copy into the application, merge, deploy or execute. No shared source is vendored into #1253.

## A. Shared core required for catalog consumption — 16 files

Owner: **shared dependency / #1242 lane**. Consume once from an owner-approved immutable dependency commit. Fourteen authorization/ownership support classes plus the scoped client and authenticated route controller are represented below; the route controller is needed for the complete existing operator pipeline, not for invoking the repository from PHP.

| Exact path | Main delta | Required contract / responsibility | API lane action |
| --- | --- | --- | --- |
| `includes/Database/ProviderConnectionSchema.php` | absent | Existing connection evidence table definition/probe; no migration executed | Consume approved owner commit; do not edit/copy now |
| `includes/Database/QueryEvidence.php` | absent | Fail-closed mutable database-error inspection | Consume approved owner commit; do not edit/copy now |
| `includes/POD/PrintifyCatalogClient.php` | changed | Existing GET-only scoped transport; replaces the legacy client once | Consume approved owner commit; do not edit/copy now |
| `includes/POD/PrintifyCatalogGetSafetyExceptionContract.php` | absent | Separate grant-bound exception contract; existing limits unchanged | Consume approved owner commit; do not edit/copy now |
| `includes/POD/PrintifyCatalogReadContract.php` | absent | Exact GET scopes/envelope validation; size-guide extension belongs here | Consume approved owner commit; do not edit/copy now |
| `includes/POD/PrintifyCatalogReadPolicy.php` | absent | Concrete reserved-attempt authorization; no callback grants | Consume approved owner commit; do not edit/copy now |
| `includes/POD/PrintifyCatalogReadRepository.php` | absent | Grant/receipt lifecycle, reservations, immutable audits, quota and rate admission | Consume approved owner commit; do not edit/copy now |
| `includes/POD/PrintifyConnectionBoundary.php` | absent | Revalidates reviewed connection tuple before credential/dispatch access | Consume approved owner commit; do not edit/copy now |
| `includes/POD/ScopedProviderConnection.php` | absent | DF-OWN-001 current reviewed integration/account/shop relationship | Consume approved owner commit; do not edit/copy now |
| `includes/REST/PrintifyCatalogReadController.php` | absent | Existing authenticated grant/read/evidence routes; no new issuer in API lane | Consume approved owner commit; do not edit/copy now |
| `includes/Security/FirstPartyOwnerPolicy.php` | absent | Protected native OWNER identity alternative; no auto-provisioning | Consume approved owner commit; do not edit/copy now |
| `includes/Security/ReviewedLocalEvidence.php` | absent | Current independent review/revocation proof; no Finance path invoked | Consume approved owner commit; do not edit/copy now |
| `includes/Security/ShopAccess.php` | absent | Explicit assigned actor/shop/capability admission | Consume approved owner commit; do not edit/copy now |
| `includes/Security/ShopAccessAuthority.php` | absent | Independent OWNER/delegate authority verification | Consume approved owner commit; do not edit/copy now |
| `includes/Security/ShopAccessPolicy.php` | absent | Canonical shops and typed assignment vocabulary | Consume approved owner commit; do not edit/copy now |
| `includes/Security/ShopAccessRepository.php` | absent | Assignment lifecycle, independent authority and durable audit proof | Consume approved owner commit; do not edit/copy now |

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

## Smallest safe strategy

**Preferred:** the shared owner publishes/designates one approved, immutable **narrow core dependency commit or artifact manifest** containing section A, using section B unchanged. The owner includes the size-guide contract extension below and identifies the minimal bootstrap/schema prerequisites. API code consumes the same classes once after that approval; no parallel auth system, client, tables or copied grant logic. Approval to consume source is distinct from merge, deployment, migration and live execution authorization, all still absent.

This avoids the 916-file cumulative diff and its global admission collisions. API handlers already enforce fixed Shop 2 scope, WordPress nonce/capabilities and installed ShopAccess directly; ordinary global capabilities cannot substitute for an assignment. Scoped checks now fail closed on missing/broken authority implementations as well.

**Alternative:** if the owner requires the cumulative #1242 candidate, the owner also supplies the section C REST capability/target and admin-action mappings with its full prerequisites. #1253 must not inject an exemption hook, change guard priority, redefine those shared classes or claim routes are ready because classes merely exist. Existing overlapping portal/CSS changes require the owner's integration review.

Neither strategy is executed by this task. Current approved application dependency remains unavailable.

## Ready-to-send request to the #1242 owner

Please designate the consumable immutable narrow catalog dependency from section A (with existing main section B), or explicitly select the cumulative strategy. Confirm packaging/bootstrap ownership and publish its reviewed source/hash manifest. In that owner-controlled dependency:

1. Extend **only `PrintifyCatalogReadContract.php`** for request `{"kind":"size_guide","blueprint_id":<positive int>}` → **GET `/v1/catalog/blueprints/{id}/size_guide.json`**. `normalize()` must continue to use the exact endpoint allowlist, at most ten requests per grant and existing account/shop/expiry constraints. `select()` must validate bounded `sizes` and `types`, size/range order, nullable range bounds and source units. Empty `{"sizes":[],"types":[]}` is missing evidence, never certification. Reject extra URL/method fields, foreign scope, redirects and malformed/oversized envelopes. The generic scoped client/repository already delegate to endpoint/select; they need no alternate transport or grant-issuer rewrite for this extension.
2. Preserve `PrintifyCatalogGetSafetyExceptionContract` unchanged: its delegation to grant normalization picks up the new exact kind without expanding exception lifetime, lock semantics or request limits.
3. Add owner-lane contract tests for exact endpoint, empty/malformed guide, extra fields, invalid IDs, request ceiling11 denial, cross-shop/account denial and existing quota/reservation/audit/rate semantics. Existing API mapper tests already cover ordered size ranges/null bounds.
4. **Only if cumulative guards are selected:** map the six exact REST method/path combinations and four exact form actions below. Preserve fixed Shop 2 targeting, per-cap assignments, nonce checks and all underlying grant/exception gates. No wildcard path/action exemptions. Proposed pure action descriptor returns `{"shop":"personalized_pod","caps":[...]}` and must agree with the actual guard.

REST namespace base: `/digiforge/v1/shops/personalized_pod/printify-research`.

| Method | Exact relative path | Capabilities |
| --- | --- | --- |
| GET | `/readiness` | manage_digiforge + manage_digiforge_pod + manage_digiforge_research + manage_digiforge_connections |
| GET | `/snapshots/{64-lowercase-hex}` | Same four |
| POST | `/receipts` | Same four |
| POST | `/batches` | Same four |
| GET | `/batches/{64-lowercase-hex}` | Same four |
| POST | `/batches/{64-lowercase-hex}/step` | Same four; explicit confirmation and existing grant still required |

| Exact admin-post action | Assigned Shop 2 capabilities; handler nonce still mandatory |
| --- | --- |
| `digiforge_pod_intelligence_import` | manage_digiforge + manage_digiforge_pod + manage_digiforge_research |
| `digiforge_pod_intelligence_export` | manage_digiforge + manage_digiforge_pod |
| `digiforge_pod_research_prepare` | All four REST capabilities |
| `digiforge_pod_research_step` | All four REST capabilities; explicit confirmation and existing grant still required |

This owner request is prepared in **#1253 only**. No message/comment or change was posted to #1242, and no owner approval is presumed.

## Read-only composition evidence and reproduction

```sh
python3 bin/check-printify-shared-dependency.py --repository . --profile narrow-core --php php
python3 bin/check-printify-shared-dependency.py --repository . --profile cumulative-admission --php php
```

Requires the exact manifest Git objects already present locally; the tool never fetches or installs anything. It checks each source SHA-256, materializes class definitions only in a temporary test directory, and loads the API-owned diagnostic adapter against those pinned definitions. It never loads `Plugin.php`, registers hooks, calls schema migration/provisioning methods, creates grants/exceptions, reads credentials or contacts providers. PHP harness network entry points are tripwires; unauthenticated collection must deny. This is interface/admission compatibility, **not database readiness, signed ownership, provider or release acceptance**.

Observed at the pinned heads:

| Composition | Core methods | REST target/admission | Portal admission | Size-guide scope | Denied operation |
| --- | --- | --- | --- | --- | --- |
| Narrow core definitions + unchanged main dependencies | Compatible | Own fixed handlers, no cumulative global guards installed | Own assigned-capability handlers | Unsupported in shared pin | Unauthenticated collect → pod_research_account |
| Same core + current cumulative admission definitions | Compatible | All six routes unmapped / fixed target false | Four actions unmapped | Unsupported | Collect stops at pod_research_pipeline_unavailable |

Both probes reported zero HTTP calls, grants, exceptions and production writes. Focused tests additionally cover absent/partial dependencies, exact source pins/path safety, retained baseline files, nonce denial, foreign-shop/extra-suffix404, scoped capability denial, and no catalog option mutations. No shared source bytes changed.

**Remaining approval:** the #1242 owner must designate/approve the narrow shared dependency and size-guide contract change; if choosing cumulative integration, also approve/implement its admission mappings. No production authorization is requested or implied. STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD.

Local receipt: Composer legacy/unit/static/standards PASS (1,175 tests / 7,418 assertions); offline70 PASS; focused WordPress18 /79 PASS; mobile24 checks PASS at390/768/1280px. The usual local MariaDB11.8 baseline migration diagnostic remains unchanged; hosted exact-head CI is the final repository check. Review verified that admin-post handlers already delegate through Batch::allowed to all four assigned capabilities; the new common wrapper preserves that behavior and adds denial on partial/malformed authority rather than a second policy.
