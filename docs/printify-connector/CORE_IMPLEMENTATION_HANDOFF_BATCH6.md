# Standalone mission for the DigiForge core Codex session — offline native ports

Implement on the existing core development PR1264 only after reading this handoff and the companion's exact final Batch6 commit. Do not modify companion PR1263, create a competing authority/store or assume that this document authorizes installation or provider execution.

## Immutable inputs

- Core source inspected:702a4d078555ac9cb75fd115357db5387281c74a (development1.0.103.25).
- Companion branch:feat/printify-companion-get-only-20261112, PR1263.
- Canonical spec:companion/digiforge-printify-connector/contracts/native-core-port.v1.json.
- Spec version1.0.0; SHA-256:6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434.
- Normative implementation notes:docs/printify-connector/BATCH6_NATIVE_CORE_PORT_SPEC.md.
- Existing typed interfaces:NativeCorePortInterface extends MutationInspectionCoreInterface, PublishingCoreInterface, OperationsCoreInterface (and inherited MutationCoreInterface/WorkflowCoreInterface).

Verify these bytes before implementing. JSON structural validation AND the listed semantic/native authority rules are mandatory. NativePortSchema implements only the documented bounded subset; do not claim generic full JSON-schema certification.

## Suggested core files — not created by the companion session

1. `includes/Connector/Printify/NativeBridge.php`: pure versioned factory. Check interface availability lazily so core boots safely without the companion. No DB/vault/network work during discovery.
2. `includes/Connector/Printify/NativeCorePort.php`: thin facade implementing the exact composite interface. Reuse the current core client, vault, mapping, owner, transaction and audit components; do not implement another transport/identity/approval system.
3. `includes/Connector/Printify/NativeAuthorityAdapter.php`: delegate current actor/session, native capabilities, Global Owner/fresh step-up, ShopAccess and reviewed provider/account/credential relationship checks to authoritative core components. Exact actor/shop/module/approval/product/order revisions; fail closed on unknown metadata.
4. `includes/Connector/Printify/NativeJournalAdapter.php`: reuse or adapt authoritative core journal/repositories for atomic immutable registration, duplicate identity, one-use fencing, trusted receipt provenance, UNKNOWN preservation, audit-before-ack and local-review CAS. Never invoke ControlledExecutionTransaction::execute as a passive journal helper.
5. `includes/Connector/Printify/NativeInspectionAdapter.php`: consistent SELECT-only mutation/workflow/review/fulfillment snapshots with bounded exact schemas and minimized exports.
6. `includes/Connector/Printify/NativeAllocationAdapter.php`: trusted order/line-item/product-version/proof allocation joins. No inferred quantities or fabricated identity.
7. `includes/Connector/Printify/NativeRecoveryAdapter.php`: read-only journal/checkpoint/generation/drain diagnostics. Bind core-owned emergency STOP events to independent generation revocation, including repeated STOP while already ON. No automatic rearm. Keep admission non-executable if durable independent enforcement is unavailable.
8. `includes/Connector/Printify/NativePanelAccessAdapter.php`: current WordPress session/nonce and exact read scope, no administrator→owner fallback. Embed companion ProtectedPanel only through the existing protected portal/router; no public proxy.
9. Preserve/add the GET `Bridge::connect('1.0.0')` compatibility factory via the reviewed existing GET interface/NativeGetConnector shim. Delegate actual GET requests to the existing core transport and all current safety gates. A GET capability does not approve execution under STOP ALL.

These are organizational suggestions; preserve working core components instead of duplicating them. If existing storage cannot meet the guarantees, report the exact schema/transaction limitation and prepare any offline migration proposal separately. Do not apply production migrations, substitute options/transients for durable evidence or fabricate successful acknowledgements.

## Required method semantics and tests

Run companion conformance fixtures against the new native implementation, with real disposable core stores where available:

- Factory/version/manifest/source/spec mismatch; missing capabilities; absent companion; no eager credential/provider access.
- Fixed GET grammar, caller identity/shop/integration binding, existing projection/redaction, no arbitrary URL/header/body or token exposure; STOP ALL/external lock deny live provider execution.
- Registration/claim CAS with two workers, one durable winner, immutable payload/approval and physical duplicate identity, replay denial, lost commit acknowledgement and restart.
- Missing journals, stale authority/product/order/review versions, revoked approvals, wrong shops/credentials/accounts and unavailable audit persistence deny.
- Trusted receipt producer/fence/hash/sequence/epoch checks, exact duplicate idempotence, conflicting/out-of-order receipt denial, UNKNOWN never retried or relabeled as failure/success without new authoritative evidence.
- Repeated emergency STOP events advance revocation even if the Boolean is already true. Recheck before custody and final dispatch. Pending drain prevents acknowledgement; persistence failures cannot revive old authority. Synthetic success is not hosted durability or coordinated-rollback certification.
- Current allocation proofs/revisions and split quantities; no over-allocation. Cancelled/partial/exception observations remain read-only.
- Current WordPress session/nonce and owner/module scope for protected panels. No values in URLs/logs/exports; revocation/session destruction denies subsequent reads. Review decisions change local review state only.
- WordPress integration, all existing unit/offline/security/static checks, extracted-package acceptance and exact-head Engineering & Safety CI. Preserve all prior tests and reservation22.

## Acceptance and return

Return the exact core SHA, changed files, native method-to-repository mapping, source/spec manifest, test counts, transaction/crash/concurrency evidence, schema impact and remaining independent recovery/custody/provider blockers. Report whether the new source implements the typed ports; do not equate schema conformance with operational authority.

Do not falsely report the old reference SHA as the new implementation identity. The companion's expected core source pin is trusted composition/configuration, never a caller override; supply the genuine new core SHA for separate companion pin review. Use synthetic identities, credentials and provider responses only.

No production/staging installation, merge, provider requests, credentials, publication, orders, webhooks, Cloudflare provisioning, policy activation or historical replay. STOP ALL ON, external lock ON, automation OFF and RELEASE HOLD active. This is an offline implementation mission only; installation and live acceptance need separate approval.
