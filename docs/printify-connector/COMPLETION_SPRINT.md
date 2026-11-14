# Paired Printify completion sprint — offline review candidate

Deadline: 16 November 2026. Companion baseline 823a5beafa8820101fea04ba5cd05e1f41be3ff0; independently inspected starting core 234c0f6938fd616dba0421d97fba8968c891f826; new frozen core 7d624512a0fab416278b7d8f88208c9463224b6f. Both existing PRs remain draft. Final source pins, counts and CI conclusions belong in the exact-head qualification receipt; a pending run is not a PASS.

## Delivered software

MutationFence::register now requires the actual core PREPARED state and revalidates unchanged authority after registration. Four red/green cases reproduce the previous relabeling of UNKNOWN/FENCED/CANCELLED and post-registration revocation. No error resets or rearms core state.

The full synthetic lifecycle composes preserved upload, create, update, publish-PENDING, order/proof lineage, webhook authenticity/replay, split fulfillment, operator inspection and emergency/revocation boundaries. Synthetic review versions/receipts are explicit fixtures, not inferred live provider facts or native mutation approval.

Core NativeBusinessMutationEvidence 1.0.0 resolves actual protected business/artwork/package/plan-policy source joins and registers a durable nonexecuting HELD candidate. The companion HeldPreparationReadModel consumes actual source and registration results: strict scope/identity/revision/schema/hash checks; no executable JSON values; bounded 64 KiB/depth 32 metadata; explicit Shop 2/IMAGE_UPLOAD restriction; no native version-zero inference. Unknown or changed registration states are not relabeled HELD/PREPARED. All authorization/custody/checkpoint/payload-equivalence flags remain false; native_product_version remains null. Its source_authenticated=false and current_core_verification_required=true fields prevent a self-hashed observation being treated as authenticated authority. It creates no route, transport, vault, mapping, approval store or scheduler.

Actual-source consumption is exercised by tests/contracts/NativeHeldSourceCompanionAcceptance.php with the real core graph and journal under STOP/external-lock ON. Native GET, catalog and partial mutation profiles remain separate. Missing native ports are denial tests, never synthetic positives advertised as native completion.

## Concrete remaining gates

1. Canonical mutation_authority requires approved=true, stop_all=false, external_lock=false and a verified independent checkpoint. These claims cannot be emitted from current inert source/held evidence. Native mutation admission/journal/receipt aggregates, publication/allocation/recovery therefore remain unavailable; only GET_READ/PANEL are advertised.
2. Core upload plans use shop2-{package-prefix}.png; companion upload requests use {asset-sha256}.png. They are different immutable wire payloads even for identical bytes. Current source evidence explicitly reports companion_payload_equivalence_verified=false. A future core-approved exact-wire adoption contract must choose and approve one payload; do not rewrite or rehash an approved request into equivalence.
3. Stored business product_version_id is not a canonical per-product mutation counter. New source output keeps the native counter UNKNOWN/null. Product-create/update source binding is denied until exact saved-provider-product/receipt/mapping joins have genuine conformance; publication is outside the source-only API.
4. The existing durability predicate requires innodb_flush_log_at_trx_commit=1. Previously reported production value 2 means the new source/held path denies on that host. Neither lane changes this predicate or production settings. Isolated InnoDB tests are not host crash/restore certification.
5. Coordinated all-root rollback and old usable credential copies remain independent-recovery/exclusive-custody blockers. Unattended writes remain blocked. Current production account/credential scopes, reviewed Shop2 binding, installation and live provider acceptance remain independently unverified/unauthorized.

## Reproduction and packaging

Freeze both Git SHAs. In the core checkout run bin/package-native-printify-ports.py to create the committed-source archive plus the explicitly generated native-build.json. Validate source-to-artifact.json, CRC and every member against the original Git archive. In the companion checkout run bin/package-printify-connector-stage2.py with the existing hash-pinned.17 reference ZIP. Select its companion ZIP for the paired artifact; its legacy-core composition is a separate compatibility control, not the new native core.

Run the complete source unit/WordPress/offline/static/security suites. Mount the exact core archive and extracted companion in isolated WordPress with network/mail denied. Execute native GET/catalog/default-pin, native-mutation and native-held-source profiles. Use the complete pinned baseline/overlay package harness without omitted files/methods for installed-lineage regression. Record hosted source CI separately from extracted-package evidence. All successful transport responses and identity secrets are synthetic.

Package status is TEST_ONLY_NOT_INSTALLATION_READY while any required gate remains blocked. Archive hashes/source manifests do not override release governance. No production/staging installation, dependency provisioning, live credentials, provider requests, merge or policy activation occurs during qualification.

## Future installation and rollback decision — nonexecuting instructions

Before an owner separately authorizes installation, verify the exact paired hashes/source pins, supported core/companion versions, schema/lifecycle behavior, current installed-byte equivalence and the approved restoration checkpoint. Existing.17 reference package evidence is not proof of the current installed bytes. Preserve actual current plugin files and owner data using the existing approved process; no recovery qualification or backup operation is performed by this sprint.

Only after those gates and separate installation approval: install the reviewed pair in the specifically authorized acceptance environment, retain integration-disabled/STOP/lock/automation/release-hold state, and inspect manifest/discovery without credential use. Installation approval is separate from binding, policy adoption and bounded GET execution; none authorizes a provider mutation.

If that later installation fails before data/schema changes, deactivate the companion and restore the verified preinstallation plugin files through the approved file-replacement procedure. Do not change owner enrollment, grants or audit history. If later authorized activation changes data/schema, file rollback alone is insufficient: use its separately reviewed recovery procedure/checkpoint, preserving external revocation and fail-closed authority. Never drop journal tables, erase UNKNOWN receipts or replay historical operations as cleanup. Restoring WordPress must not restore external credential authority or silently rearm integrations.

STOP ALL ON; external safety lock ON; automation OFF; activation unauthorized; RELEASE HOLD. Production-release decision remains BLOCKED until separately qualified and approved.

## Native fixture isolation correction

The first new-core GET profile recorded40tests/538assertions and one setup error in the PROVIDER_READ dataset: PortalAccessFixture::verifyEmail raised digiforge_portal_email_verification before the provider handler. A separate complete19-case GET boundary run passed, but that did not erase the failure. The captured exception and original logs are retained.

Controlled reproduction established a fixture infrastructure conflict: fresh table prefixes still shared the core test bootstrap's fixed synthetic nonce salts, while MariaDB advisory-lock names are server-wide. Holding the global email-send limiter lock denied another fixture namespace despite independent counters. The same held lock yields the existing generic OTP error/status429. Production behavior is correctly fail-closed and is unchanged.

The native acceptance bootstrap now derives only its test nonce salt from the private random namespace. Parent/child workers inherit the same configuration/prefix; auth/logged-in salts remain unchanged. The install-only WordPress subprocess has no test hook library or authentication and therefore does not register the callback. NativeFixtureLimiterIsolationAcceptance passes1test/10assertions: same-namespace contention remains denied while the independent child namespace is admitted. The full previously failing native suites are rerun; no case or assertion is excluded. These test files are not WordPress runtime or a production authentication change.

## Qualified paired source

Core7d624512a0fab416278b7d8f88208c9463224b6f exact-head Engineering & Safety run43575156758 PASS:1875WordPress/70624assertions(5existing skips),requiredcatalog58/267021,PHP2193/10770(1existing skip),offline198,nativeboundary17/95,ActionScheduler5/87. No workflow or predicate was weakened.

Corrected paired GET40/555 plus catalog10/64 and partial native mutation57/538 PASS. Actual source/held consumer1/56 and protected source panel1/64 PASS with real session/nonce and current-source revalidation; invalid nonces deny and no controls or sender exist. The companion pin is promoted only after these results and exact-head core CI. Default discovery now rejects all three older sources702a,509abc,05091b before admitting7d once. Final postpromotion/package/companion CI results are recorded in the PR and artifact receipt after execution.

Plugin::heldPreparationPanel exposes only an unmounted read-only component using the existing native panel/session/nonce authority and fixed core source API. It rechecks the current source and panel access before return. It displays native version UNKNOWN, payload equivalence UNVERIFIED and mutation approval NOT GRANTED. It cannot register a candidate, approve a request, retry, publish or dispatch. Source classes/registries remain core-owned.
