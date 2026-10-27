# DigiForge OWNER / DG2-438 cloud acceptance result

Date: 2026-10-26 UTC. **DRAFT / RELEASE HOLD / NO-GO.** This is the cloud report requested by the owner; no Windows Documents access is assumed.

## Exact source, commits and audit boundary

- Repository: subvicco12/digiforge. Existing cumulative draft [#1242](https://github.com/subvicco12/digiforge/pull/1242), existing branch `feat/google-drive-scoped-grant-contract-20261024`, also used by #1241. No new branch/PR or merge.
- Main verified at `844107ab9baf7e1303cd47708e9eab3bc700b348`.
- Audited task baseline: `f63cd7f49fef61efd89b797550be53c360c190d7`, [audit #4808 PASS](https://github.com/subvicco12/digiforge/actions/runs/40231574769). Existing first-party OWNER implementation is present in that source, not installed on production.
- New engineering commit: **`57a7af90b2770bd7f5106db0360087c084ae8e54`**. Changes only the offline acceptance preparation tool and its regression tests. This report and the sanitized evidence receipt are a subsequent documentation commit on the same branch.
- A new full exact-head Engineering & Safety Audit is required for the final saved candidate. At report commit time it has not yet run. The final SHA/run/result is recorded in #1242 and issue #1209 after execution; the old #4808 PASS must not be reassigned to a changed head. This follows the existing repository convention of keeping a commit's own SHA/audit receipt outside its immutable tree.
- No applicable AGENTS.md exists in this worktree or its parent path. The current execution ledger, first-party policy, prior OWNER/POD sprint, Blueprint extraction and Master500 extraction were inspected. The historical proposed operating manual is context, not authority to supersede the owner's restrictions.

## Executed read-only production acceptance

Existing WPVibe production connection was verified; no recovery/staging site was accessed. Supported read commands and GET routes were used. No SSH, desktop filesystem, authenticated browser session or reusable connector credentials were assumed.

| Check | Fresh observed result | Boundary |
|---|---|---|
| Plugin / health | Active DigiForge1.0.103; health ok | Version equality is not exact source equality |
| WordPress / PHP | WP7.1.3 / PHP8.3.36 | Host metadata only |
| Safety | STOP ALL true, externally_locked true, automation_enabled false | No control setter or execution probe |
| Authenticated API actor | ID1, subvicco12@gmail.com, administrator | API identity is not mailbox possession or a native browser nonce |
| Ordinary administrator permissions | All15 existing DigiForge capabilities granted | Does not establish scoped OWNER authority or operational Finance permission |
| Shop discovery GET | shops=[], implicit_access=false, external_execution_authorized=false | Expected fail-closed empty access; no grant created |
| Protected native OWNER constant | DIGIFORGE_FIRST_PARTY_OWNER not defined | No value/pin installed or generated |
| First-party policy source | Missing via exact wp-content path,404 | No replacement spelling or reset attempted |
| WordPress core checksums | Verified3338 files, no mismatches/missing/unexpected core files | Does not verify plugin hooks, email delivery, mailbox access or credentials |
| Database markers | digiforge_db_schema_version=25; digiforge_db_version=23; provider schema1 | Fresh evidence differs from the task's25/25 premise |
| Additive tables | catalog_acceptance_evidence, etsy_order_event_evidence, provider_connections present, InnoDB | Metadata read only; no migration/index/data repair or row processing |

The25/23 observation is not automatically a schema defect: the source explicitly retains `DIGIFORGE_DB_VERSION='23'` as its legacy/package marker; `MigrationTest` expects that legacy value while MigrationPlan::LATEST is25. No option was forced to25. Table metadata is not a full fresh-install/restore or exact index/column compatibility test.

WPVibe's arbitrary PHP eval is blocked. It was not retried in another form and no WPCode snippet was created to bypass this restriction. The approved wp-content reader emits line numbers and masks known secret patterns, so **fresh raw installed SHA-256 values are UNKNOWN**. Its source text can support only normalized comparison. The large portal source exceeds its64KiB read limit. The absence of a byte-exact export/host checksum interface is a specific external blocker, not permission to invent hashes.

## OWNER source comparison and smallest proposed scope

Full candidate SHA-256, normalized observed hashes and limitations are in [the sanitized evidence receipt](evidence/owner-dg2438-cloud-readiness-20261026.json). Normalized matches are not raw preimages or a restore guarantee.

| Source | Fresh comparison / presence | Proposed treatment |
|---|---|---|
| `includes/Security/FirstPartyOwnerPolicy.php` | ABSENT404 | ADD after exact authorization |
| `includes/Security/ShopAccessPolicy.php` | NORMALIZED MATCH | Retain; verify raw preimage/dependency |
| `includes/Security/ShopAccessAuthority.php` | NORMALIZED DIFFERENCE | REVIEW replacement |
| `includes/Security/ShopAccessRepository.php` | NORMALIZED MATCH | Retain; verify raw preimage/dependency |
| `includes/REST/ShopAccessController.php` | NORMALIZED MATCH | Retain; verify raw preimage/dependency |
| `includes/Portal/ShopAccessPanel.php` | NORMALIZED DIFFERENCE | REVIEW replacement |
| `includes/Core/Capabilities.php` | NORMALIZED MATCH | Retain; verify raw preimage/dependency |
| `includes/Security/ShopAccess.php` | NORMALIZED MATCH | Retain; verify raw preimage/dependency |
| `includes/Security/ShopAccessAdminGuard.php` | NORMALIZED MATCH | Retain; verify raw preimage/dependency |
| `includes/Portal/Portal.php` | UNKNOWN:64KiB reader limit | Obtain complete source/checksum; do not claim closure |

Observed scope: **six normalized matches, two differences, one absent policy, one unreadable large portal source**. The smallest *proposed*, not deployment-certified, first-party overlay is:

1. ADD `includes/Security/FirstPartyOwnerPolicy.php`.
2. REPLACE `includes/Security/ShopAccessAuthority.php` as a coherent unit with that policy.
3. REPLACE `includes/Portal/ShopAccessPanel.php`.

The remaining reviewed files have normalized-compatible interfaces; they must not be blindly replaced as a larger bundle. Existing autoload logic maps DigiForge namespaces to includes/class paths; the current plugin header declares1.0.103/base23. The existing route/portal registration, `ShopAccess`, `ShopAccessPolicy`, `ShopAccessRepository`, `ShopAccessAdminGuard`, `Capabilities`, `Logger`, `Database/Tables`, WordPress users/roles/options and the audit table are dependencies. The unreadable complete portal/boot source and raw preimages prevent a full transitive installed dependency certification. No autoloader rewrite, whole-package replacement, activation/deactivation hook, migration or capability setter is part of the proposed three-file scope.

### Original-state backup and rollback requirements — not executed

Before any separately authorized installation, obtain an atomic original-state checkpoint in approved protected custody:

- Exact unmasked preimage bytes, SHA256, lengths, paths, ownership/modes and presence/absence for every changed/added file, plus complete dependent plugin/build manifest. A missing policy is an explicit absence preimage. Do not use normalized reader output as backup.
- Existing protected configuration custody and presence/type metadata, authority-file references and legacy OWNER/DELEGATE state; no password hashes, reset links, salts, keys or tokens exported to chat. Retain exact secure preimages on the host under approved handling.
- Consistent local ACL options/revisions/replay journal, immutable audit linkage, role definitions and matched database/protected-artifact checkpoint. Confirm backup identity, retrieval, integrity and an authorized isolated restore; stale historical backup labels do not establish this.
- Freeze relevant writes and separately authorize the exact files/configuration transition. Deploy the three-file dependency set atomically with an approved failure/maintenance path. No source file may call an absent policy class.
- Code rollback restores the complete exact two-file preimages and removes the added policy only if its prior absence is proven. Restore the reviewed compatible configuration/authority state consistently; never reactivate obsolete signed OWNER authority silently. Preserve signed DELEGATE boundaries.
- Do not blindly restore a whole database snapshot after new grants/audit events: that can discard history or resurrect revoked authority. If enrollment or other writes occurred, stop for a separately reviewed recovery preserving history/revocation. This task grants no destructive rollback, erasure or cleanup authority.

Current installation readiness: **BLOCKED** by unavailable raw preimages/checksums, incomplete dependency source, genuine mailbox receipt, protected custody/legacy-authority review and matched restore proof. No deployment authorization is implied by the source comparison.

## Exact owner verification and protected configuration journey

The existing WordPress core lost-password/login mechanism is the supported path. Core checksum evidence supports its installed core integrity; plugin/mail hooks and actual mailbox delivery are not independently certified here.

1. In a separately authorized owner session, navigate to the exact HTTPS production origin. Confirm userID1 and exact mailbox subvicco12@gmail.com, without exporting user_pass, cookies or credentials. The fresh API account read is supporting identity evidence only.
2. Only with explicit authorization for mail/reset: use WordPress's existing lost-password flow. The owner opens and consumes the expiring single-use link privately from their mailbox and authenticates to that same account/site. The assistant never requests, copies, logs or handles the link, token or password. No mail/reset was sent in this sprint.
3. A redacted authoritative receipt records mechanism/origin/account, genuine verification/authentication time, verifier and bounded expiry. Existing login or a matching profile email alone is insufficient.
4. After verification/reset, authorized protected-host custody computes the account fingerprint from fresh ID/login/email/registration/password-hash fields and installation hash from `site_url('/') . '|' . $wpdb->prefix`. Compute privately; never export the password hash. Resetting afterward deliberately invalidates the pin.
5. In separately approved protected configuration loaded before DigiForge, use exactly `version` integer1, `account_sha256`, `installation_sha256`, positive genuine `email_verified_at`, and future `expires_at` no later than365 days after verification. No default verification time, pin or expiry was manufactured.
6. Review legacy signed OWNER records before transition. While native configuration is present, malformed/expired/drifted native evidence fails closed and cannot borrow a signed OWNER. Valid signed delegates remain separate. Removing native configuration returns to legacy signing, so obsolete OWNER records must not silently reappear during rollback/revocation. Native OWNER needs no Sodium; signed delegates retain existing signing requirements.
7. After separately authorized install/configuration, owner logs into the normal website Operations Console -> Shop access / Operators. Explicitly enroll only desired canonical shops/capabilities with native nonce, confirmation, exact approval hash, current revision, replay key and expiry. Reads never enroll. Start with Digital/Personalized POD; global totals require explicit four-shop access but do not activate deferred shops. Finance processing remains excluded; browser Finance operator GRANT remains denied.
8. Perform authenticated desktop/mobile/keyboard, revoked/expired/cross-shop and failure-state acceptance under the retained locks. Current connector API authentication does not prove this browser journey. No grant, configuration setter or production negative mutation was executed.

STOP ALL/external lock/automation controls are independent of local OWNER authority and must remain unchanged throughout. All15 pre-existing role capabilities remain a root prerequisite; this is not permission to enable Finance or future shops.

## DG2-438 supplier / artwork findings

Master500 identity stays **DG2-438 / Family-name embroidered-look / Personalized Sweatshirts / NAME_MONOGRAM**, wave18 of20. Historical attributed UI evidence stays store28930593, product6ad647d24f4a65e4300211e5, source listing8a2666c5-d3db-4b44-bf6a-4c5b4213815e, DYNAMIC_CHOICE routing. Selected sizes conflict8 versus9 and colors/variants/SKU/blueprint/provider are incomplete. This does **not** establish a real mapping from that product to DG2-438.

Official [Gildan18000 catalog page](https://printify.com/app/products/49/gildan/unisex-heavy-blend-crewneck-sweatshirt) identifies blueprint49 as a **research candidate only**. Its current public page shows multiple decoration methods and provider-dependent areas/sizes; page rendering includes unattributed provider price cards. Those amounts are not an exact selected-provider/variant quote or settled cost. Product size measurements are garment dimensions, not printable rectangles or tolerances.

A fresh public unauthenticated [provider-list endpoint](https://api.printify.com/v1/catalog/blueprints/49/print_providers.json) returned200 via Firecrawl. The parsed representation (not original raw HTTP bytes) is hash-bound in the evidence receipt. Public capability observations:

| Provider ID | Name | Reported decoration methods | Reported average production, business days |
|---|---|---|---|
| 41 | Duplium | dtg | 2.3 |
| 39 | SwiftPOD | dtg | 1.2 |
| 217 | Fulfill Engine | dtg, embroidery, puff-print, metallic-print, glitter-print | 2.6 |
| 331 | Shirt Monkey | dtg | 0.9 |
| 99 | Printify Choice | dtg | 1.5 |
| 3 | Marco Fine Arts | dtg | 1.4 |
| 34 | The Print Bar | dtg | 3.0 |
| 410 | Printful | dtg, embroidery, dtf | 1.3 |
| 61 | Dimona Tee | dtg, embroidery | 2.3 |
| 26 | Textildruck Europa | dtg | 1.1 |
| 52 | Stakes Manufacturing | dtg | 1.5 |
| 6 | T Shirt and Sons | dtg | 1.0 |
| 29 | Monster Digital | dtg | 1.1 |
| 402 | Atelier Katanga | dtg | 2.5 |
| 66 | Prima Printing | dtg | 1.7 |
| 72 | Print Clever | dtg | 1.7 |
| 50 | Underground Threads | dtg | 2.0 |
| 27 | Print Geek | dtg | 1.8 |

These are catalog-level capabilities/averages, not DG2-438 acceptance, a guarantee or the route of the historical Choice product. Choice99 is not evidence of a fixed fulfilling factory. Embroidery is reported for217/410/61; no selected embroidery variant/placement is established.

The public blueprint49/provider29 variants endpoint returned**401 Unauthenticated**. No token, provider login, renewal or repeated retry was attempted. Exact variant IDs, SKUs, placement rectangles/positions, DPI, safe/bleed, physical scaling and supplier tolerance therefore remain **UNKNOWN**. Do not substitute blueprint12/Monster29 T-shirt4500x5100, the separate observed3951x4800 T-shirt rectangle, or five embroidery guides for this sweatshirt.

Official [Monster Digital shipping page](https://printify.com/shipping-rates/monster-digital/) was retrieved successfully: US/Mexico fulfillment centers (Columbus,Ohio and Juarez,Mexico), tracking and listed carriers are public observations. Its actual rate table remained “Loading shipping rates…” in the retrieved representation. Destination/method/first/additional-item prices and a product-specific SLA are **UNKNOWN**; no supplier cost/margin was calculated. Public averages and generic page “from” prices are not selected-variant or Choice quotes.

Official [embroidery preparation guidance](https://help.printify.com/hc/en-us/articles/4483608843921-How-do-I-prepare-my-design-for-embroidery) distinguishes thread from ink, warns that digitization can enlarge/simplify details and that mockup previews can differ from actual stitches. It gives generic minimum text height0.25in, line thickness0.05in and up to6 thread colors. These are not selected supplier geometry or approval. Noto Serif Regular2.007/OFL source and the retained FC01 text renderer remain diagnostic components; fine serifs have no demonstrated embroidery fitness. No auto-bold, auto-shrink, replacement glyph, changed buyer text or new artwork was introduced.

“Embroidered-look” can describe a printed visual style; it does not establish actual thread embroidery. The final versioned design and method must be independently reviewed. Real embroidery requires applicable digitization/thread/placement evidence and physical review; DTG/DTF printed faux texture requires its own licensed art, geometry and print QA. Exact buyer payload and controlled artwork lineage remain required in either case; source-PNG parity cannot certify physical stitches. Complete artwork/mockup/native-font rights, independent artistic/linguistic review, native optional-line behavior and ordinary buyer editability remain BLOCKED/UNKNOWN. No design, product, listing, draft, order or supplier binding was created.

## Reproduced defect and implemented correction

`bin/prepare-owner-pod-acceptance.py` previously selected the first `label='sweatshirt'` record without an immutable source pin or exact shop/store/environment/listing/product check. Seven adversarial mutations (foreign shop, environment, store, listing, changed product, duplicate label, changed receipt body) failed to raise: the shortlist could silently attribute a foreign or ambiguous observation to its fixed Master500 selection, even though production authorization remained false.

Engineering commit57a7af90b2770bd7f5106db0360087c084ae8e54 now reads the historical observation bytes once, verifies pinned SHA256 `b3d389f2defdecc30d00e115920dd16019509d08661668d11d074417e67854b1`, validates the exact existing scope and unique historical sweatshirt identity, and includes shop/environment/store/listing in the generated receipt. The same read supplies parsing and returned hash, eliminating inconsistent data/hash rereads. Future changed evidence requires independently reviewed version/pin updates; it cannot overwrite historical identity silently. No parallel repository, new privilege, migration or provider adapter was added.

Regressions use derived synthetic mutations only. Authentic-vs-synthetic boundaries, source-to-supplier mapping=false, all required evidence UNVERIFIED and production_authorized=false remain. The existing twenty25-product waves and all500 identities are preserved.

## Executed local tests and scope of proof

| Verification | Result |
|---|---|
| Adversarial reproduction before fix | RED:7 subcase failures, expected ValueError not raised |
| Owner/POD reconciliation including new adversarial cases | PASS:4 tests |
| Focused existing OWNER/exact-personalization/renderer-integrity unit tests | PASS:18 tests /110 assertions |
| Full PHP8.3 unit suite, isolated no-network container | PASS:1821 tests /9704 assertions;1 existing optional skip |
| Full pinned offline suite with supplied PHP prerequisite | PASS:169 tests |
| Authoritative acceptance checker | PASS;268 non-Finance,23 Finance-dependent separate |
| Source diff whitespace | PASS |
| Existing full native OWNER/four-shop/portal tests | Baseline audit4808 reused; new final exact-head hosted run required |
| Genuine installed authenticated browser, mailbox receipt, raw backup restore | NOT RUN/BLOCKED, not represented by unit or connector reads |

No new runtime PHP behavior was changed; existing exact Unicode,48-code-point/overflow/glyph/optional-field and parity implementations are reused. No further independent runtime P0/P1 defect was reproduced in this scope. This does not claim all software or commercial acceptance is complete.

## All22 unresolved non-Finance criteria

The exact atomic census and existing behavioral references are preserved. Every row remains UNVERIFIED; policy approval or a synthetic test is not criterion-wide acceptance. The full existing notes remain in `docs/engineering/original-source/distinct-nonfinance-acceptance.tsv`; Finance's23 criteria are excluded, not removed.

| Criterion | This sprint's disposition / remaining evidence |
|---|---|
| `BP-seam-panel-render` | BLOCKED: exact reviewed sweatshirt panel/seam/safe/bleed geometry absent; T-shirt/embroidery guides not transferable. |
| `BP-ai-value-routing` | UNKNOWN: approved deterministic-first policy retained; authentic measurable-benefit/provenance/review records absent. |
| `BP-template-qa-spelling` | BLOCKED: exact-text local guards reused; independent linguistic/glyph/artifact review for selected product absent. |
| `BP-printify-shop-scope` | UNKNOWN: historical store scoped and pinned locally; current account/integration/variant authority not certified. |
| `BP-distinct-provider-shops` | EXTERNAL/DEFERRED: no Shop3 provider identity inferred or onboarded. |
| `BP-etsy-shop-identity` | EXTERNAL: current Etsy account/OAuth acceptance absent; no renewal attempted. |
| `BP-portal-4-provider-connections` | BLOCKED: actual current provider binding and installed browser journey missing. |
| `BP-portal-7-mockups` | BLOCKED: selected variant/panel/UV/seam and commercial display/derivative rights not established. |
| `BP-portal-7-supplier-readiness` | BLOCKED: public catalog capability obtained; real product mapping/variant/geometry/cost/shipping still missing. |
| `BP-portal-11-production-authorization` | OWNER/EXTERNAL: no production approval or execution; locks remain. |
| `BP-portal-17-conversion` | UNKNOWN: approved typed metrics retained; authentic time-bounded, attributed cohorts/source numerators/denominators and completeness missing. No sales/performance invented. |
| `BP-portal-17-defect-quality` | UNKNOWN: approved typed metrics retained; authentic time-bounded, attributed cohorts/source numerators/denominators and completeness missing. No sales/performance invented. |
| `BP-portal-17-supplier-performance` | UNKNOWN: approved typed metrics retained; authentic time-bounded, attributed cohorts/source numerators/denominators and completeness missing. No sales/performance invented. |
| `BP-portal-17-product-performance` | UNKNOWN: approved typed metrics retained; authentic time-bounded, attributed cohorts/source numerators/denominators and completeness missing. No sales/performance invented. |
| `BP-portal-17-shop-performance` | UNKNOWN: approved typed metrics retained; authentic time-bounded, attributed cohorts/source numerators/denominators and completeness missing. No sales/performance invented. |
| `BP-live-portal-normal-work` | INSTALLED-SITE BLOCKED: authenticated API user1 verified, shops empty; first-party code/configuration and native browser acceptance absent. |
| `BP-secret-no-chat` | EXTERNAL PROCESS: no secrets requested/exposed; cannot certify all future operator behavior. |
| `BP-quality-feedback` | UNKNOWN: approved reviewed-feedback policy retained; genuine attributable review/correction records missing. |
| `BP-digital-semantic-content` | EXTERNAL: prior exact r2 owner approvals retained; no repeated approval requested; whole-criterion evidence incomplete. |
| `BP-digital-semantic-visual` | EXTERNAL: prior product decisions retained; independent complete visual/ordinary-buyer evidence still required. |
| `BP-digital-semantic-ip` | EXTERNAL: exact third-party entitlements/POD/delivery rights remain source-specific. |
| `BP-digital-semantic-policy` | EXTERNAL: prior exact buyer terms approval retained; full policy/commercial/installed acceptance not inferred. |

**Counts unchanged:**246/268 bounded implementation candidates;22 non-Finance UNVERIFIED;23 Finance-dependent separate;500 identities in20 waves,360 bounded recipes/140 artistic-execution-blocked. No promotion, registration or certification.

## Next critical path / exact outside actions

1. Obtain approved protected-host raw source checksums/preimages and complete portal/dependency/backup/restore evidence. Review the proposed exact three-file OWNER overlay and configuration/legacy-authority rollback. Separate deployment permission is mandatory; no broader package/migration authority is implied.
2. Separately authorize the private WordPress mailbox-control verification and protected account/installation pin after any reset, then controlled first-party installation and explicit local enrollment. Retain all locks; verify genuine desktop/mobile/keyboard/cross-shop/revocation acceptance without using wp-admin as the normal business UI.
3. Through a separately authorized read-only supplier source, export the exact historical product/store/blueprint/provider-or-Choice/variant/SKU/method/placement/revision mapping. Reconcile DG2-438 rather than matching by sweatshirt name. Obtain authoritative variant geometry/DPI/safe/bleed/applicability and destination-specific cost/shipping evidence. One401 is a blocker, not permission to provision/use tokens.
4. Review the actual embroidered-look design/method, rights, digitized or printed artifact, linguistic/native/buyer and physical evidence. No unapproved font/style/field changes. Reuse the source-bound receipt and twenty review waves; do not restart catalog development.

No production/staging files, configuration, passwords, OWNER grants, provider products/listings, orders or Finance processing were changed. No code snippet, migration, merge, deployment, activation, publishing, signing restart, retention or deletion was performed. **STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD; NO-GO.**
