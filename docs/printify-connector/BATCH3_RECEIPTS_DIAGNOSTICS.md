# Companion Batch 3 — receipts, local operator evidence and test isolation

Companion0.4.0, mutation protocol1.0.0 and optional inspection protocol1.0.0. OFFLINE ONLY. No native core mutation/inspection implementation, public endpoint, provider sender, vault, mapping/approval database or external dispatch authority is added. Core runtime source and PR1264 remain unchanged.

## Trusted receipt import

`ReceiptImporter::import(intent, json)` accepts only schema `DF_TRUSTED_RECEIPT_IMPORT_1`, exact operation ID, scope fingerprint, payload fingerprint and a64-hex receipt hash. Maximum2048 bytes. Raw provider bodies, credentials, extra fields and arbitrary URLs are rejected before accessing core. The adapter resolves `RECEIPT_READ` through the existing trusted core port, verifies full receipt schema/hash/binding/fence epoch, then requests atomic core reconciliation. Caller-computed hashes do not establish provenance. Duplicate and out-of-order handling remain core-transaction decisions. The Batch2 low-level `acceptReceipt` is not a browser import interface.

Import does not create a provider receipt or make uncertain writes retryable. Revoked authority, STOP ALL/external lock, missing journals or absent core ports deny journal changes. All test receipts/provider responses are synthetic.

## Read-only operation views and exports

`Plugin::operationPanel(intents)` is a callable renderer for later authorized portal integration. It adds no WordPress hook, route, form, download handler, mutation button or scheduler. Default discovery fails closed because the native core port is absent.

The optional `MutationInspectionCoreInterface` extends the existing mutation port with `inspectionVersion(): '1.0.0'` and `inspect(binding)`. Core must independently authorize the **current** operator/session/shop/module for each local SELECT-only inspection. Mutation approval may be revoked while a separately authorized operator can still inspect its history. WordPress administrator status alone is not owner authority. STOP ALL and the external lock continue to deny external execution; this local evidence read does not introduce a provider-policy exception.

Exact response fields: inspection_version, exact echoed binding, read_authorized=true, observed_at (within30 seconds), checkpoint_epoch, journal (or null), audit_events and evidence_hash. The evidence hash is canonical SHA-256 excluding itself. It detects tampering; authentication comes from the trusted in-process core interface, not from an unsigned JSON file.

Journal fields: state, positive revision, epoch, approval_state (VALID/REVOKED/EXPIRED/UNKNOWN), receipt_reference (nullable64hex), nonnegative event_count, fence_present Boolean. RESERVED maps from FENCED and RECONCILED from METADATA_RECONCILED. PREPARED, UNKNOWN, REJECTED, CONFLICTING, CANCELLED and MISSING have explicit displays. Conflicts are a core read projection; the companion does not overwrite an accepted terminal receipt with a conflict.

Up to20 minimized audit events per operation: ascending positive sequence, fixed state, timestamp no later than observed_at and event_hash. Unknown event fields, payloads or credentials deny projection. Core must read a consistent journal/audit/checkpoint snapshot. Missing journal, stale epoch, revoked/expired/unknown approval, unresolved UNKNOWN and conflicting receipt have bounded diagnostic codes. No diagnostic clears a fence, restores a slot or grants retry.

`OperatorReadModel` accepts at most25 distinct operation intents and reauthorizes each through core on every snapshot/export. It does not enumerate arbitrary database records. Export contains only fixed minimized operation fields, diagnostics, journal/receipt hashes and bounded audit observations. No artwork bytes, titles, customer data, approval secrets, credential references, raw provider bodies or raw audit errors are exported. `export_hash` permits byte/content consistency checking, not independent certification. Exports are JSON snapshots with read_only=true, retry_permitted=false and external_execution_authorized=false.

## Ten reused-database failures: root cause and correction

The preserved Batch2 rerun had10 failures. WordPress test install.php drops only `$wpdb->tables()` and multisite core tables. DigiForge tables are not in that list. Plugin DDL/explicit commits also escape per-test rollback, so retained plugin rows outlive the reinstalled core options/users. Evidence from the original synthetic reused database:

- `test_printify` connection key remained: ConnectionTesterTest creation collided.
- A research candidate remained APPROVED: ResearchRepositoryTest expected a new PENDING candidate.
- Fixed provider mapping identity remained: PrintifyPreflightTest insert collided.
- Fixed authorization-package hashes (including atomic-package and persistence-reconcile-package) remained, with six authorization bindings: six Schema19LifecycleProvenanceTest cases operated on failed duplicate inserts/stale IDs rather than new packages.
- Core schema markers were reset while plugin tables remained: MigrationTest saw schema0 instead of24; historical dbDelta reconstruction emitted invalid audit-log ID-default alterations.

These are an inconsistent test reset, not evidence that a normal upgrade with preserved markers fails. A dedicated real-WordPress test confirms current markers and consumed nonce evidence survive `maybe_migrate()` and repeated activation. This is bounded current-lineage evidence, not universal upgrade certification. No production migrator or authorization code was changed.

Test-only `FixtureConfiguration` creates a0600 temporary wrapper referencing the existing config and selects a per-suite bounded random table namespace. It copies no credential values and drops/truncates no retained plugin tables. The namespace stays within MySQL identifier limits. Reused DB qualification therefore preserves old operation evidence while giving each independent suite a coherent fresh WordPress/plugin namespace. Explicit custom bootstraps must call the helper before defining WP_TESTS_CONFIG_FILE_PATH, or intentionally manage their own namespace for upgrade tests.

Child workers using WP_TESTS_SKIP_INSTALL=1 must inherit the parent's private config path and prefix. The initial namespace attempt exposed one failure in HostingerRecoveryTransportTest::testOverlappingWorkersHaveExactlyOneDurableClaimWinner because children selected a new uninstalled namespace. `resolve()` now reuses the parent namespace and denies if it is unavailable; no concurrency assertion was weakened. Prefix wrappers are unlinked on parent shutdown. Disposable DB cleanup is an external test-environment concern, never production runtime behavior.

The original10-failure log and the one intermediate child-worker failure are retained outside source. Fresh and previously failing reused databases are tested with the same assertions. No production or historical operation is replayed.

## Core handoff and remaining gates

The authoritative core must implement the Batch2 transactional contract: current approval/identity checks inside registration/claim/reconciliation transactions; immutable payload/approval binding; physical duplicate uniqueness; one-shot fences; durable journal plus audit commit before acknowledgement; receipt event/hash/fence/sequence uniqueness; UNKNOWN preservation; terminal-history preservation; and independent checkpoint validation. Inspection must use a separately authorized consistent read snapshot and never invoke write actions.

The companion supplies adapters, reference import, projection and rendering only. Actual core ports, hosted durability/recovery qualification, independent anti-rollback authority, exclusive credential custody, provider identity/binding and all installation/live execution approvals remain blocked. Restoring all trusted roots can still revive old authority unless independently prevented. No signed file/hash alone closes that gate. Stage2 policy remains inactive; STOP ALL, external lock, automation OFF and RELEASE HOLD are preserved.
