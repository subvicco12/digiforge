# Companion Batch 4 — publishing intents, orders and authenticated event observations

Companion0.5.0. OFFLINE/SYNTHETIC ONLY. Main core runtime and PR1264 remain untouched. No provider sender, public webhook route, background task, vault, approval store, order store or separate transaction authority is introduced. All real external writes remain denied. Stage2 authorization remains blocked/inactive.

## Provider contracts

Official source: https://developers.printify.com/ . Exact passages retrieved2026-11-12 are retained in OFFICIAL_BATCH4_CONTRACTS_20261112.json. Documentation retrieval is not an authenticated provider API call.

- POST `/v1/shops/{shop_id}/products/{product_id}/publish.json` accepts publication Boolean flags. An empty200 response is asynchronous acknowledgement, not evidence of Etsy publication. Custom API-store publish events are not Etsy publication; this implementation requires reviewed Etsy channel evidence.
- Printify documents `X-Pfy-Signature: sha256={digest}`: HMAC-SHA256 of the **raw request body** using the subscription secret, with constant-time comparison. No undocumented timestamp-signature scheme is assumed.
- Events identify UUID, type, created_at and resource. This batch supports product created/updated/deleted/publish-started and documented order/line-item/shipment event topics. Other topics fail closed. The24-hour admission window and60-second future tolerance are local evidence policy, not a provider replay guarantee.
- Order status and line-item fields follow the documented V1 contract. Optional totals/costs are omitted rather than assigned fabricated values.

## Publication preparation and reconciliation

`PreparationService::publish` produces an immutable PRODUCT_PUBLISH MutationIntent. Exact shop/integration/provider shop, product ID/current version, authorization revision, owner approval fingerprint, channel/product evidence receipts and connected/eligible Etsy state are required. The claim `approver_role=GLOBAL_PRIMARY_OWNER` is not authority: core must independently verify the actual current owner, native capabilities, session and action-specific approval.

`PublishingCoreInterface` is an explicit optional extension: publicationVersion1.0.0 and authorizePublication(binding,prepared). A generic mutation-core implementation cannot authorize publication. Base mutation protocol1.0.0 remains unchanged. The adapter checks publication admission on every core exchange, in addition to existing STOP ALL/external-lock/current-approval checks. Native support is absent by default.

The existing core journal/fence handles registration, duplicate physical product/version identities, one-shot claims, receipt sequences and conflict denial. Changing logical aliases, approval references or flags does not create another admissible publication for the same physical product/version. Empty200 acknowledgement projects PUBLISH_PENDING; errors/timeouts remain UNKNOWN without retry. Operator evidence maps a reconciled publication acknowledgement to PUBLISH_PENDING, never published. A locked product readback remains pending; an unlocked readback requires human review. No Printify publishing_succeeded or Etsy publication endpoint is called or fabricated.

## Typed read-only order observations

`OrderReadModel::fromProvider` returns an immutable minimized observation. It validates provider order/shop identity, documented statuses, bounded line items, positive quantities and provider/variant IDs, structural address fields, ISO country codes and email syntax. Address validation is explicitly STRUCTURAL_ONLY_PROVIDER_UNVERIFIED; it is not deliverability, tax, shipping eligibility or fulfillment certification.

Core-supplied lineage binds **order ID + line-item ID + product + variant + provider + product version** to approved proof receipt references. One proof cannot be inferred for another line item with the same product/variant. Missing/rejected proofs, missing lineage, provider mismatch or unknown status require manual review. Receipt hashes are claims until core resolves their authoritative approval and content. Raw buyer addresses, email, names and personalization text are discarded from the model and operator exports.

Provider and external-channel order identities have stable physical-scope dedupe hashes. The business-content hash excludes read-receipt and transient scope-revision metadata, so a new read receipt alone does not create a false conflict. The core must distinguish legitimate later authoritative order observations from duplicate external order creation; the companion never overwrites an order or submits it.

## Core-owned local observation and webhook interface

`WorkflowCoreInterface` extends the SAME core port with workflowVersion1.0.0, authorizeWorkflow(scope,actor,action), withWebhookSecret(scope,callback), recordWorkflow(envelope), and inspectWorkflows(scope,actor,limit). Fixed actions: record_order, receive_webhook, inspect_workflows. No caller-selected HTTP operation is exposed.

Core must verify current native actor/session/shop/module rights for each action, bind the credential/account/shop association, resolve read/proof receipts, and explicitly deny all external writes. Local evidence intake is not a safety-policy exception. A revoked actor, wrong scope, incompatible interface, unavailable journal or failed post-commit authority check denies acknowledgement.

The secret callback runs only after core admission, inside existing core custody. The companion neither retrieves nor stores the secret; tests use a synthetic-only value. `WebhookContract` verifies the exact raw body signature, bounded JSON, event/resource identity, topic, timestamp and exact provider-shop binding. Private topic data is not exported or applied as business state. Every accepted event still requires authoritative readback.

Core must atomically claim event identity/payload hash and append a durable audit receipt before acknowledging. Identical replay is DUPLICATE; changed payload for the same event is CONFLICT; older or ambiguous per-resource ordering requires OUT_OF_ORDER/manual review. No event automatically changes product/order fulfillment state. Unknown/missing core outcomes remain denied without automatic retries. Invalid signatures produce only minimized WEBHOOK_REJECTION evidence when the local actor/core permit recording it; no raw payload, signature or secret is included. There is no live ingress endpoint.

`DF_WORKFLOW_RECEIPT_1` binds scope/actor/kind/identity/content hash, disposition, positive journal revision and matching recovery/checkpoint epochs, plus canonical receipt_hash. Hashes detect byte changes; trusted core provenance and transactional persistence supply authority. A self-hashed caller object is insufficient. The core must retain original accepted evidence on conflict and preserve UNKNOWN outcomes; coordinated rollback of all trusted roots remains blocked.

## Operator evidence

`WorkflowPanel` and `WorkflowCoordinator::snapshot/export` are callable read-only views for later core portal embedding. They expose at most20 minimized journal observations with fixed reasons for order review, authoritative readback, rejected webhook, event conflict/out-of-order, publication pending and UNKNOWN. Each read requires current core permission, and every returned row must match the exact requested scope fingerprint. No form, retry action, scheduler, public route or secret-bearing raw response is introduced.

## Synthetic qualification and remaining gates

Fixtures test wrong scope/stale approval/version, publication-only admission, duplicates, receipt ordering/conflicts, malformed orders/addresses, missing and cross-line-item proofs, documented HMAC forgery, replay, older events, rejected-event diagnostics, revocation, absent core, storage failure, lost acknowledgement and concurrent event intake. Synthetic file journals demonstrate local atomic claims/reopen behavior only; they do not certify hosted crash durability, independent rollback resistance or real provider connectivity.

The current main core does not implement the optional ports. Remaining integration: native core transactions/audit and current owner/read admission; trustworthy order/proof/catalog/channel evidence; real subscription-secret association and rotation under existing custody; durable inbox quotas/retention and authoritative readback reconciliation; independent recovery/revocation and exclusive credential custody; separate installation and bounded live acceptance approvals. No policy exception is activated.

Recommended Batch5: core-port contract/conformance integration and operator review/import wiring, then expanded order/shipment evidence reconciliation. Do not implement a competing store or live dispatcher. STOP ALL, external safety lock, automation OFF and RELEASE HOLD remain preserved. Historical reservation22 is not inspected, changed or retried.
