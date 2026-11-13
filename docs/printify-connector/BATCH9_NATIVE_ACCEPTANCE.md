# Batch 9 actual-native integration acceptance

## Exact sources and scope

Starting companion: ac3f61c1cccec1a49bddf2f1f3c8975e40d8a8f3. PR1264 independently advanced from the requested 5fc4c7af reference to **d154c601b3c5ed0ec93eaaac268bc4bba26758f4**. The current source was fetched and inspected in a detached clean worktree. Core source and PR1264 were not modified.

Core's own committed-source packaging tool produced `digiforge-native-ports-offline.zip`, SHA-256 **ae2061c08827efca17928f13494c07318dfeb43c5a375ea29ae68b910c8edd5a**; canonical git-archive SHA-256 **20b326c5455324ff08b533e2ea11345c5af0a7115592bbe8a12ea45653d69e8e**. Its source-to-artifact transformation adds only the build-owned native source/hash stamp. Core runtime bytes were verified against this artifact, not replaced by mocks. Test support files were added only in the isolated composition: exact core test fixtures, companion runtime, the companion FixtureConfiguration helper and the core's exact PHPUnit configuration. They are not part of a production package.

## Native matrix

| Interface / workflow | Actual implementation | Acceptance disposition |
| --- | --- | --- |
| Factory, versions, source/spec identity | NativeBridge + NativeBuildIdentity + native registry | 18 manifest/interface/denial checks PASS on exact built source |
| Panel permission | NativeAuthorityAdapter + NativePanelAccessAdapter | Positive real isolated WordPress session, signed synthetic shop grants, independently reviewed synthetic connection, nonce and actual companion adapter PASS for five views |
| Workflow read authorization | authorizeWorkflow | Positive read authorization PASS; never provides a data snapshot or execution grant |
| GET admission | NativeReadConnector | STOP/external lock deny before any HTTP/ciphertext access. Positive GET BLOCKED: operational grant join absent; GET_READ not advertised |
| Mutation REGISTER/CLAIM | exchange | BLOCKED: journal_and_independent_checkpoint_required; not wired to held journal |
| Receipt import / RECOVER | exchange | BLOCKED: same missing native journal/checkpoint; no invented successful receipt |
| Inspection / publication | inspect / authorizePublication | Native operation identity join missing; publication remains externally locked |
| Publication/order queues | reviewQueue / inspectWorkflows | BLOCKED: trusted snapshot joins absent; protected companion render fails closed after valid panel permission |
| Allocation | allocation | BLOCKED: approved allocation/proof join absent |
| UNKNOWN/conflict reconciliation | missing authoritative receipt/workflow joins | No native positive pass claimed. Existing companion synthetic coverage preserved |
| Emergency recovery | recovery | BLOCKED: independent checkpoint/drain required; no emergency acknowledgement fabricated |
| Held candidate persistence | NativeHeldJournal + explicit disposable proposed schema | Positive real InnoDB local prepare/fence/read-after-reinstantiation/UNKNOWN and duplicate-fence denial PASS; record retains approval_verified=false, dispatch_permitted=false, independent_checkpoint_verified=false |
| Revocation / scope / source pin | native session/capabilities/mapping + companion adapter | Wrong pin, stale binding, cross-shop, invalid nonce, revoked capability and destroyed WordPress session deny |

Native capability advertisement remains **PANEL only**. The held journal is a native local candidate store, NOT the versioned mutation host or independent recovery authority. No external response sender was enabled. HTTP and credential-ciphertext SELECT tripwires remained at zero. Only test-local synthetic encrypted material was seeded; no real provider token exists in this fixture.

## Local actual-core result

The composed native WordPress suite passed **7 tests / 247 assertions**; the separate exact manifest/interface/denial probe passed **18 checks**. Positive panel, scoped read authorization and local held candidate persistence are actual core paths. No successful native GET, mutation-port reservation, receipt import, snapshot/reconciliation or recovery is claimed. Both HTTP and credential-ciphertext read counters remained zero.

## Pin decision

**DO NOT UPDATE the default trusted core SHA pin.** This source passes limited panel and local candidate-persistence conformance, but complete native port integration is not implemented. Tests explicitly compose a NativePortAdapter with the independently verified SHA as trusted test configuration only. No caller or request can update runtime trust. Wrong-pin denial is tested. No business safety policy is relaxed.

## Reproduction

1. Fetch and verify the exact core commit and ancestry in a clean detached local worktree. Run its `bin/package-native-printify-ports.py` offline; verify source-to-artifact hashes and ZIP CRC/member integrity before extraction.
2. In an isolated disposable composition, retain every core runtime member unchanged. Add the exact core `tests/` tree, the reviewed companion tree, companion `tests/wordpress/FixtureConfiguration.php` helper and the exact core PHPUnit WordPress configuration as test scaffolding. Load the real companion before core test-fixture fallback interfaces.
3. Mount only synthetic WordPress/database config as `/private/native-test-config.php`; no production/staging files or credentials. Use MariaDB via Unix socket with network disabled. Preserve core WP_ENVIRONMENT_TYPE=local, unique upload path and template fixture initialization by invoking the actual core tests/wordpress/bootstrap.php. Add HTTP/email preemption before core plugin bootstrap and load the real companion afterward at muplugins_loaded priority11.
4. Run `tests/contracts/native-wordpress-bootstrap.php` and `tests/contracts/NativeCompanionWordPressAcceptance.php` using PHP8.3/PHPUnit9.6/isolated WordPress. Set test-only `DF_NATIVE_TEST_SHA` to the independently reviewed exact commit. No runtime source pin is written.
5. Explicit held-journal schema creation occurs only inside the disposable test database. Never migrate production or treat local flush/fsync tests as hosted durability certification. Preserve all-root rollback, independent anchoring and exclusive custody blockers.

The bootstrap and acceptance file are explicit test harnesses, not routes, abilities, deployment code or provider adapters. This local composed-native suite is separate from hosted companion source CI and the legacy installed-lineage extracted-package suite. Initial composition lacked the test-only FixtureConfiguration helper; adding that helper fixed bootstrap without changing core runtime. A later attempted broad run used the legacy configuration/custom bootstrap and therefore missed the core local-environment, unique-upload and template setup. That invalid broad run was stopped and preserved as unqualified evidence, not attributed to a core regression. The corrected harness delegates to the actual core bootstrap and exact configured suite. Four legacy Printify catalog files excluded by the core configuration remain explicitly outside that configured suite and must be accounted for separately; no exclusion was added here.

## Remaining work / Batch 10

Separate core session must implement reviewed native operational-grant joins, transactional mutation/journal/receipt wiring, trusted order/publication snapshots, allocations and independently enforceable checkpoint/drain and credential-custody contracts. Expose capabilities only when genuinely complete. Then independently inspect the new exact core SHA, rerun both positive and negative real-native acceptance, and review a source-pin update separately. Do not turn held candidate fences into dispatch permits or equate synthetic/local durability with production guarantees.

No installation, provider call, real credential access, provider write, publication, order submission, Cloudflare, merge, policy activation or historical replay. STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD.
