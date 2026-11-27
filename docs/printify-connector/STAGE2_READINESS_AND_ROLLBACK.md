# Stage 2 installed-lineage preparation

Historical checkpoint: eec808f1. Follow-up acceptance recovery and the separately proposed, inactive policy wording are documented in STAGE2_ACCEPTANCE_RECOVERY.md and STAGE2_GET_POLICY_AMENDMENT_DRAFT.md. Original package/control evidence below is preserved; final recovered exact-head results are in the PR handoff.

Status: TEST_ONLY_NOT_INSTALLATION_READY. No live GET, installation, migration, merge, staging or production change is authorized. The Personalized POD lane is not imported.

## Verified identities and provenance

PR1263 starting head: ef7f99a1ec8c90a118fa931986441fda0e7e810c. Governing PR1262:761fb79abb2813455b7a90a56aa1fac8e6994f22. Exact .17 package: digiforge-1.0.103.17-consolidated-update.zip, 3,201,579 bytes, SHA256 5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac, 741 members, CRC verified. Source e957ba096751dc34254cdbcbefed13b625f5e507 is the qualified composition source, NOT an exact checkout archive. Later .19 preservation evidence (cbead09e) independently records this exact base hash/source tuple.

Read-only Hostinger calls on 2026-11-12: wordpress_installations_list scoped to u917733804/digiforge.converentis.com identifies installation30602969; wordpress_plugins_list-installed confirms DigiForge1.0.103.17 ACTIVE. Printify Shipping Method3.2 is also active. No credentials, live configuration or database records were read. A subsequent supported non-executing hosting_files_website-content inspection read only nine non-secret PHP source files. All nine complete byte lengths and SHA256 hashes match the exact baseline, including both modified preimages and ShopAccess, boundary, scoped retriever, integration repository, logger, Owner and Session. Settings.php and ScopedProviderConnection.php were refused by Hostinger with HTTP422; no workaround was attempted. See STAGE2_PRODUCTION_SOURCE_EVIDENCE.json. Full production-tree equivalence remains UNVERIFIED.

## Compatibility findings and correction

The .17 package already contains native ShopAccess, ScopedProviderConnection::verified, PrintifyConnectionBoundary::context/runtime and PrintifyScopedCredentialRetriever::consume. The previous main-derived report was correct about its checkout, but those contracts are NOT absent from .17. They must not be copied from main over production. Vault custody remains existing scoped server-side callback retrieval, context-bound decryption, current binding revalidation and result redaction. No new vault/key/ciphertext store.

The exact .17 catalog client lacks connectorRead. The reproducible builder adds ONLY the reviewed method at a unique hash-pinned preimage; removing that method must reconstruct the complete original client. Existing methods, private fields, original transport and retry contracts remain byte-identical. All other baseline members remain byte-identical except reversible plugin version metadata. Adds isolated connector classes and the GET contract. Core autoloading already supports the namespace. The separately packaged companion has only its existing idempotent plugins_loaded hook, no REST endpoints, tables, cron, credentials, mappings, HTTP client or owner authority. Both plugins can load together in isolated WordPress tests.

HostCompatibility performs source contract discovery without DB, vault or HTTP access. Companion status now distinguishes presence of the interface from native dependency availability. Discovery never grants execution. Exact-package probe reports all required native methods present and execution_authorized=false.

PrintifyUploadOwnership is absent from .17. UPLOAD_READ/UPLOADS_READ therefore remain denied; do not infer ownership from account visibility or add an unreviewed receipt store. Other eleven read contracts retain their existing source/offline validation. Provider support and actual live scopes remain unverified.

No migration, new table or schema-version change is introduced. Existing .17 settings, integration registry, integration_secrets, provider-connection evidence, audit_log and wp_options remain authoritative. Budget writes require InnoDB/full option_name uniqueness and a standalone transaction. Actual MariaDB fixture passes; standard MySQL transaction-state compatibility remains unqualified and denied. Existing Global Owner revision/permissions, four-shop isolation, Operators, scheduler, product/order code and historical22 are preserved.

## Acceptance policy and exact approval boundary

PR1262 compatibility clause: "The connector must fail closed if DigiForge is missing, incompatible, STOP ALL, locked or disabled."
PR1262 security clause: "STOP ALL and external safety lock supersede all connector settings. Automation OFF by default."

AcceptancePolicy::assess is a NON-AUTHORIZING decision matrix, not a dispatch grant. It rejects missing/unknown/extra evidence, non-GET, wrong tuple, active STOP ALL or external lock, disabled native provider, missing reviewed binding, unknown credential association and missing installation/live-GET approvals. Even a complete hypothetical matrix returns execution_authorized=false; runtime native authorization, budget, audit, vault and per-attempt checks remain mandatory. Caller-submitted booleans cannot authorize anything. This policy is tested and deliberately has no activation/exception switch.

| Evidence | Disposition |
|---|---|
| STOP ALL ON | DENIED, regardless of any other approval |
| External lock ON | DENIED, regardless of any other approval |
| Automation OFF/current native activation gates | Native provider remains denied |
| Owner approved installation only | No live GET permission |
| Correct tuple but unknown account/credential/scopes | DENIED |
| Missing, expired, revoked or drifted native authority | Existing native denial preserved |
| Non-GET or arbitrary URL/headers/body | Existing fixed contract rejects |
| Complete hypothetical unlocked native prerequisites | Eligible for separate review only; assessment NEVER grants execution |

The requested future live GET while both locks stay ON is not executable under this blueprint. No GET exemption is inferred. If that operational behavior is required, it needs a separately reviewed explicit amendment to PR1262 and the parent safety policy, followed by offline implementation/qualification. This batch does NOT adopt such an amendment, disable a lock or arm business automation.

## Non-secret binding evidence

| Field | Proposal | Evidence state |
|---|---|---|
| Internal shop/key | 2 / personalized_pod | Required exact pilot tuple |
| Integration | 3 | Existing owner/Work evidence; no current DB inspection |
| Provider shop | 28930593 | Prior Work dashboard evidence; API identity UNVERIFIED |
| Pop-Up28925875 | Excluded | Must not substitute |
| Environment | production | Required proposal; no binding write |
| Account reference | UNKNOWN | Dashboard identity does not identify vault token |
| Credential association/fingerprint | UNKNOWN | No secret read/decryption; independent metadata proof required |
| Effective read scopes/expiry | UNKNOWN | No authenticated API request authorized |
| Binding approval/evidence rows | ABSENT in supplied evidence | No current production record assertion |
| Integration enablement | false in supplied evidence | Not changed; existing native policy denies |
| Shop3 | DEFERRED | No assignment or implicit credential sharing |

## Reproducible test-only composition

Run the tracked bin/package-printify-connector-stage2.py with --baseline pointing to the exact private .17 ZIP and --output a new private directory. No download, install or network occurs. The manifest records exact source HEAD, original source/package identity, per-member hashes and composition_is_exact_checkout=false. Build only from a clean reviewed checkout at the final PR SHA. Reject wrong ZIP bytes, duplicate/traversal/symlink members, changed preimages or method delta and stale extracted output inventory. ZIP timestamps/modes/order/compression are fixed. Rebuild twice and compare bytes; verify CRC and every manifest member independently.

Outputs: digiforge-1.0.103.17-connector.1-test-only.zip and digiforge-printify-connector-0.1.0-test-only.zip. These are separately installable plugin archives syntactically, NOT approved production releases. The core ZIP is the complete preserved baseline plus the narrow host port; installing only the companion on unmodified .17 cannot supply the missing method.

## Future installation/rollback procedure — NON-EXECUTING

1. Complete package acceptance reconciliation and independent review. Obtain actual current installed source hashes through an approved non-executing channel and compare the baseline; halt on drift. Do not use this procedure to approve the unresolved package tests.
2. Obtain separate approval for the exact two archive hashes and installation window. Identify an existing valid recovery checkpoint under the owner's separate recovery process; no backup/restore qualification was performed here.
3. Keep STOP ALL and external lock ON, automation OFF, RELEASE HOLD. Do not change integration3, bindings, credentials, permissions or schema. No live GET smoke test is authorized.
4. Under future installation approval, preserve the complete current plugin directory off-webroot. Verify its immutable member manifest; preserve permissions. Replace the core only with the reviewed complete port and add the companion separately. No main-derived wholesale update.
5. Verify versions/interface/native source contracts, portal login/owner/Operators/scheduler availability and continued denial of GET and all writes. Inspect minimized local diagnostics only. If any fails, stop.
6. Roll back by disabling/removing ONLY the companion and restoring the exact pre-install core directory, including the original header/client and removal of added connector files. Verify every original member/hash and preserved owner/permission/safety markers. No SQL migration or automatic record deletion is part of rollback; do not reset budget history or historical22. If no acceptance requests ran, this preparation creates no production budget/audit state.
7. File rollback does not certify backup recovery, undo an external operation or authorize reenrollment. No rollback/restore was executed on production or staging in this task.

## Acceptance evidence and remaining gates

Local source qualification:1268unit/7775assertions;251WordPress/2362assertions;73offline tests including12package tests with exact private baseline supplied; configured PHPStan/PHPCS, security gates and568extracted PHP files lint PASS. All requests/mail were denied except synthetic fixtures.

Full extracted .17-source fixture run:435tests/3952assertions,6errors14failures2skips. Unchanged exact .17 control:431tests/3931assertions,6errors14failures2skips. All20 error/failure method names match exactly; four extra connector tests pass. Failures cover inherited source/package differences in approvals, master catalog, preflight geometry, research, opportunity panel and fixture assumptions. They were not suppressed, reclassified as PASS or repaired by changing unrelated production code. See STAGE2_PACKAGE_REGRESSION_COMPARISON.json. Remaining full-package gate is BLOCKED, and must be reconciled before installation readiness.

Additional package security review reproduced arbitrary source-SHA labeling and output symlink overwrite. TDD fixes verify and consume exact committed source blobs, reject dirty or unrelated source identities, refuse symlinked output paths and use the same cached baseline bytes for hashing/parsing. All12 package tests pass, including baseline replacement-race and output confinement cases.

Hosted source CI outcome and final exact source/package identity are recorded in the PR handoff/qualification receipt. Source CI is not exact-package certification. Full extracted-package acceptance must not be declared PASS merely because connector tests pass. No human independent approval, live provider authentication or full production source equality is claimed.

Separate decisions: compatibility/package defects resolved and reviewed; current production drift verified; owner approval of exact installation artifacts; independently reviewed account/credential/integration/shop binding; explicit governing read-policy decision consistent with active locks; separate one-session GET scope/budget approval; execution only after all native prerequisites hold. No Cloudflare, new provider credential, account reenrollment, dependency installation, PR merge or operational activation is authorized or required by this preparation.
