# API-only acceptance ledger — 2026-11-08

This candidate starts at qualified portal source e957ba096751dc34254cdbcbefed13b625f5e507. Printify PR1253 remains 6e521719d925c75f27aed5ccdd47048cc1e5a15d. POD PR1259 remains a597365c3ffbab022671553675a59a8783931e02. No PR is merged and no POD source is imported. Production installation is unauthorized.

## Official contract reconciliation

Reference: https://developers.printify.com/ retrieved November 8, 2026. The downloadable OpenAPI document returned HTTP403; machine-schema validation is UNVERIFIED. Accessible official endpoint examples inform the deterministic fixtures; these are not official machine schemas. Shops/products/uploads/orders/webhooks remain V1; V2 JSON:API shipping evidence is used where supported. No speculative V2 product/order endpoints are added. Global600/min, catalog100/min and publish200/30min are provider ceilings, not execution grants. Every request requires User-Agent. PAT/OAuth credential lifecycle and actual account scopes require external verification.

| Capability | Implementation/evidence | Disposition |
|---|---|---|
| Credentials and ownership | Existing vault, ScopedProviderConnection, PrintifyConnectionBoundary; no new credential store | IMPLEMENTED; live identity/scopes UNVERIFIED |
| Shop2/Shop3 mapping | PrintifyApiRequest::mapping rejects shared external shop IDs; runtime ownership contracts retained | OFFLINE_VERIFIED; Shop3 remains deferred/inactive |
| Account shop discovery | SHOPS_READ + governed read workflow; projection exposes only configured shop | IMPLEMENTED; LIVE_READ_UNVERIFIED |
| Blueprint/provider/variants/geometry/size guides | Existing ten-request catalog grant, safety exception, reservations, batch coordinator and mapper retained; global provider read contracts added | Existing offline evidence reused; LIVE_READ_UNVERIFIED |
| Regional shipping | Existing V2 shipping projection; no invented rates/destinations/production certification | IMPLEMENTED; missing provider evidence BLOCKED |
| Existing product and mockup readback | Existing PrintifyProductRequest/Response/Pipeline; bounded product-list contract prepared | Product-bound readback IMPLEMENTED; generalized list executor BLOCKED |
| Image upload / product create/update / personalization/preview | Existing package-bound reviewed product pipeline, single-use claims, vault and exact variant/area projection | IMPLEMENTED; LIVE_WRITE_UNTESTED |
| Product publication | Exact explicit field-selection contract only; no execution admission | PREPARATION_IMPLEMENTED; execution BLOCKED pending separate policy |
| Order creation | Existing governed mutation/interlock/dispatch; strict existing-product line/address/shipping contract added; implicit product creation denied | IMPLEMENTED; LIVE_WRITE_UNTESTED |
| Production authorization | Separate PROVIDER_PRODUCTION_AUTHORIZE permit retained | IMPLEMENTED; LIVE_WRITE_UNTESTED |
| Orders/fulfillment/tracking | ORDER_READ/ORDERS_READ governed GET; bounded statuses, exact identity, PII-minimized projection; unknown cost remains null | IMPLEMENTED; LIVE_READ_UNVERIFIED |
| Shipping quote/cancellation | Not admitted through generic transport | BLOCKED; separate PII/operation policy required |
| Webhook subscriptions | No new live subscription executor | BLOCKED; subscription lifecycle/secret provisioning not implemented in this batch |
| Secure webhook ingress | Exact raw-byte HMAC, bounded timestamp/schema/topic/shop checks, replay-conflict classification implemented | OFFLINE_VERIFIED contract; public route and durable replay intake BLOCKED |
| Rate/retry/uncertain outcome | New reads single-attempt, ten/min account bucket; existing catalog retry reserves each request; writes never automatically retry; durable claims precede sending | IMPLEMENTED locally; unified cross-pipeline account budget remains a live-acceptance gate |
| Audit/idempotency | Existing claim/nonce/audit reused; new sealed read plans and audit-anchored receipts; unknown outcomes consumed | IMPLEMENTED; historical #22 unchanged PENDING/UNKNOWN |

The API is **not comprehensively complete** while the explicitly BLOCKED entries remain. Offline tests are not live Printify certification. No live GET or mutation was attempted; STOP ALL and external lock remain ON, automation OFF and RELEASE HOLD active.

## Stable interface for PR1259

Use `PrintifyApiRequest::prepare()` for descriptions, `PrintifyOrderEvidence::validate/project()` for existing-product order contracts, and `PrintifyApiReadWorkflow::prepare/approvalPayload/authorize/receipt()` for governed reads. Dispatch only through `PrintifyControlledTransport::executeApiRead()`. A prepared fingerprint is never a grant. Exact read policy key is `printify-api-read:<plan hash>`; required payload is server-derived by `approvalPayload`, max_requests1, with independent existing ReviewedLocalEvidence review. No REST route or automatic background invocation is registered. Callers must preserve native capabilities, fresh owner verification, exact connection scope and all global locks.

Webhook verification alone is not safe ingress: the future intake must provision unique per-shop secrets privately and durably claim dedupe_key before acknowledgement or business use. The current class authorizes no transitions. No provider/customer payload is logged.

## Installed-lineage composition

The new complete API-only 1.0.103.19 archive uses the exact qualified .17 ZIP (SHA5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac), not a wholesale source checkout. Five new PHP files, four reversible integration edits, zero deletions. The installed stronger dispatch-claim/connection-scope implementation is retained. All other .17 members including scheduler, owner and authentication remain identical. This candidate excludes .18/PR1259 POD additions. A later combined package must review API overlays against1259; never install this API-only archive over a newer combined lineage without reconciliation.

No schema migration is introduced: new immutable records use existing wp_options; nonce/audit reuse existing tables. Dispatch requires autocommit, no outer transaction and InnoDB options/audit/nonce tables. Production observations are not repeated or claimed here. Source CI and exact-package local tests are distinct from a hosted exact-ZIP certification.

## Installation and rollback gates

Do not install under RELEASE HOLD. Require separate owner installation approval, current installed-file comparison to .17 preservation baseline, database compatibility and approved recovery evidence. Keep STOP ALL/external lock/automation settings unchanged. No owner enrollment or credential changes are needed for this candidate. Rollback requires the separately approved consistent checkpoint; this mission does not qualify recovery.

## Qualified source reconciliation

The repository lagged the qualified installed-lineage transport. PrintifyDispatchClaim, PrintifyScopedCredentialRetriever, PrintifyLiveTransportInterlock and the matching PrintifyReconciliationLookup consumer are imported byte-identically from the exact .17 package above. MutationRequest and ControlledTransport retain that same qualified implementation plus only the documented strict-order/header/read-entry edits. The composed ZIP already contained these stronger contracts; this reconciliation changes source consistency, not package bytes. Their imported SHA-256 values are independently verifiable against the pinned .17 archive. No additional transport, credential or provider operation is admitted.
