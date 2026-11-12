# Native Printify core boundary — partial implementation, non-executable

Core baseline: PR1264 702a4d078555ac9cb75fd115357db5387281c74a (1.0.103.25).
Pinned companion: PR1263 a7dca70902f755c38f04aa7793040a8253a3056d.
Batch6 canonical JSON SHA256: 6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434.

**This is not completion of the native operational ports.** All composite signatures exist; only scoped panel authentication is advertised as a complete native capability. Read-only local authorization is available directly, but WORKFLOW is not advertised because its persistence/snapshot services are incomplete. GET, journals, publication, allocation and recovery are denial-only boundaries. No caller, administrator, source hash or synthetic fixture can turn them on. No production/staging installation or provider execution is authorized.

## Implemented boundary

- Inert NativeBridge::connect('1.0.0'), optional companion composite-interface discovery and schema integrity. Absent/wrong version returns WP_Error. No companion implementation is bundled at runtime.
- GET Bridge::connect('1.0.0') and immutable shared value contracts from pinned companion core compatibility files. Exact fixed GET grammar, no URL/header/body callback or provider sender. Runtime STOP/lock checks precede provider authority. An independently resolved read-plan/grant join is still required; configured connections and upload source references do not supply one.
- Current real WordPress cookie identity, session-token existence/revocation/expiry, native capabilities and ShopAccess signed admission. Current independently reviewed ScopedProviderConnection, PrintifyConnectionBoundary and Integrations Repository metadata bind shop, environment, provider, integration, binding hash, expiry and revision. No vault decryption.
- Panel nonce binds exact version + scope fingerprint + fixed view. Current session fingerprint is recomputed, never trusted. Access rechecked after nonce. Panel receipt hashes match companion MutationIntent canonical format. The companion's existing ProtectedPanel omits auth values from exported snapshots. No portal route is mounted here.
- Owner-sensitive publication additionally delegates Owner::require (Global Primary Owner + fresh native email verification); it never grants publication. Native shared approval/owner behavior unchanged.
- Every exact mutation/inspection/recovery/allocation/receipt/review interface has an explicit non-executable denial. No fake empty snapshot, fabricated epoch, persisted receipt, generation increment or successful drain acknowledgement.

## Method-to-core matrix

| Port | Existing core authority/storage | Result / missing native guarantee |
|---|---|---|
| read / GET Bridge | Settings, ShopAccess, PrintifyConnectorReadContract; existing PrintifyApiReadWorkflow/PrintifyControlledTransport remain unchanged | Fixed grammar + scoped prechecks; BLOCKED exact independently approved read plan/authorization join. No alternate transport. |
| exchange AUTHORIZE/REGISTER/CLAIM/OUTCOME/RECONCILE/CANCEL/RECOVER/RECEIPT_READ | PrintifyOperationClaim, PrintifyDispatchClaim, POD nonce/binding/outcome/receipt/unknown repositories | BLOCKED native atomic identity/physical duplicate/journal/audit/checkpoint join. Options-backed legacy claims are not substituted. |
| inspect | ShopAccess, existing execution receipt/reconciliation repositories | BLOCKED exact operation ID to native authorization/fence/provenance snapshot join. A caller hash is not a trusted core receipt. |
| authorizePublication | native module scope, Settings, Owner::require, PrintifyProductPipeline's current policy checks (unchanged) | BLOCKED publication-specific current approval/product/checkpoint join. Etsy PublishReviewRepository is not used as Printify authority. |
| authorizeWorkflow | session + signed shop/module + current reviewed connection | Current local-read authorization only; never an execution grant. |
| withWebhookSecret | existing PrintifyWebhookInbox authenticates with core-owned secret internally | BLOCKED exclusive-custody bridge. Callback is NEVER invoked with secret material. |
| recordWorkflow / inspectWorkflows | existing PrintifyWebhookInbox / PrintifyApiReadWorkflow | BLOCKED atomic event/approval/receipt identity mapping and consistent native snapshots; no second store. |
| reviewQueue / decideReview | current signed module access | BLOCKED exact review subject/payload/auth/product/order/epoch CAS and consistent queue. No caller-driven outcome change. |
| recordFulfillment / inspectFulfillment | existing order evidence and fulfillment models | BLOCKED trusted read-receipt/order/allocation lineage join. |
| allocation | existing Orders/ApprovedPodMappingResolver + order line items | BLOCKED approved allocation proof/revision/checkpoint join. No inferred quantities/tracking identity. |
| recovery INSPECT | current signed read scope; Settings boolean protection unchanged | BLOCKED independent monotonic checkpoint + journal generation + inflight lease/drain evidence. No false empty/ready receipt. REARM/RETRY/RESTORE unsupported. |
| panelAccess | WP native cookies/session/nonce, ShopAccess, ScopedProviderConnection | Implemented. No owner fallback, no provider grant. ProtectedPanel data remains unavailable without native OPERATIONS snapshots. |

## Durable and emergency-STOP blockers

Existing pod_execution_nonces, pod_authorization_bindings, pod_execution_outcomes/receipts/unknowns/failures and immutable audit provide useful foundations. They do not presently resolve the companion's operation/intent/scope/physical duplicate IDs to one transactional core journal with current approval and product/order revisions, fence, sequence, epoch and trusted receipt producer. PrintifyOperationClaim and PrintifyDispatchClaim use wp_options plus audit and their own legacy identities; Batch6 explicitly prohibits substituting options/transients for the required native journal.

Existing Settings::protectProduction persists Boolean STOP ON and activation/automation OFF. Repeated calls do not prove an independent, monotonically advancing revocation generation, independent checkpoint or drained credential/dispatch leases. A restored database and salts could restore all current roots. No independent checkpoint or exclusive distributed credential-custody service is configured/qualified by this task. Synthetic success cannot certify these requirements. No change is made to emergency STOP or any existing execution path; the new native path remains permanently non-executable, including when synthetic flags are unlocked.

The smallest next native implementation needs a reviewed core-owned journal/schema proposal adapting the existing repositories, exact identity/approval resolver, independent revocation/checkpoint/lease interface and hosted custody/rollback qualification. No migration was added or applied here. No external service or secret is provisioned.

## Source and package identity

Unbuilt source has no source-manifest claim; contractManifest throws if the fixed build stamp is unavailable. `python3 bin/package-native-printify-ports.py OUTPUT` creates an offline committed-source archive and adds exactly one generated native-build.json. The stamp contains the genuine built commit and hashes of all native runtime files; the runtime checks its complete inventory. No request/filter/constant selects the source pin. It is package-integrity metadata, not a signature or remote attestation; the whole archive hash and independently reviewed source pin remain necessary. All original git archive member bytes are preserved, but whole ZIP bytes intentionally differ because of the declared stamp. This does not change the canonical package script or assert installability/production preservation acceptance.

Companion test snapshots contain only exact committed interfaces/conformance code and canonical JSON; no transport or implementation is imported into runtime. Shared five value/interface contracts and PrintifyConnectorReadContract are byte-identical to pinned companion core paths. NativePortContract adapts the bounded validator into core with a canonical receipt hash helper; no full JSON-schema certification is claimed.

Companion composition must separately pin the genuine resulting core SHA. Its old 702a4d reference cannot authorize this modified implementation. PR1263 is unchanged.

## Acceptance limits

Focused tests prove bounded contracts, current real sessions/nonce/signed scope and non-executable denials under missing native evidence. Existing core safety regressions are run separately. No successful native REGISTER/CLAIM, two-worker winner, lost-commit recovery, trusted provider receipt import, native allocation or emergency generation/drain qualification is claimed. These are **unimplemented obligations**, not passing synthetic conformance. Operational core-port integration remains BLOCKED.

STOP ALL ON; external safety lock ON; automation OFF; Manual order approval; RELEASE HOLD. No installation, activation, provider requests, real credentials, publication, order, merge or production write.
