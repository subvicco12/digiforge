# Companion Batch5 — pinned core compatibility, local review and fulfillment observations

Companion0.6.0. OFFLINE/SYNTHETIC ONLY. No native core authority, approval store, dispatcher, scheduler, credentials or schema migration is introduced. Main PR1264 is read-only throughout this batch.

## Actual core source and compatibility decision

Inspected PR1264 head: **702a4d078555ac9cb75fd115357db5387281c74a**, development header1.0.103.25. This is distinct from the installed-lineage1.0.103.17 package used for preserved extracted-package tests. Source hashes for eight actual core files are pinned in BATCH5_CORE_CONTRACT_MANIFEST.json. Test-only exact source fixtures compile in a separate PHP process without booting WordPress, SQL, vault or provider code. Pure FulfillmentEvidence validation is exercised against the pinned native implementation. Validator source matches the companion baseline byte-for-byte.

| Concern | Actual core contract | Companion handling |
|---|---|---|
| Actor/shop permissions | Security\ShopAccess::authorize(shop,cap,actor); PortalAccess\Owner::forActor; Owner::require adds fresh email step-up | Delegate safe local-scope checks to native ShopAccess. No WordPress-admin/email owner fallback. Native owner/session checks remain core-owned for review commits. |
| Shop/provider mapping | POD\ScopedProviderConnection::verified(shop,integration,capability), DF-OWN-001 local relationship evidence | Inspect signature only. Local binding is not credential/account/API identity or execution permission. No mapping writes or fabricated association. |
| Transactions | POD\ControlledExecutionTransaction::execute consumes nonce then invokes adapter and persists terminal/UNKNOWN evidence | **Never invoked** by this companion. Not a safe local-review substitute. |
| Publication review | Listings\PublishReviewRepository is explicitly Etsy/seller/listing/package-specific | Cannot be relabeled as Printify approval. No calls to its mutation methods. |
| Generic local evidence | Security\ReviewedLocalEvidence has governed propose/decide transactions | Do not invent a new domain or use it as a second approval system. Core integration must adapt it explicitly. |
| Fulfillment preparation | Orders\FulfillmentEvidence::validate(shipping,cost,currency) | Safe pure adapter validates required native economics and shipping method; missing costs deny, no fabricated values or execution grant. |
| Versioned bridges | Core source has neither Connector\Printify\Bridge nor MutationBridge | Typed GET/mutation/workflow/review integration is **BLOCKED_NATIVE_PORT**. No direct transport or execution fallback. |

`CoreContractAdapter` performs bounded fixed-class ABI discovery and safe native validation/delegation. Its reference SHA is documentary, not runtime byte attestation. It never equates method availability with owner authority, production equivalence or live readiness. Required bridge negotiation remains separate. No core runtime files are imported into the companion or modified on PR1264.

## Versioned review interface and invariants

`OperationsCoreInterface` extends the same WorkflowCoreInterface, with operationsVersion1.0.0, reviewQueue(scope,actor,limit), decideReview(request), recordFulfillment(request), and inspectFulfillment(scope,actor,orderIdentity). Native core implementation is currently absent. Discovery uses only the fixed MutationBridge; no caller-selected callback or public route is added.

Core must authorize current actor/session/shop/module for inspect_reviews, record_review, record_fulfillment and inspect_fulfillment. External writes must remain denied. Future browser integration must validate native nonces before invoking a mutation; there is no browser mutation handler here. Strong owner/fresh-email checks and independent approval requirements are core duties, not replaced by a role string or local UI state.

DF_REVIEW_QUEUE_1 carries exact scope/actor, checkpoint epoch, at most20 rows and canonical evidence hash. Rows bind core-owned subject/payload hashes, PUBLICATION/ORDER/FULFILLMENT kind, operational state, independent review state/revision, product/order versions, authorization revision, recovery epoch and fixed blocker codes. Unknown/private fields deny export. Epoch disagreement displays SNAPSHOT_STALE and blocks decisions.

Local decisions: ACKNOWLEDGE, REQUEST_CORRECTION, REJECT, REVIEW_LOCAL. Review states: PENDING, ACKNOWLEDGED, CHANGES_REQUESTED, REJECTED, REVIEWED_LOCAL. Operational state is never changed by a review receipt. UNKNOWN/CONFLICTING cannot be approved or rejected away; acknowledgement/correction preserves their uncertainty. REVIEW_LOCAL requires a known pending queue state and no blockers, and **never grants execution**. Terminal local review decisions cannot be silently reopened.

The coordinator rereads current core queue evidence, compares the exact row/versions and checkpoint, binds a deterministic request hash, then requires a hashed core receipt with the expected revision increment and unchanged operational state. Core MUST implement transactional compare-and-swap of current actor authority, exact payload/approval/version, review revision and epoch; persist journal plus audit before acknowledgement; reject duplicate/conflicting/stale decisions; and retain lost-ack outcomes. Companion checks are restrictive protocol validation, not another authority or transaction implementation.

ReviewPanel is a callable read-only renderer; no route/form/scheduler or production integration is registered. Queue/export reads are reauthorized. JSON exports contain bounded hashed identities, states, versions and blocker evidence, never raw tracking references, credentials, addresses or free-text audit errors. Hashes provide consistency checking, not signature-based independent approval.

## Shipment and fulfillment contracts

Official V1 shipment metadata: carrier, number, url, delivered_at and line_item_ids (https://developers.printify.com/#shipment-properties). No provider shipment ID, per-shipment quantity or shipped_at value is invented. Tracking keys are derived locally and always scoped by parent order identity in core; raw references remain case-sensitive. URLs are structurally checked but never followed, rendered or exported.

ShipmentReadModel binds the existing typed order and exact scope, order/current expected version, every line item's product version, trusted read-receipt reference and observation time. It validates bounded carriers/references, delivery timestamps, exact line IDs, duplicate rows and quantities. Split-shipment quantities require explicit **core-reviewed allocation evidence**. Line-item membership alone does not prove quantities; missing allocations remain unverified. Over-allocation, stale versions and wrong scope deny. Identical tracking rows dedupe; conflicting tracking rows retain UNKNOWN/manual review rather than overwriting evidence.

States distinguish PARTIAL_TRACKING_OBSERVED, COMPLETE_TRACKING_OBSERVED, DELIVERY_OBSERVED, CANCELLED_ORDER_OBSERVED, EXCEPTION_REVIEW_REQUIRED and UNKNOWN. The normalized provider order status is retained separately. Tracking coverage is not certified production or delivery. Cancellation remains an observation, never a cancellation request or refund guarantee. Missing product lineage remains blocked. All observations require human/core evidence review and have external_execution_authorized=false.

FulfillmentReconciler classifies same-order snapshots as DUPLICATE, OUT_OF_ORDER, STALE_VERSION, CONFLICT or REVIEW_REQUIRED. It never applies state, clears a fence or permits retry. Current core must resolve real read receipts, allocation/product/order versions and provider identities, and make atomic monotonic journal decisions. Typed read-only inspection is gated by current core authority, exact order identity and expected version; no locally cached snapshot can independently authorize a decision.

## Qualification boundaries and remaining work

Synthetic fixtures cover native ABI absence, native-admin denial, stale approvals/versions, revoked read authority, wrong-shop records, partial/split shipments, missing allocations, delivery/cancellation/exceptions, tracking conflicts, duplicates/older snapshots, restored review records against a newer independent epoch, lost acknowledgement, UNKNOWN preservation and redacted exports. The existing safety/authorization/replay suites remain intact.

Synthetic epoch and journal tests do not certify hosted durability or resistance to coordinated rollback of all trusted roots. Exclusive provider credential custody and independent recovery/revocation remain unqualified. Historical reservation22 is not read, retried or modified. No production/staging install, provider request, order submission, publication, webhook provisioning or policy activation occurs.

Recommended Batch6: coordinate and qualify the native core-port implementation on the core-owned lane; add typed conformance tests against that new exact SHA; then wire protected operator review/fulfillment panels and trusted receipt joins. Continue exception/recovery evidence tooling only within approved offline scope. Do not add competing approval/mapping stores or an independent sender. Installation, real identity/binding/scopes and bounded live acceptance require separate approval. STOP ALL, external lock, automation OFF and RELEASE HOLD remain active.
