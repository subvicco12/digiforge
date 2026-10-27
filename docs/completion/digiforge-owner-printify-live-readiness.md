# OWNER and Printify live readiness — 2026-10-27

## Exact source and decision

Existing draft [PR #1242](https://github.com/subvicco12/digiforge/pull/1242), branch `feat/google-drive-scoped-grant-contract-20261024`. Verified baseline head **86536471eafb337a6862619a3a5feff66ede4428**, [Engineering & Safety Audit #4813 PASS](https://github.com/subvicco12/digiforge/actions/runs/40455119632); main844107ab9baf7e1303cd47708e9eab3bc700b348. New engineering commit **c28b0ab44cc6af22903d343f37b4d7be5817e720** adds local source-capture qualification and a composed catalog acceptance regression. Report/ledger commit follows; final exact-head audit SHA/run/result is recorded on #1242 and issue1209 after publication, without an extra self-referential report commit. No previous audit is assigned to changed code.

**NO-GO for production installation, configuration, OWNER enrollment and live Printify.** This is deployment preparation plus executable offline qualification, not an installation or external-operation authorization. No runtime PHP, provider implementation, Finance, schema, installer or CI change is part of this batch.

## Live facts and evidence limits

| Item | Current evidence classification |
|---|---|
| DigiForge1.0.103, schema25/25, STOP ALL/external lock ON, automation OFF, shops[] | Owner-reported current state; not independently refreshed in this task. |
| Fresh permitted public health GET | Unauthenticated bounded GET to `/wp-json/digiforge/v1/health` failed with cloud transport URLError; no HTTP response/status/source hash obtained. Does not establish a site outage or verify production configuration. No credential/cookie was sent. |
| Authenticated portal or host source access | No usable authorized authenticated WordPress/host connection available in this task. No reset, mailbox request, eval, snippet, new credential or alternate bypass attempted. |
| Earlier source observation | Reused Oct26 report: six normalized source matches, two differences, FirstPartyOwnerPolicy absent, complete large Portal source unavailable. Masked/line-numbered output was not a byte-exact preimage. No repeated source audit. |
| Earlier database markers | Oct26 authenticated observation was canonical25/legacy23/provider1; current owner report is25/25. Source deliberately retains package/legacy marker23 while migration latest25. Neither number proves present table/index/data compatibility. Current raw state remains unverified; no marker repair proposed. |
| New first-party OWNER / catalog workflow | Audited source exists; actual installed compatibility, mailbox proof, bindings and live execution remain BLOCKED. |

Existing evidence: [prior installed-source report](digiforge-owner-dg2438-cloud-sprint.md), [narrow package/supplier report](digiforge-owner-supplier-certification-sprint.md), [low-cost catalog contract](digiforge-printify-low-cost-research.md). Supplier research was not repeated.

## Minimum OWNER-only source candidate

Reuse the existing deterministic [three-file review overlay](artifacts/digiforge-owner-007da8b-review.zip) and [exact manifest](artifacts/digiforge-owner-007da8b-manifest.json). It is not a standalone/cumulative plugin installer.29,892bytes; SHA-256 **8090fde4b5eafe23ddfdadfa3714557129558dfe1a4f3d77c9df331d39146243**. Source pin007da8b9d563567e5811f8ceb43b8732b48a5558 / audit4809 is the unchanged OWNER source reference; the current candidate's new code/tests require their own audit.

| Proposed operation | Payload | Candidate SHA-256 | Actual raw production preimage |
|---|---|---|---|
| ADD only after proven prior absence | includes/Security/FirstPartyOwnerPolicy.php | 9b308fb2f1b0b6f01dcd42cdc3ba7813a4c26c2aeaeda080b6fdce70b3739416 | UNKNOWN; historical404 is not a fresh authenticated inventory. |
| Reviewed coherent REPLACE | includes/Security/ShopAccessAuthority.php | 075c431a3106bdd0d3e4e6979fe7ea858f147c24c356ce34fb4cd770c03424e0 | UNKNOWN; exact original bytes/metadata required. |
| Reviewed coherent REPLACE | includes/Portal/ShopAccessPanel.php | 171d9e97d250c987332bb6e72156a1d9fd0f453942de9b630ff0e2fc08124105 | UNKNOWN; exact original bytes/metadata required. |

Thirteen existing dependencies are hash references, not payload: digiforge.php; Core/Plugin and Capabilities; Security/ShopAccess, ShopAccessPolicy, ShopAccessRepository, ShopAccessAdminGuard, Logger; Database/Tables; Portal/ReviewConfirmation, Portal; Orders/Validator; REST/ShopAccessController. Exact hashes/sizes are in the manifest, reverified against current committed source. Full installed autoload/boot/portal compatibility remains unproven. If any reference differs, review the actual difference and stop this narrow scope; do not silently add cumulative/POD/Google/Finance source.

### New read-only private-capture qualifier

`python3 bin/package-first-party-owner.py --inspect-capture <PRIVATE_LOCAL_PLUGIN_SOURCE_COPY>` reads only those16declared paths. It follows no symlink components, uses anchored directory descriptors, rejects hardlinks/FIFOs/nonregular/oversized files and detects per-file changes during reading. It emits canonical path/hash/size/captured-copy-mode and review classifications only. It does not read wp-config.php, credentials, arbitrary extra files, database records or remote sources; no PHP source is executed. It does not write/extract/install or alter capture contents. The path is a privately collected local copy, not a request to connect to production.

Missing replacement preimages/dependencies, dependency drift or an unexpected existing ADD target produce MISSING_OR_DRIFTED. Complete local copies can show LOCAL_FILES_PRESENT_DEPENDENCIES_MATCH only. Every report still has production_source_verified=false, installed_dependency_closure_verified=false, production_absence_verified=false, mailbox_possession_verified=false, rollback_tested=false and deployment_authorized=false. CLI exits2 after every capture inspection, so it cannot serve as an automated deployment-success signal. Source hashes do not prove capture origin, permissions on the live host, cross-file checkpoint consistency, malware absence or restore success. No authentic production capture was available or fabricated here.

The existing `--verify` archive mode still succeeds against the unchanged ZIP. New capture inspection creates no larger OWNER package. Keep capture output and original source bytes private; do not commit production preimages or protected configuration to GitHub.

## Protected first-party configuration procedure — NOT EXECUTED

1. Obtain separate exact authorization for protected configuration custody. Establish genuine mailbox control for user1/subvicco12@gmail.com through an independently trusted, private verification receipt. Record verifier, method, timestamp and evidence reference privately. A profile email, administrator role, successful login or checksum is insufficient. Do not request/export reset links, passwords or tokens; no password-reset or mailbox operation is authorized here.
2. The trusted server custodian must freshly verify ID1, exact mailbox, user_login, user_registered, existing user_pass hash, administrator membership, all15 current user capabilities and all15 stored administrator-role capabilities. Do not alter roles/caps to make this pass. The stored password hash is used in memory only by the existing policy and never exported to chat/logs/reports.
3. Reuse `FirstPartyOwnerPolicy::fingerprint()` on that fresh server identity. Exact algorithm hashes UTF-8 JSON array `['1', user_login, user_email, user_registered, user_pass]` using the existing unescaped-Unicode/slash encoding. Compute installation_sha256 from exact `site_url('/') . '|' . $wpdb->prefix`, not a guessed homepage URL, other environment or Printify grant site hash. These are separate identity contracts.
4. Prepare, but do not install here, `DIGIFORGE_FIRST_PARTY_OWNER` in the approved protected bootstrap before plugin loading. It has exactly five fields: version(integer1), account_sha256(lowercase64hex), installation_sha256(lowercase64hex), email_verified_at(genuine positive UTC epoch integer), expires_at(explicit future UTC epoch integer). No extra fields or placeholders can be accepted at runtime. Expiry must be after now and at most365days after genuine verification. Custodian/method/evidence/lease end require actual owner decisions; no default timestamp or lifetime was minted.
5. Inspect existing protected constants and legacy signed OWNER/DELEGATE authority before any change. Configured native OWNER is exclusive; independently valid DELEGATE records remain separate. Invalid/expired/drifted native configuration denies without legacy OWNER fallback. Removing the native constant can restore legacy signed OWNER handling, so rollback must not blindly unset it or resurrect retired authority. No new signing system or repeated approval of already approved DF-OWN policies is required.
6. Preserve STOP ALL/external lock ON, automation OFF and RELEASE HOLD. This root is a local administrative authority, not a provider connection, product approval, production authority or Finance activation. Fresh identity/role/capability reads and pin invalidation on changed identity are already implemented. Test intentional invalidation in disposable fixtures, not by changing production user1/password/roles.

## Compatibility / backup / rollback manifest — BLOCKED

Required before separately authorized installation: authenticated fresh original bytes/hash/length/path/presence/ownership/permissions for all three targets, raw comparison of all13references and complete relevant boot/portal closure; actual PHP/WordPress/plugin/environment compatibility; protected authority/configuration inventory with private custody; matched filesystem/database/artifact checkpoint identifiers; existing user/role/options/audit/table transactional compatibility; a separately authorized isolated restore result. No raw preimage, backup location, host command success, restoration result or installed permission was invented.

Proposed eventual source order after approval: verify staged three-file checksums and originals; add the policy before a new authority/panel can reference it; replace authority and panel coherently using the host's approved atomic deployment procedure; verify raw readback and boot compatibility before any protected pin/enrollment. Do not upload the overlay or cumulative PR artifact through the WordPress plugin installer, run activation/deactivation/migrations, replace dependencies or change settings as part of this three-file scope. Source installation alone does not establish OWNER access.

Rollback remains NOT TESTED: retain original coherent two-file preimages, proven prior ADD absence, original modes/ownership and protected configuration/authority checkpoints. Restore only the reviewed coherent code/configuration set in dependency-safe order, avoiding legacy-authority resurrection. Remove a new policy only if its original absence is proven. Preserve append-only grants, revocations and audits. If enrollment/other writes occurred, stop for history-preserving recovery; do not blindly restore an older database, delete grants/audits or perform cleanup. No restore, deletion or rollback action is authorized by this report.

## Exact post-install OWNER enrollment/readback — FUTURE SEPARATE AUTHORIZATION

- Use the actual user1 website session and website access panel `/?df_view=access`. Inspect the protected root readback and four canonical scopes: digital, personalized_pod, standard_pod, jewelry. Before enrollment, shops[] is expected: the root never creates implicit assignments. Anonymous/unassigned/foreign actors must remain denied. Independent negative identity/nonce/replay/audit-failure tests already pass locally/hosted; do not mutate production identities to repeat them.
- Verify the existing15capability contract: manage_digiforge; manage_digiforge_products; manage_digiforge_digital; manage_digiforge_research; manage_digiforge_ai; manage_digiforge_production; manage_digiforge_pod; manage_digiforge_listings; manage_digiforge_orders; manage_digiforge_finance; manage_digiforge_automation; manage_digiforge_connections; manage_digiforge_settings; publish_digiforge; view_digiforge_analytics. Native policy requires their genuine presence in user and stored administrator role; no capability setter is proposed.
- After separate authorization for each exact enrollment, use the existing ENROLL form: current generated nonce for digiforge_portal_access; action digiforge_portal_access; exact shop; existing administrator role; explicitly selected capability list; current source approval hash from protected policy; generated unique request_id; fresh expected_revision; bounded form expiry; checkbox confirmation CONFIRM_EXPLICIT_SHOP_ACCESS. No subject_id for self enrollment. Values come from fresh trusted readback, not this report. No field is pre-approved, no real nonce/hash/expiry was generated here.
- Exactly four independently reviewed local enrollment intents if allfour OWNER administrative scopes are authorized. Re-read revision between actions; never replay a previous POST or reuse a stale revision. Server enforces nonce/confirmation, current root, approval binding, CAS/replay and immutable audits transactionally. Negative POST/replay/race trials belong in the disposable fixture, not speculative writes against the live account.
- Read authenticated `GET /wp-json/digiforge/v1/shop-access/shops?capability=<each-of15>` with the current WordPress session/REST nonce and compare the explicitly enrolled scopes. Read `GET /wp-json/digiforge/v1/shop-access/grants?shop_key=<canonical-shop>` for each with nonce, record exact revision/grant ids/caps/expiry/audit linkage, business_access_granted=false and external_execution_authorized=false. Check desktop/mobile/keyboard access panel and selected-shop navigation, with no extra assignment or activation action.
- Four OWNER scopes do not activate Standard POD/Jewelry or provider authority. Digital must stay independent of POD. Finance capability existence does not authorize Finance processing; operational exclusion persists. Verify live STOP ALL/external lock/automation posture and all provider/order/publish controls remain closed. Installed-site acceptance is still unperformed.

## Separate Printify installation/readiness gate

The OWNER overlay deliberately excludes the catalog implementation. Do not expect its three files to install the research workflow. Audited research delta consists of PrintifyCatalogClient, PrintifyCatalogReadContract, PrintifyCatalogReadRepository, PrintifyCatalogPreview, new PrintifyCatalogResearch and REST/PrintifyCatalogReadController. Existing Core/Plugin registration/autoloading, Settings, business/store/program registry, ScopedProviderConnection and reviewed ownership schema/evidence, integration Repository/CredentialVault, ShopAccess, Logger, options/audit storage and related contracts must already match or be independently reviewed for a separately scoped installation. These production hashes/migrations/connection records are UNKNOWN. No minimal deployable Printify ZIP or cumulative release is certified here; do not install a six-file subset without transitive compatibility proof.

## Bounded live catalog acceptance — PREPARED, NOT AUTHORIZED OR EXECUTED

Prerequisites: authenticated installed code/dependencies and current main-site binding; independently verified personalized_pod environment/business/store/account/shop/integration relationship and credential fingerprint; existing encrypted custody, current role/shop assignments, valid provider account and no revoked/drifted evidence. Reuse existing connection/token; do not create a token, renew authorization or infer ownership from store28930593. Missing prerequisites deny before dispatch.

Proposed exact plan:8requests from `PrintifyCatalogResearch::requests(49,[410,217,61])`, maximum8fresh dispatches, one independently selected expiry within the existing one-hour limit, no wildcard/query pagination/redirect/retry or mutation authority. Each execution uses a unique Idempotency-Key, current REST nonce and exact request_index. The first proof reads use max_age=0; subsequent receipt/cache readback uses max_age300and must add0dispatches. No header/token/body is placed in chat or this report.

| Index | GET-only endpoint |
|---|---|
|0|/v1/catalog/blueprints/49.json|
|1|/v1/catalog/blueprints/49/print_providers.json|
|2|/v1/catalog/blueprints/49/print_providers/410/variants.json|
|3|/v2/catalog/blueprints/49/print_providers/410/shipping.json|
|4|/v1/catalog/blueprints/49/print_providers/217/variants.json|
|5|/v2/catalog/blueprints/49/print_providers/217/shipping.json|
|6|/v1/catalog/blueprints/49/print_providers/61/variants.json|
|7|/v2/catalog/blueprints/49/print_providers/61/shipping.json|

Under current STOP ALL/external lock the ordinary path must deny with0dispatches. A future live pilot additionally requires separate exact owner authorization and the existing grant-bound catalog-GET safety-exception receipt, with true acknowledge_catalog_get_only/acknowledge_no_mutations/acknowledge_locks_remain_on and explicit expiry no later than the grant. Its existing main-site/protected safety posture checks remain mandatory. That narrowly governed admission changes no global flag and grants no ordinary credential/order/production/publishing authority. This mission grants no such live exception and creates no approval. If it is not separately approved, the live result is BLOCKED, not permission to turn locks off.

Record original-response SHA-256, retrieval time, exact endpoint/request/grant/account/environment/integration scope, quota/dispatch/audit references and existing source receipts privately. Account-wide50/fixed-minute slots (at most100rolling-minute within the managed installation/environment), response1MiB/1000record bounds, single dispatch and current pre/post-response authorization remain.401/403/429/5xx, malformed/foreign/partial responses, uncertainty or drift stop that intent; no repeated failed request/new token to force success. Never call product/shop mutation, order, publish, price, upload or production endpoints. API shipping discovery is a method list, not rates; any actual method/destination read needs a separate explicit future scope.

Compare returned variants/decoration methods/catalog rectangles only as API observations. DPI, physical scaling, safe/bleed applicability, tolerances, actual account/store assignment, licensing, buyer editing and supplier/product certification remain independent. DG2-438 and historical sweatshirt6ad647d24f4a65e4300211e5 mapping remain unproven. No new supplier research, variants, prices or approvals were invented.

## Executed engineering evidence

- Capture qualifier5initial missing-feature errors RED;19package/capture tests GREEN, including unchanged archive, private-copy-only status, missing/drifted dependencies, unexpected ADD target, root/parent symlinks, hardlinks/FIFO/size bounds, per-file mutation, metadata-only output and CLI blocked status. Read-only independent review found no important defect; no private capture examined by reviewer.
- Composed isolated WordPress/MariaDB pilot:1test/101assertions PASS; eight fresh mocked allowlisted GETs, all source receipts UNVERIFIED/no mutation authority, zero before exception, zero additional on cache readback, denied extra index and revoked grant, STOP ALL still ON/Printify runtime disabled. No actual provider traffic.
- All catalog suites:54tests/971assertions PASS. Existing missing-audit regression exposed WordPress's object_id default integer format; fixture now supplies explicit string formats and still asserts exact removal and subsequent evidence/cache denial. This corrects a nondeterministic synthetic corruption target, not production audit behavior. Canonical request-key ordering is checked without treating JSON dictionary insertion order as semantics.
- Unchanged native OWNER59/1605, unit1828/9737(skip1), WordPress1433/24149(skip2), offline185/browser60/security/package evidence from baseline4813 is reused for unchanged code, not called a new candidate audit. Full new exact-head audit is required after publication; final receipt is on PR/issue after report commit. No redundant broad research or full local suite repeated.

All22non-Finance UNVERIFIED,23Finance-dependent separately excluded,500identities/20review waves remain. No acceptance criterion promoted.

## Remaining owner/external decisions and critical path

1. Authenticated fresh private source inventory/preimages, complete compatibility and matched backup/isolated restore evidence; no missing byte, timestamp or successful recovery claim may be supplied by a synthetic fixture.
2. Approved protected custodian, genuine mailbox-control verification receipt and explicit OWNER lease; separately authorize exact three-file installation and protected configuration, then each explicit local enrollment/readback. Already approved engineering policies need no duplicate approval.
3. Independently qualify the separate installed Printify dependency closure and actual shop/account/integration ownership. Separately approve only the exact bounded8GETpilot plus its grant-bound safety exception if desired. Existing locks remain ON; no provider mutation or broader activation is part of that decision.
4. Actual selected variant/placement/geometry/method/cost/shipping/rights/native/linguistic/physical evidence and installed-site acceptance remain separate certification work. No additional SaaS is needed for the prepared API workflow.

STOP ALL ON; external lock ON; automation OFF; Finance operational exclusion; Standard POD/Jewelry inactive; RELEASE HOLD/NO-GO. No production deployment/write, merge, password reset, OWNER grant, protected configuration modification, Printify live request, credential use, product/listing/order/price mutation, publishing or activation occurred.
