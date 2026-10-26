# OWNER release-readiness / DG2-438 supplier certification sprint

2026-10-26 UTC. **DRAFT / RELEASE HOLD / NO-GO.** Repository subvicco12/digiforge; existing draft [#1242](https://github.com/subvicco12/digiforge/pull/1242), same development branch. No Windows desktop access, production write, merge or deployment is assumed.

## Exact engineering and evidence boundary

- Verified main: `844107ab9baf7e1303cd47708e9eab3bc700b348`.
- Verified task/source baseline: `007da8b9d563567e5811f8ceb43b8732b48a5558`, [full exact-head audit #4809 PASS](https://github.com/subvicco12/digiforge/actions/runs/40267811278).
- New packaging engineering commit: **`73c83345b427a46f3f5d377fa81bc6235a7e15fe`**. The report, ledger and supplier evidence are committed afterward. A new exact-head hosted audit is required for the final candidate; at report-commit time it has not yet run. Its exact final SHA/run/result is recorded in #1242 and issue #1209 after execution, without changing the audited tree to insert its own SHA. #4809 is source-reference evidence, not a PASS for future code.
- Existing repository guidance, first-party OWNER policy, previous cloud report, acceptance ledger and Master500 source were reused. No applicable AGENTS.md was found. No new branch or PR; no Finance implementation, runtime PHP, schema, installer or CI workflow was changed.

## Delivered narrow OWNER package

[Review ZIP](artifacts/digiforge-owner-007da8b-review.zip) · [exact source/dependency manifest](artifacts/digiforge-owner-007da8b-manifest.json) · [archive checksum](artifacts/digiforge-owner-007da8b-review.zip.sha256).

ZIP SHA256: **`8090fde4b5eafe23ddfdadfa3714557129558dfe1a4f3d77c9df331d39146243`**;29,892 bytes. Exactly six archive members: three PHP payloads plus MANIFEST.json, SHA256SUMS and INSTALL-REVIEW.md. The PHP bytes come from exact audited Git blobs at007da8b; no mutable working-tree, cumulative package or external source is copied.

| Payload relative to the existing digiforge plugin root | Treatment | Exact SHA256 |
|---|---|---|
| includes/Security/FirstPartyOwnerPolicy.php | ADD only with a proven absence preimage | 9b308fb2f1b0b6f01dcd42cdc3ba7813a4c26c2aeaeda080b6fdce70b3739416 |
| includes/Security/ShopAccessAuthority.php | REPLACE only with exact original backup | 075c431a3106bdd0d3e4e6979fe7ea858f147c24c356ce34fb4cd770c03424e0 |
| includes/Portal/ShopAccessPanel.php | REPLACE only with exact original backup | 171d9e97d250c987332bb6e72156a1d9fd0f453942de9b630ff0e2fc08124105 |

`bin/package-first-party-owner.py` builds and independently verifies the archive. It requires these exact source hashes, reads one current committed HEAD, and refuses payload or declared-dependency drift relative to the pinned source. It never reads mutable source checkout bytes and never infers production state. The manifest records hashes for13 **existing dependency references**, not additional payload. Those include bootstrap/portal registration, capabilities, shop-access repository/policies/guard, logger, audit tables, REST controller, review confirmation and its validator. This is not a claim of complete installed dependency closure.

The archive is reproducible using fixed metadata and stored bytes. Verification checks the exact member set, regular-file mode, bounds, CRC and complete byte equality with rebuilt source. It rejects a cumulative plugin ZIP, extra POD/Google/Finance/bootstrap files, missing/duplicate/traversal paths, symlinks, changed code/checksums/manifests, fabricated approval flags and appended content. The CLI writes only a new local .zip with exclusive creation, never overwrites, extracts or installs. No updater, standalone plugin header, wp-config.php, signing material, account pin, grants, database migration or activation hook is included.

```sh
python3 bin/package-first-party-owner.py --verify docs/completion/artifacts/digiforge-owner-007da8b-review.zip
python3 bin/package-first-party-owner.py --output /tmp/new-owner-review.zip
```

The second command requires a new local filename. It is not a deployment command. **Package-scope verification: VERIFIED LOCAL. Installation readiness: BLOCKED.** Manifest deployment_authorized, rollback_tested, mailbox_possession_verified and installed_dependency_closure_verified remain false; raw installed preimage hashes remain null. Successful ZIP verification cannot change those facts.

## OWNER behavior, installation prerequisites and rollback

The existing source still supports all four canonical scopes (`digital`, `personalized_pod`, `standard_pod`, `jewelry`) and all15 existing administrative capabilities. Capability names in the manifest are prerequisites, not new grants. Digital/Personalized POD remain the active business scope; Standard POD/Jewelry stay inactive. Finance is operationally excluded even though its pre-existing capability remains part of the root's15-capability integrity contract.

Fresh account/role/capability reads, strict user1/mailbox/installation binding, bounded genuine mailbox verification, explicit enrollment, nonce/confirmation, exact approval hash, revision/CAS/replay and transactional immutable audit remain unchanged. Current committed source checks do not replace production identity or ownership proof.

Declared runtime prerequisites: compatible DigiForge1.0.103 architecture, WordPress7.1+, PHP8.3+, complete referenced class/autoload/portal contracts and actual existing options/audit/table compatibility. The source-reference installation used canonical schema25, legacy/package marker23 and provider schema1; these numbers are not migration instructions or proof of the current database. No generic administrator/email fallback, role setter, self-pinning route or new privilege is introduced.

Fresh read-only production health during this sprint remains1.0.103, STOP ALL=true, external lock=true, automation=false. Shop discovery still returns[] with implicit_access=false and external_execution_authorized=false. These are API observations, not installed OWNER/browser acceptance.

The prior dated source receipt showed six normalized matches, two differences, an absent first-party policy/constant and a large portal beyond the connector's64KiB reader limit. Its full candidate hashes and UNKNOWN raw installed hashes remain in [the prior receipt](evidence/owner-dg2438-cloud-readiness-20261026.json). That masked/line-numbered source is not a byte-exact preimage. It was not relabelled as fresh raw source or a tested restore. No blocked eval, code snippet, password/reset link or production configuration workaround was attempted.

Before separately authorized installation:

1. Capture fresh raw bytes, SHA256, length, permissions, exact path and presence/absence for all three payload targets and verify the13 dependency references plus complete boot/portal compatibility. Existing dependency code is **not shipped**, so mismatch or missing evidence blocks installation.
2. Establish an approved, retrievable, consistent source/configuration/database/protected-artifact checkpoint and isolated restore evidence. No backup or restore was executed here. Never use normalized text or a version label as a rollback backup.
3. Privately verify actual user1/subvicco12@gmail.com mailbox control through the existing WordPress mechanism only under separate authorization for any mail/reset. Never request/expose token, password or user_pass. The owner authenticates on the exact HTTPS origin; profile email or an API session alone is insufficient.
4. Authorized protected custody computes the account/installation pin after any reset and installs the exact five-field configuration with genuine verification time and bounded expiry (maximum365days). Protected configuration, signing/delegate/obsolete OWNER state and source changes require a coherent reviewed transition. No such value or grant is supplied by the ZIP.
5. Freeze writes and separately authorize the exact three-file application. The new policy must not be absent while its callers are live. Verify safety locks and explicit local enrollment afterward, then genuine authenticated desktop/mobile/keyboard and revocation/cross-shop acceptance. Global views need explicit four-shop access; this never activates future shops or providers.

Rollback requirements, **not tested**: retain and restore the coherent two-file raw preimage set; remove the added policy only with proven prior absence. Restore only the approved compatible protected authority/configuration state, without resurrecting retired signed OWNER records. Preserve append-only audit and revocation history. If grants or other writes occurred, stop for independently reviewed history-preserving recovery rather than blindly restoring an old database or deleting records. No automatic rollback, deletion, erasure or retention job was added.

## DG2-438 decision matrix

All source identities and the fixed wave18 selection remain unchanged. Historical store28930593/product6ad647d24f4a65e4300211e5 is DYNAMIC_CHOICE; selected sizes conflict8/9 and exact colors are incomplete. **That observation is not a verified DG2-438 mapping, nor evidence of blueprint49 or provider99.** Gildan18000/blueprint49 is a research candidate only.

Reuse the previously captured official public [blueprint49 provider list](https://api.printify.com/v1/catalog/blueprints/49/print_providers.json), its18 public records and representation hash; do not repeat the prior401 variant request or borrow credentials. The existing catalog contract already accepts that observed provider schema offline, so no new supplier adapter/authority system was justified.

In this table, VERIFIED refers only to the stated public catalog field; the decision for each actual product remains UNVERIFIED/BLOCKED.

| Research candidate | VERIFIED public observation | UNVERIFIED / BLOCKED fields | Decision |
|---|---|---|---|
| Blueprint49 / Monster Digital29 | DTG; reported average production1.1 business days; prior public shipping page US/Mexico centers/carriers | Exact size/color IDs, SKU, placements/geometry/DPI/safe/bleed, selected price/shipping; printed embroidered-look artwork and rights | UNVERIFIED research option; not historical product assignment |
| Blueprint49 / Fulfill Engine217 | DTG, embroidery, puff/metallic/glitter print; reported average2.6 business days; new public shipping page lists USPS/Asendia/OnTrac | Location conflict SC versus NC; dynamic shipping table missing; selected variant, method/placement/geometry, cost and actual embroidery/digitization suitability | UNVERIFIED; supplier certification BLOCKED |
| Blueprint49 / Printful410 | DTG, embroidery, DTF; reported average1.3 business days | Exact chosen variant/method/placement, region/cost/shipping, fonts, rights and physical/native review | UNVERIFIED alternative; no inferred Printful/Printify account binding |
| Blueprint49 / Printify Choice99 | Catalog DTG; reported average1.5 business days | Actual fulfilling factory/routing/scaling, all selected variant/placement and shipping/cost evidence | UNVERIFIED dynamic route; never a fixed-provider substitute |
| Historical sweatshirt -> DG2-438 | Only attributed historical UI identity/scope retained | Authentic source-to-program/product/version/variant/SKU mapping absent; conflicting options | BLOCKED, not proven compatible or incompatible |

No numeric variant ID, price, cost or margin was invented. Public averages are not delivery guarantees, paid expenses or selected-product quotes. Garment size measurements are not printable rectangles. The earlier T-shirt3951x4800 observation, Monster T-shirt4500x5100 and five embroidery guides are not this sweatshirt's geometry. Exact front/back/other placements, safe/bleed applicability, DPI, scaling and tolerances remain UNVERIFIED.

### New qualified public source

[Official Fulfill Engine shipping page](https://printify.com/shipping-rates/fulfill-engine/) returned200 in the permitted public read-only workflow. It says **United States, South Carolina** in its summary but **United States, North Carolina** in the fulfillment-center detail. State selection is UNVERIFIED due conflict; neither value was silently chosen. The rendered rate section still says “Loading shipping rates…”. Selected destination/method/first/additional-item rates remain UNKNOWN; a generic regional surcharge does not supply them. [Sanitized source/matrix receipt](evidence/owner-supplier-certification-20261026.json) records the retrieved representation hash, not an original raw-HTTP hash.

Official embroidery guidance from the prior report distinguishes actual thread/digitization from printed texture and permits provider transformations that can alter details. Generic minimum text/line requirements do not certify this product. FC01's retained Noto Serif Regular2.007/OFL and exact-text renderer remain diagnostic components, not an approved embroidered-look or stitch-ready design. Required family_name, optional ESTABLISHED omission, exact Unicode,48-code-point limits, unsupported-glyph/overflow rejection and preview/final source parity are unchanged. No auto-shrink, glyph substitution, font/style revision or native Printify activation occurred. Licensed artwork/mockups, independent artistic/linguistic, native and ordinary-buyer/physical evidence remain separate blockers.

## Tests, independent review and acceptance

| Check | Executed local result |
|---|---|
| Missing narrow packager reproduction | RED:7 initial capability failures |
| Stale committed-source reproduction | RED: old snapshot could still build after a new committed OWNER change |
| Focused package security/regression tests | PASS:11 tests, including real temporary-Git drift and CLI behavior |
| Native OWNER/access suite | PASS:59 tests /1604 assertions, all four-shop/15-capability coverage retained; expected injected audit-failure diagnostics |
| Full PHP8.3 unit suite | PASS:1821 tests /9704 assertions;1 existing optional skip |
| Full pinned offline suite | PASS:180 tests |
| Acceptance checker and diff checks | PASS; no criterion promotion |
| Independent read-only review | No Critical/Important issue. Minor tamper-fixture metadata issue corrected, then11/11 independently rerun PASS |
| Actual production installation, mailbox verification, grants, restore, provider certification | NOT RUN / BLOCKED; not inferred from local tests |

The new code changes packaging/verification only. Runtime OWNER controls and supplier contracts were reused rather than replaced. Complete exact-head hosted audit is required on the final saved candidate; successful baseline/source audits are not automatically reassigned.

### Individual22-criterion reconciliation

| Criterion | Disposition |
|---|---|
| `BP-seam-panel-render` | UNVERIFIED: exact reviewed sweatshirt panel/seam/safe/bleed evidence missing. |
| `BP-ai-value-routing` | UNVERIFIED: approved deterministic-first direction retained; authentic benefit/provenance/review evidence missing. |
| `BP-template-qa-spelling` | UNVERIFIED: exact text/local QA retained; genuine linguistic and selected-artifact review missing. |
| `BP-printify-shop-scope` | UNVERIFIED: historical scope pin retained; current account/integration/product authority missing. |
| `BP-distinct-provider-shops` | UNVERIFIED/DEFERRED: no future Shop3 provider identity or activation inferred. |
| `BP-etsy-shop-identity` | UNVERIFIED: current provider account/OAuth evidence not supplied; no renewal. |
| `BP-portal-4-provider-connections` | UNVERIFIED: actual bindings and installed owner/browser acceptance missing. |
| `BP-portal-7-mockups` | UNVERIFIED: exact variant/panel/UV/rights and independent review missing. |
| `BP-portal-7-supplier-readiness` | UNVERIFIED: public method matrix qualified; exact variants/geometry/cost/shipping still BLOCKED. |
| `BP-portal-11-production-authorization` | UNVERIFIED: package explicitly grants no production authority; no approval or execution. |
| `BP-portal-17-conversion` | UNVERIFIED: typed metrics implementation retained; authentic attributed cohorts/source records/completeness absent. No performance invented. |
| `BP-portal-17-defect-quality` | UNVERIFIED: typed metrics implementation retained; authentic attributed cohorts/source records/completeness absent. No performance invented. |
| `BP-portal-17-supplier-performance` | UNVERIFIED: typed metrics implementation retained; authentic attributed cohorts/source records/completeness absent. No performance invented. |
| `BP-portal-17-product-performance` | UNVERIFIED: typed metrics implementation retained; authentic attributed cohorts/source records/completeness absent. No performance invented. |
| `BP-portal-17-shop-performance` | UNVERIFIED: typed metrics implementation retained; authentic attributed cohorts/source records/completeness absent. No performance invented. |
| `BP-live-portal-normal-work` | UNVERIFIED: narrow package now reproducible; actual installation, enrollment and authenticated browser journey remain BLOCKED. |
| `BP-secret-no-chat` | UNVERIFIED external process boundary; no credential/reset material requested or exposed. |
| `BP-quality-feedback` | UNVERIFIED: approved reviewed-feedback contract retained; authentic attributable records missing. |
| `BP-digital-semantic-content` | UNVERIFIED: prior exact-file owner decisions retained; no repeat approval requested or new criterion proof. |
| `BP-digital-semantic-visual` | UNVERIFIED: independent complete visual/buyer/installed evidence missing. |
| `BP-digital-semantic-ip` | UNVERIFIED: source-specific third-party entitlements and complete commercial review remain external. |
| `BP-digital-semantic-policy` | UNVERIFIED: existing terms decisions retained; complete criterion evidence not inferred. |

Counts remain246/268 bounded implementation candidates +22 UNVERIFIED;23 Finance-dependent criteria separately excluded. All500 identities and20 waves remain, including360 bounded recipes/140 artistic-execution-blocked concepts. Packaging progress is not a new commercial acceptance count.

## Remaining genuine critical path

1. Fresh protected-host raw checksums/preimages and complete dependency/restore evidence, plus exact separate installation authorization. No cumulative release ZIP should substitute for this reviewed three-file overlay.
2. Genuine private mailbox verification and protected identity/installation configuration, explicit local enrollment and installed authenticated desktop/mobile/keyboard/revocation acceptance. No passwords/tokens in chat; no signing or policy approval restart.
3. Authentic current shop/product/blueprint/provider-or-Choice/variant/SKU/placement/version mapping, geometry/applicability, selected destination shipping/cost and current source evidence. Resolve the Fulfill Engine location conflict if selecting that provider; do not infer IDs from names.
4. Exact artwork/method/rights/linguistic/native/buyer/physical review and separate product/production approval. Existing ten policy directions and prior exact Digital owner approvals need not be requested again.

STOP ALL ON; external lock ON; automation OFF; Finance operationally excluded; Standard POD/Jewelry inactive; RELEASE HOLD / NO-GO. No production changes, protected configuration writes, password reset, OWNER grant, provider activation/mutation, listing, publishing, order, payment, merge or deployment occurred.

### Preserved failed audit and correction

[Audit #4810](https://github.com/subvicco12/digiforge/actions/runs/40291969475) failed the complete-history credential scan after the preceding unit, WordPress, offline and browser suites passed. The generic-key rule matched the public ShopAccessPanel source checksum beside its path. Source pins now use explicit path/sha256 records, without scanner exemptions or weakened checks. Only this sprint's two new commits were rebuilt over unchanged audited baseline007da8b; the failed run remains historical evidence. Final exact-head audit evidence is recorded in #1242/issue1209 after publication.

## CI dependency acquisition correction — 2026-10-26

Current continuation base: e755e8b4cefa329e64fd5e228dfeb8104fa77106; CI engineering commit: **396fcb773636346feaec1b7485e3f12c9ae2edb2**. Existing draft #1242 and cumulative ancestry retained. [Audit #4811](https://github.com/subvicco12/digiforge/actions/runs/40294570630), attempts1/2, is CANCELLED/BLOCKED, not PASS: both reached the unchanged20-minute limit downloading Chromium system dependencies from Azure's Ubuntu mirror. Browser tests had not begun; subsequent Action Scheduler/security/HTTP/package gates were skipped. Both attempts remain historical evidence. Hosted unit/offline/OWNER/WordPress/catalog successes are partial evidence, not complete certification.

Reproduction: bounded public read of262144bytes from the corresponding official archive.ubuntu.com package returned200 in0.92seconds in this cloud environment; Azure route returned a network error. This measures this environment only, not hosted-runner performance. The CI helper replaces Ubuntu mirror-list/Azure URIs with official HTTPS archive.ubuntu.com/security.ubuntu.com sources before the first APT operation. Existing suites, components, signing keys and package signature/hash verification are preserved. Vendor source files remain unchanged. The executable refuses non-GitHub/non-Linux/non-Ubuntu contexts; missing expected archive configuration fails setup. No repository-trust exception, new credential, timeout increase, skipped test or production configuration operation was added.

Changed files: `.github/workflows/digiforge-foundation-audit.yml`, `bin/configure-ci-ubuntu-mirror.py`, `tests/offline/test_ci_ubuntu_mirror.py`, this report, the execution ledger and sanitized evidence receipt. Five synthetic configuration regressions failed before the helper existed and now PASS; the existing11 OWNER package regressions remain PASS. Independent read-only reviewer reran5/5 and found no Critical/Important issue. Actual runner acquisition performance and all remaining gates require the final published exact-head audit; its SHA/run/result is recorded on #1242 and issue1209 after this report commit. No previous PASS is assigned to this changed candidate.

The OWNER ZIP, manifest, source/dependency hashes and four-shop/15-capability scope are unchanged. All22 non-Finance UNVERIFIED and23 excluded Finance-dependent criteria,500 identities and20 waves are unchanged. Production preimages/dependency closure/rollback/mailbox verification and exact DG2-438 supplier/product/variant/geometry/cost/shipping/rights evidence remain BLOCKED. No new provider research, authentication attempt, production write, grant, protected configuration change, deployment or merge. STOP ALL/external lock ON; automation OFF; RELEASE HOLD / NO-GO.
