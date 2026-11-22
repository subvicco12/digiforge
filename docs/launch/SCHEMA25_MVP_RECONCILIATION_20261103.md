# Schema-25 Personalized POD MVP reconciliation

Engineering candidate only. STOP ALL ON, external safety lock ON, automation OFF, Manual order approval and RELEASE HOLD remain required. No production installation, activation, migration, backup, restoration, provider publication/order, paid call or settings change was performed or authorized. No merge authorized by this task.

## Baselines and provenance

Current remote main was reverified as3758d1385f7d4a62947248822c1fe1c89ec9818e, containing merged PR1255. Its MVP was audited at8d884abd3954e950a571cfeb2db06a34cfa5778e by audit4860. That artifact omitted later installed components; do not deploy it over production.

Historical repository baseline **bf23a7a6ce56584c69d90b67d880584986382461** is reachable through `origin/feat/google-drive-scoped-grant-contract-20261024`. Its492 include PHP paths exactly match the complete paginated production listing. Fresh inspected normalized source for Plugin.php, EtsyOrderEventSchema, ProviderConnectionSchema, ShopAccessAuthority, ShopAccessRestGuard, ShopAccessAdminGuard and ShopAccessPanel matches this baseline7/7. This is repository reconciliation evidence, **not proof of installed raw byte identity**. The authorized WordPress reader masks/line-numbers content and cannot read the203KB installed portal. Raw installed per-file SHA256 and exact deployed commit remain UNKNOWN. No checksum was invented from normalized output. Details and complete observed path inventory are in `evidence/SCHEMA25_INSTALLED_SOURCE_20261103.json`.

Other recovered implementations are available in history: c1803582 (schema25 signed Etsy event evidence),3ad807f4 (independent shop grants),98fb27dc (reviewed ownership/policy contracts),ff7181ee (provider connections),af381060 (Drive queue/OAuth orchestration). Later f63cd7f4/cabe5ad7 include additional first-party OWNER changes not evidenced as installed. They were not substituted wholesale for the observed baseline.

## Integration scope

The isolated `codex/schema25-mvp-reconciliation-1252` branch starts from bf23 and merges exact certified main3758. Both parent histories remain reachable. Against main the PR necessarily restores the historical installed lineage; against bf23 the application delta is the bounded existing MVP plus explicit shop admission. This is preservation, not a rewrite of Finance/Drive/customer editor/native rendering or provider modules.

All492 baseline include files remain present. Existing bootstrap, schema migrations, provider connections, catalog service, signed Etsy webhook/event ingestion, REST/admin guards, independent signed grant repository, audit logger, customer editor/render and Google/Finance modules are preserved. Existing runtime files differ only in Portal.php (two additive registration/render calls), ShopAccessAdminGuard.php (exact two-action admission) and scoped portal CSS; the dormant optional SAM/matting worker’s exact Hydra pin is also advanced to the advisory-fixed release as described below. No production schema markers/options were manually modified.

Confirmed integration defects fixed:

1. New MVP repositories originally checked only global WP capabilities. `Security/PersonalizedPodAccess` now requires current independent personalized_pod grants for both research and POD, in addition to ordinary capabilities. Four repositories recheck on reads/writes; portal entrypoints and governed generation gates reuse the same admission. Generation additionally requires explicit AI scope and retains existing STOP ALL/external execution controls. Revoking authority invalidates saved design/package access and held exports. No administrator bypass or source-approval override exists.
2. The installed admin guard denies unknown actions, so the two new portal POSTs originally failed for valid operators. Only exact `digiforge_opportunity_design` and `digiforge_commercial_research` receive scoped admission; handlers retain nonce/capability/stored-source validation. Unknown actions remain denied. Actual registered-hook admission/revocation is tested.
3. A stale MVP unit fixture constructed a validated Printify template without catalog provenance. It now uses synthetic transport-shaped source through the existing stricter catalog constructor; no supplier gate was relaxed.
4. Full WordPress testing reproduced existing Drive fixture stale-snapshot corruption deletes. The two test-only transaction-order corrections from cabe5ad7 are reused; no Drive runtime code changed.

5. The preserved optional offline matting dependency hydra-core1.3.7 fails the existing public-advisory gate (GHSA-wf6j-7hvr-gr93). Requirements and the worker exact-version check both advance to1.3.8, matching the later reviewed historical fix. No worker is enabled or installed, and no advisory is ignored.

Existing MVP positive fixtures now use opt-in synthetic independently signed grants. Negative capability fixtures explicitly deny a user capability instead of removing an override that would leave the test role's capability active. Fake provider tests explicitly grant AI scope; no real provider call is made. New tests cover privileged actors with no grant, digital-only/incomplete grants, current grant success, revoked authority, saved package/export denial, exact admin action and real hook admission, and idempotent schema initialization. Existing shop-access negative suites cover expiry, role loss, corrupt evidence and audit outages.

## Schema / compatibility impact

| Contract | Candidate behavior |
| --- | --- |
| WordPress/PHP | Declared WP>=7.1, PHP>=8.3; observed production7.1.4/8.3.36 compatible with floors |
| Canonical runtime schema | Existing MigrationPlan LATEST25 preserved; expected installed health25/25, no downgrade to24 |
| Core/legacy metadata | Existing DIGIFORGE_DB_VERSION23 preserved; separate marker, not runtime expected version |
| Provider connection | Existing schema1 and exact scoped reviewed relationships preserved; no credential activation/backfill |
| Etsy event evidence | Existing append-only v25 table/repository/webhook semantics preserved |
| New MVP persistence | Append-only bounded JSON options for research/concepts/design/artwork/decisions/packages/receipts; no new custom table or down migration |
| Upgrade from25 | Existing version/table checks no-op when complete; tests preserve existing row counts and markers; complete raw production table/index comparison remains release gate |
| Older baseline | Existing boot/activation migrations can create/alter tables or capabilities; file replacement can trigger initialization before explicit activation |
| Artifact identity | Version1.0.103 retained for baseline compatibility; exact source SHA/ZIP checksum/manifest mandatory; version alone cannot identify installed bytes |
| Artwork | Existing direct/A3 SVG60000-byte bounds, approved reference SVG262144, PNG2MiB each, final held JSON65536 preserved; rejects oversize, no truncation or invented provider upload |
| Execution | Held JSON/manual approvals grant no external publication/production; all prior interlocks preserved |

The delta does not introduce destructive migration or rewrite old approvals. It does not prove a safe files-only downgrade. Existing historical migrations, capability/default initialization and protected artwork-root creation remain possible boot effects. Uninstall with cleanup opt-in is destructive and is forbidden as rollback. Preserve complete DB/options/usermeta, plugin bytes, uploads/protected assets, relevant configuration and encryption keys in a matched checkpoint.

## Validation and independent review

Commands use a disposable loopback WordPress/MariaDB fixture and blocked/fake network transports. No tests target production. Existing pinned offline runtime is required: Pillow13.0.0/Freetype2.14.3 and locked cutout dependencies; system Pillow12.3.0 correctly fails closed. Local PHPStan requires loopback worker permission. Neither issue warrants weakening source checks.

- Focused schema25/shop-access/provider/event/MVP run:103 tests/1236 assertions PASS before two additional hook/revocation cases; subsequent full suite covers final cases.
- Standard composer test:1889 unit tests/9794 assertions,1 existing skip; legacy scripts, PHPStan and PHPCS PASS. Expected PHP8.4 dynamic-property diagnostics in legacy anonymous test doubles remain visible.
- Full WordPress/catalog, offline/security, final exact-head independent review and hosted audit/artifact receipts are recorded on the consolidated PR and Issue1252 after completion. No claim of PASS is inferred for a future SHA.

## Recovery dependencies — separate authorization required

Production read-only inventory in the preceding readiness assessment reported current schema25/provider1, external lock/STOP ALL true, automation false. Stored backup September30 and drill September29 are stale and do not prove today's recoverability. The historical recorded package source b0eba677fa9399d2b80147710c770655f4add965 / ZIP f8b4930db9450c783ec97e679ad739c82e8fadade80f7d2b15fba01656e81be1 is not today's verified installed tree.

Required before deployment authorization:

- Authorized raw installed plugin directory inventory/hash and exact prior recoverable artifact, resolving any drift from the observed historical baseline.
- Fresh consistent complete production DB plus installed files, uploads/protected artwork, MU/plugin/site configuration and encryption key material; private custody, UTC identity, coverage, hashes, retrievability, retention and DB/files pair binding.
- Independently verified isolated restoration of that exact checkpoint with mail/provider egress/cron blocked, including decryptability, audit/queue/schema/portal/login/shop isolation. Agree RPO/RTO and how post-checkpoint writes are reconciled.
- Qualified host-specific pinned replacement/activation behavior and out-of-band PHP-fatal recovery path. No Hostinger tool is callable in this session; past skill qualification does not prove current host access or backup bytes.
- Current authorized operator shop access/full readiness, runtime extensions/request limits, Manual order policy and bounded synthetic FL03/FL04 installed-site acceptance. Authentic Work research/design/licensing/provider/media/economics remain separate commercial gates.
- Explicit later exact-artifact deployment and rollback authorization. This engineering candidate does not authorize backups/restores, deployment or release.

Overall: candidate engineering evidence must be evaluated on the final exact head; **production readiness remains BLOCKED / RELEASE HOLD** until raw identity reconciliation and a matched recoverable checkpoint are independently verified. Existing read-only deployment/rollback assessment remains applicable; never force schema markers or infer restore success from a queued job or stored boolean.
