# Standalone Printify acceptance ledger

This is an offline engineering checkpoint. The standalone package is passive and
advertises zero provider capabilities. The existing qualified operation ledger
remains reference evidence for the integrated API implementation.

| Component | Verified starting SHA | Scope |
| --- | --- | --- |
| API #1260 | 035b693713fe162cf3f74edd6d5e2f6217cff9e1 | Sole integration target |
| Companion #1263 | 36a713cbbc8af36a04497e9bf4679c1976793f71 | Read-only compatibility reference |
| Core #1264 | c02b248c14eb71a571e5dae881fe0a10823b5560 | Read-only contract reference |

| Lane | Owned paths in this checkpoint | Offline evidence | Remaining standalone acceptance |
| --- | --- | --- | --- |
| A, provider | includes/POD/PrintifyApiRequest.php, includes/POD/PrintifyApiReadEvidence.php, tests/unit/PrintifyApiBoundaryTest.php | Order validation enforces actual request limit and preserves it on continuation. | Bind the qualified catalog, geometry, upload, product, shipping, order, cancellation and tracking operations through explicit standalone services. |
| B, native | contracts/cloudflare-dispatcher/NativeObservationConformance.php and test/core-observer-conformance.test.mjs in that tree | Future held-journal events are rejected. Existing native contract identity stays pinned. | Authoritative REGISTER, CLAIM, allocation and recovery remain gated by the existing Core and independently qualified checkpoint. |
| C, execution | contracts/cloudflare-dispatcher/sender.mjs and test/custody-fencing.test.mjs in that tree | A late synthetic response body is cancelled after a deadline or custody failure. UNKNOWN and one-use slots are retained. | Exclusive usable credential custody, independent recovery and hosted revocation/drain qualification remain external prerequisites. |
| D, standalone | standalone/printify-api/ | Passive bootstrap, inert status, exact Git-blob manifest, deterministic ZIP and extracted bootstrap checks. | Configuration and operator UI, complete capability discovery, supported runtime composition and disposable WordPress installation acceptance. |
| Integration | .gitattributes, .github/workflows/printify-standalone-offline.yml and this ledger | Standalone tree excluded from Core export. Dedicated offline CI tests source and rebuilt artifact. | Await exact-head hosted CI and add end-to-end standalone provider acceptance. |

Existing integrated API operations and their approval rules are documented in
[the operation ledger](OPERATIONS-1.0.103.20.md). Their offline tests do not imply
standalone capability or live provider acceptance. The native and synthetic
sender paths remain distinct from the standalone bootstrap.

The governing 19 November 2026 architecture document and its sections 1–24
were not located during this checkpoint. Obtain the exact source before final
feature-by-feature certification. Do not substitute an earlier blueprint.
Shop 2 and its WordPress diagnostic were excluded.

STOP ALL and external safety lock remain on. Automation remains off and RELEASE
HOLD stays active. No production installation, activation or provider execution
is authorized.

## Day 2, governing architecture reconciliation

The owner supplied **Printify API Plugin FINAL Architecture Complete**, dated
19 November 2026, including Sections 1–24. This table records scope against
that source. Statuses describe this standalone build unless noted.

| Section | Capability | Disposition and evidence |
| --- | --- | --- |
| 1 | Standalone product, one native authority | PARTIAL. Independent bootstrap and ZIP. No authority implementation copied. |
| 2 | Evidence baseline | PARTIAL. Source and packaged smoke tests. Keep inherited integrated API evidence separate. |
| 3 | Components and SDK | PARTIAL. Local runtime, Tools panel and scoped catalog validator. Consumer SDK and governed runtime wiring open. |
| 4 | Trust boundaries | PARTIAL. No sender, credential access or provider calls from the standalone runtime. Hosted trust unqualified. |
| 5 | Data ownership | PARTIAL. Scoped input digest only. Durable provider-attributed snapshot storage open. |
| 6 | Capability and wire versions | PARTIAL. PANEL/DIAGNOSTICS and OFFLINE_CATALOG_VALIDATE are the only discoverable features. GET and mutation families remain unavailable. |
| 7 | End-to-end flows | PARTIAL. Local catalog input checks. Authorized fetch, durable snapshot, product and order journeys open. |
| 8 | Safety invariants | PARTIAL. Standalone holds are fixed and no operational paths exist. Hosted sender fencing is unqualified. |
| 9 | Failure model | PARTIAL. Local invalid input denies. Synthetic UNKNOWN and retry evidence remains in existing dispatcher. |
| 10 | Package and installation | PARTIAL. Deterministic isolated ZIP and extracted bootstrap. Real disposable installation, upgrade and uninstall open. |
| 11 | Operator UI | PARTIAL. Admin Tools health, capability discovery and held execution status. Operational queues, dependency telemetry and audit export open. |
| 12 | Acceptance tiers | PARTIAL. Fixture and source/artifact checks. Standalone WordPress/MariaDB, hosted and provider tiers open. |
| 13 | Release gates | BLOCKED_EXTERNAL. Independent checkpoint, custody and hosted recovery remain open. |
| 14 | Completion semantics | PARTIAL. Local checks only, RELEASE HOLD. |
| 15 | Reference evidence | PARTIAL. Exact architecture and starting source IDs recorded. No transfer of earlier execution authorization. |
| 16 | Standalone runtime and pins | PARTIAL. Missing packaged validator denies its capability. Package records exact input and generated module digests. Runtime dependency attestation open. |
| 17 | Provider registry | PARTIAL. Nine catalog kinds reuse the existing exact request/response validator. Product, image, order and tracking standalone routing open. |
| 18 | State machines | PARTIAL. Local validation and denial only. Existing governed mutation/recovery state separate. |
| 19 | Transactions | PARTIAL. No standalone writes or schema changes. Native owns operation state. |
| 20 | Root and custody | BLOCKED_EXTERNAL. All-root rollback and old usable credential copy counterexamples remain open. |
| 21 | Events and reconciliation | PARTIAL. Existing synthetic contracts remain. No standalone webhook or reconciliation activation. |
| 22 | Budgets | PARTIAL. Catalog input limited to 256 KiB and JSON depth 24. Operational budgets and quota health open. |
| 23 | Compatibility and upgrades | PARTIAL. Separate PHP symbols and reproducible artifact. Runtime coexistence, restore and installation matrix open. |
| 24 | Remaining evidence | PARTIAL. Architecture reconciled here. Provider fields, authority infrastructure, hosted durability, installation and live acceptance unresolved. |

## Day 3, offline lifecycle and disposable storage

The packaged Runtime adds scoped `OFFLINE_LIFECYCLE_REVIEW` when the shop and
account configuration match. The deterministic fixture validates catalog
variant membership, product intent and artwork placement, actual PNG bytes,
existing-product order and shipping intent, and tracking readback against the
same operation and line quantities. The simulated timeout remains UNKNOWN.
A synthetic matching readback neither confirms a provider outcome nor
authorizes retry. Approval and version values are fixture bindings, separate
from trusted native approvals and revisions.

The opt-in InnoDB fixture store inserts catalog snapshots under a unique
identity, records exact scope, source label, digest, observation time and
unverified freshness, and verifies bytes before reading metadata. It is
limited to a disposable local database with no automatic schema or activation
hooks. Real WordPress/MariaDB fixture checks passed for duplicate insert,
scope and actor denial, and retrieval after database restart. This is
fixture snapshot durability, separate from native operation idempotency,
hosted recovery, and authenticated provider snapshot provenance.

No operational GET, mutation, scheduling or custody capability is advertised.
Native admission and recovery, live provider attribution, action currency and
stock evidence, geometry beyond the validated contract, and broader standalone
operation coverage remain open. Shop 2, Core and Companion are unchanged.

## Standalone 0.5.0 and 0.6.0 delta against sections 1–24

| Sections | Source capability | Offline verification | External qualification |
| --- | --- | --- | --- |
| 1–4, 10–12, 15–16, 23 | Standalone bootstrap, operator discovery, exact-source packaging and separated runtime symbols. Standalone SDK and operational compatibility matrix remain incomplete. | Source and extracted smoke, deterministic ZIP, disposable activation. | Live composition and release approval unavailable. |
| 5, 8–9, 18–20 | Exact-selector signed synthetic catalog evidence and insert-only fixture snapshots. Native register, claim, checkpoint and journal authority remain outside the standalone runtime. | Duplicate denial, stale and cross-scope denial, restart readback, synthetic UNKNOWN and retry denial. | Qualified issuer/witness, exclusive credentials, historical-token invalidation and recovery evidence required. |
| 6–7, 17, 21–22 | Eight provider read plans and typed projections over supplied bytes. Scoped catalog and local product, artwork and order preparation remain available. Standalone approved-intent submission, trusted receipts, event intake and authoritative reconciliation remain incomplete. | Exercise eight routed reads, scope denial, bounded pagination, malformed evidence and projection of only the selected shop. | No provider-confirmed response, hosted durability or quota evidence. |
| 13–14, 24 | Release held. Read review exposes an explicitly blocked native reconciliation result. | Fixtures do not supply approvals, dispatch authority or successful resolution of UNKNOWN. | Independent authority and explicit operational approval required. |

The typed provider read router reviews unattributed bytes. Signed catalog
snapshots retain their separate fixture evidence contract. Neither grants native
operation idempotency. The existing Core/native ownership boundary still owns
approval and product revision currentness, durable operation transitions, and
the definitive reconciliation decision.

## Standalone 0.7.0 native held inspection

The standalone adapter accepts the frozen Core build
`d64d32159bea4832b962acb6b4c08fa6fbeae910` with its exact native build stamp
and canonical v1 contract digest. It uses Core's protected held inspection
and compares its event digest to the current Core journal read and exact
binding, including the operation, approval reference, product version and
payload digest. The existing Core panel/session and reviewed connection
checks supply currentness and the configured shop/account join. The adapter
returns only a held observation or UNKNOWN with retry denied. This does not
certify the approval or product revision as current for operational execution.

Core #1264 `c02b248c14eb71a571e5dae881fe0a10823b5560` was inspected as
source reference. Its mutation exchange still denies access pending the native
transactional journal and independent checkpoint join, and the Companion
still pins the frozen Core. REGISTER, CLAIM, trusted operational receipts,
reconciliation and recovery remain blocked. No alternate journal or writer
is introduced.

## Standalone 0.8.0 synthetic application

The [official Printify OpenAPI document](https://developers.printify.com/openapi.json)
parsed as OpenAPI 3.0.3 with 47 paths. The typed route subset uses the existing
qualified PHP request and response contracts where available. Route alignment
and fixture behavior do not establish broad schema conformance or live access.

| Architecture sections | Delivered capability | Scope and remaining work |
| --- | --- | --- |
| 1–3, 6–7, 16–17 | Standalone typed client and in-memory transport for shop discovery, catalog and variants, artwork upload/read/list, product create/read/list/update, shipping quote, order create/read/list/cancel, separate simulated production, and isolated webhook fixture management. | Fixed fixture catalog and subset of documented routes. Production client and full provider feature parity remain open. |
| 5, 8–9, 18–22 | Fixture state, bounded plans/pages, validation errors, synthetic rate limits, duplicate and stale-write denial, lost-acknowledgment fault, and order tracking readback. | State survives within a fixture run. Neither fixture identity nor a synthetic readback creates a native journal receipt or resolves a real UNKNOWN. |
| 10–12, 15, 23 | Source-stamped deterministic ZIP and opt-in WordPress Tools journey, with scoped capability checks, nonce admission and sanitized results. | Fixed scenario. General product/order editing and durable audit views remain open. |
| 4, 13–14, 20, 24 | Existing protected native held inspection and standalone execution holds. | Operational registration, claim, independent checkpoint, credential custody, historical-token invalidation and hosted recovery remain blocked. |

## Standalone 0.9.0 disposable operator workspace

| Capability | Source and scope |
| --- | --- |
| Operator operations | Local, scoped, nonce-protected workspace with individual typed JSON operations. Covers 31 provider-shaped fixture operations, plus explicit synthetic production and fulfillment controls and event ingestion. |
| Persistence | Fixture-only InnoDB table. A revision-and-seal CAS commits bounded transport state, event deduplication and sanitized audit together. Pending doubts require readback and consumed operation IDs deny replay. |
| Provider contracts | The route subset adds documented product GPSR read and line-item cancellation with separate 204 acknowledgment and readback. An isolated order:created event follows its documented envelope and checks exact shop and order identity. No webhook delivery over HTTP. |
| Qualification limits | The selector presents the configured fixture shops the current operator can review. General graphical editors, additional documented routes and event topics, and hosted or provider qualification remain open. Core authority, credential custody and independent recovery remain external gates. |

## Standalone 0.10.0 operator and endpoint acceptance

The 34 typed operation selectors below were checked against the published OpenAPI 3.0.3 method and path entries. Path verification covers the integrated fixture routes. It does not establish full schema parity or provider behavior. `VARIANT_READ` selects an exact variant from the documented variants response. The shipping method selector also maps to the documented priority, express and economy paths. [Printify OpenAPI](https://developers.printify.com/openapi.json)

| Fixture operation | Documented method and path |
| --- | --- |
| `SHOPS_READ` | `GET /v1/shops.json` |
| `BLUEPRINTS_READ` | `GET /v1/catalog/blueprints.json` |
| `BLUEPRINT_READ` | `GET /v1/catalog/blueprints/{blueprint_id}.json` |
| `BLUEPRINT_PROVIDERS_READ` | `GET /v1/catalog/blueprints/{blueprint_id}/print_providers.json` |
| `BLUEPRINT_PROVIDER_READ` | `GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}.json` |
| `VARIANTS_READ` | `GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}/variants.json` |
| `VARIANT_READ` | `GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}/variants.json` |
| `SIZE_GUIDE_READ` | `GET /v1/catalog/blueprints/{blueprint_id}/size_guide.json` |
| `CATALOG_SHIPPING_READ` | `GET /v2/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}/shipping.json` |
| `CATALOG_SHIPPING_METHOD_READ` | `GET /v2/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}/shipping/standard.json` |
| `PROVIDERS_READ` | `GET /v1/catalog/print_providers.json` |
| `PROVIDER_READ` | `GET /v1/catalog/print_providers/{print_provider_id}.json` |
| `IMAGE_UPLOAD` | `POST /v1/uploads/images.json` |
| `IMAGE_READ` | `GET /v1/uploads/{image_id}.json` |
| `UPLOADS_READ` | `GET /v1/uploads.json` |
| `IMAGE_ARCHIVE` | `POST /v1/uploads/{image_id}/archive.json` |
| `PRODUCT_CREATE` | `POST /v1/shops/{shop_id}/products.json` |
| `PRODUCTS_READ` | `GET /v1/shops/{shop_id}/products.json` |
| `PRODUCT_READ` | `GET /v1/shops/{shop_id}/products/{product_id}.json` |
| `PRODUCT_UPDATE` | `PUT /v1/shops/{shop_id}/products/{product_id}.json` |
| `PRODUCT_DELETE` | `DELETE /v1/shops/{shop_id}/products/{product_id}.json` |
| `PRODUCT_PUBLISH` | `POST /v1/shops/{shop_id}/products/{product_id}/publish.json` |
| `PRODUCT_GPSR_READ` | `GET /v1/shops/{shop_id}/products/{product_id}/gpsr.json` |
| `ORDER_SHIPPING_QUOTE` | `POST /v1/shops/{shop_id}/orders/shipping.json` |
| `ORDER_CREATE` | `POST /v1/shops/{shop_id}/orders.json` |
| `ORDERS_READ` | `GET /v1/shops/{shop_id}/orders.json` |
| `ORDER_READ` | `GET /v1/shops/{shop_id}/orders/{order_id}.json` |
| `ORDER_CANCEL` | `POST /v1/shops/{shop_id}/orders/{order_id}/cancel.json` |
| `LINE_ITEM_CANCEL` | `POST /v1/shops/{shop_id}/orders/{order_id}/line_items/{line_item_id}/cancel.json` |
| `ORDER_SEND_TO_PRODUCTION` | `POST /v1/shops/{shop_id}/orders/{order_id}/send_to_production.json` |
| `WEBHOOKS_READ` | `GET /v1/shops/{shop_id}/webhooks.json` |
| `WEBHOOK_CREATE` | `POST /v1/shops/{shop_id}/webhooks.json` |
| `WEBHOOK_UPDATE` | `PUT /v1/shops/{shop_id}/webhooks/{webhook_id}.json` |
| `WEBHOOK_DELETE` | `DELETE /v1/shops/{shop_id}/webhooks/{webhook_id}.json` |

The operator workspace provides scoped field forms alongside the advanced JSON form. The fixture store retains products, orders, events and actor-attributed operation history across restart. Archive and delete retain tombstones. Publish records a pending synthetic status. Four documented event shapes are checked by an in-process HTTP-shaped fixture adapter. An accepted event records an observation against the exact fixture resource; it does not advance the lifecycle or confirm a provider outcome. Exact replay and conflicting reuse of an event identity are denied. [Printify events](https://developers.printify.com/#events)

Remaining software scope includes complex multi-line and multi-placement form builders, remaining OpenAPI paths, additional event topics and webhook lifecycle behaviors. Operational authority, credential custody, independent recovery, and provider acceptance remain separately blocked.

## Standalone 0.11.0 consolidated fixture coverage

The v0.10.0 matrix and the verified additions below enumerate 48 selectors, covering 50 of 53 distinct method/path combinations from the OpenAPI 3.0.3 document. Variant selection shares its documented list route, and the V2 shipping selector covers four documented methods of shipping. [Printify OpenAPI](https://developers.printify.com/openapi.json)

| Added fixture operation | Documented method and path |
| --- | --- |
| `CATALOG_SHIPPING_V1_READ` | `GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}/shipping.json` |
| `PRODUCT_PUBLISH_SUCCEEDED` | `POST /v1/shops/{shop_id}/products/{product_id}/publishing_succeeded.json` |
| `PRODUCT_PUBLISH_FAILED` | `POST /v1/shops/{shop_id}/products/{product_id}/publishing_failed.json` |
| `PRODUCT_UNPUBLISH_NOTIFY` | `POST /v1/shops/{shop_id}/products/{product_id}/unpublish.json` |
| `SUPPORT_LIST` | `GET /v1/shops/{shop_id}/orders/{order_id}/support-requests` |
| `SUPPORT_DETAIL` | `GET /v1/shops/{shop_id}/orders/{order_id}/support-requests/{supportRequestId}` |
| `SUPPORT_REFUND` | `POST /v1/shops/{shop_id}/orders/{order_id}/support-requests/refund` |
| `SUPPORT_REPRINT` | `POST /v1/shops/{shop_id}/orders/{order_id}/support-requests/reprint` |
| `SUPPORT_ADDRESS_CHANGE` | `POST /v1/shops/{shop_id}/orders/{order_id}/support-requests/address-change` |
| `PERSONALIZATION_OPTIONS` | `GET /v1/shops/{shop_id}/products/{product_id}/personalization_options.json` |
| `PERSONALIZATION_CONFIGURE` | `POST /v1/shops/{shop_id}/products/{product_id}/personalization.json` |
| `PREVIEW_CREATE` | `POST /v1/shops/{shop_id}/products/{product_id}/personalization_previews.json` |
| `PREVIEW_READ` | `GET /v1/shops/{shop_id}/products/{product_id}/personalization_previews/tasks/{task_id}.json` |
| `ORDER_ADDRESS_CHANGE` | `POST /v1/shops/{shop_id}/orders/{order_id}/address_change.json` |

Publication callbacks are revision-bound fixture transitions. Support requests stay pending and unresolved. Support-request fulfillment is outside the fixture. The direct on-hold address-change route retains only a bounded address digest. Rich operator forms support bounded extra variants, separate placements and order lines. Personalization supports a fixture textbox and durable pending preview tasks. Advanced JSON is available for compound requests. The fixed catalog exposes synthetic front/back dimensions for variants 9 and 10.

| Webhook topic coverage | Fixture behavior |
| --- | --- |
| `order:created`, `order:updated`, `product:created`, `product:updated` | Exact scoped resource correlation. Order status must match retained state. |
| `order:sent-to-production`, `product:publish:started` | Correlate to explicit prior fixture production or pending publication. The publication event supports the documented create shape. |
| `order:shipment:created`, `order:shipment:delivered` | Correlate tracking digest and documented shipment-created line identities to the exact order. Delivered events update the existing fixture shipment timestamp. Replays and conflicting updates fail closed. |

The isolated fixture receiver uses the documented envelope and signature format with public fixture signing material. Its delivery path is in-process and remains separate from provider authenticity and external execution. Other event shapes remain unsupported. [Printify events](https://developers.printify.com/#events)

### Remaining OpenAPI method/path coverage

- `DELETE /v1/shops/{shop_id}/connection.json`
- `POST /v1/shops/{shop_id}/orders/express.json`
- `POST /v1/shops/{shop_id}/webhooks/{webhook_id}/simulate`

These are source gaps in the standalone typed registry. Additional event topics, fixture profile breadth and richer personalization workflows remain incomplete. Hosted verification, independent authority and recovery, and Printify credentials and live acceptance are separate operational gates.

## Standalone 0.12.0 source and offline acceptance

Combined with the two preceding matrices, the additions below cover 51 selectors and all 53 documented OpenAPI method/path combinations. This counts distinct routes. Fixture behavior and full provider schema parity are separate. [Printify OpenAPI](https://developers.printify.com/openapi.json)

| Added selector | Documented method and path | Fixture boundary |
| --- | --- | --- |
| `SHOP_DISCONNECT` | `DELETE /v1/shops/{shop_id}/connection.json` | Shop-specific synthetic disconnection persists after restart. |
| `ORDER_EXPRESS_CREATE` | `POST /v1/shops/{shop_id}/orders/express.json` | Requires a fixture external ID. Accepts multiple existing products and validates exact response line membership. The fixture creates a single Express group. |
| `WEBHOOK_SIMULATE` | `POST /v1/shops/{shop_id}/webhooks/{webhook_id}/simulate` | Synthesizes a scoped event for the most recent eligible resource for created/updated subscriptions. Duplicate delivery is denied. |

| Architecture sections | Offline status and limitation |
| --- | --- |
| 1–3, 6–7, 16–17 | All documented routes are represented. The fixture catalog and shipping profile stay bounded. Exact provider behavior and broader Express profiles, mixed fulfillment grouping and shipment variants are unqualified. |
| 5, 8–9, 18–22 | Durable local CAS, operation deduplication, signed fixture events and privacy-minimized audit. Cross-shop, stale revision, replay, lost acknowledgement and crash/restart tests exercise the extracted workspace. Fixture state supplies no native authority and does not resolve a production UNKNOWN. |
| 10–12, 15, 23 | Reproducible standalone package with scoped WordPress forms. The integrated journey covers two products, multiple variants and placements, and Express checkout. Textbox and uploaded-image references are accepted for fixture personalization, while operator inspection exposes pending, failed and corrupt preview statuses. Rendering and verified completed preview artifacts remain open. |
| 4, 13–14, 20, 24 | Held native inspection remains available. Core mutation admission, independently qualified checkpoint and revocation, exclusive credential custody, old-token invalidation, hosted recovery and live provider acceptance remain external gates. |

The receiver recognizes the 13 documented topics. It binds disconnect and deletion to fixture tombstones, line cancellation and support updates to scoped readback, and failed or corrupt preview events to a pending task. Completed preview events, support status changes without matching authoritative fixture readback, and publication-start delete envelopes remain unsupported. The standalone HTTP fixture sends bounded HTTP bytes over a private socket pair with no listener. The WordPress workspace exercises the same decoder and persists replay digests with audit state. [Printify events](https://developers.printify.com/#events)

## Standalone 0.13.0 offline behavior

The full endpoint matrix above still applies. There are 51 typed selectors for 53 public method/path pairs. This increment changes fixture behavior behind existing routes and adds an explicitly local preview completion control.

| Operations or event topics | Accepted standalone fixture behavior | Remaining limits |
| --- | --- | --- |
| Personalization options and configure, preview create and read | Bounded textbox and exact same-shop artwork URL. Pending, completed, failed and corrupt observations. The local SVG embeds selected PNG artwork and escaped text. Completion tests bind the task to the current product revision. | The SVG is a local illustration, with a 16 KiB artwork input ceiling. It does not model provider mockup geometry, generate provider PNG or JPEG results, or establish buyer verification. Older unrenderable tasks cannot be completed online or inferred successful. |
| Express create, order read/list, cancellation, fixture production and fulfillment | A single request can produce separate Express and ordinary orders. Product, variant, quantity, line identity, cost and shipping amounts are bound. Each group has independent order and line transitions. | Variant eligibility and US costs are deterministic fixture values. Actual rates and production scheduling require provider evidence. |
| Shop disconnect and product create/update/delete events | Scope and exact resource checks. Disconnection and deletion observations require the corresponding local held tombstone. |
| Order create/update/production/line-canceled/shipment-created/shipment-delivered | Per-topic order status, line identity or shipment tracking correlation. Distinct event IDs are recorded together with workspace audit state. |
| Personalization preview task processed | Terminal status checked against the exact task. A completed event must match the task's known synthetic variant references and a locally rendered artifact. |
| Product publish started | Recognizes create/update and the documented delete-only data shape. The latter is correlated to the exact product in the operator's authenticated fixture shop and records observation without deleting it. |
| Support request updated | First observation can introduce a known support type for an existing order. Subsequent observations validate timestamp, matching type, monotone status and terminal outcome. No support request implies a confirmed refund or provider mutation. | Unknown support types and terminal outcome rewrites are denied. |
| Webhook simulate | All 13 topic branches select an eligible persisted synthetic resource and use the same signed, bounded HTTP decoder and durable audit path. Stable generated identities deny replay. | Empty or ineligible fixtures deny simulation. The application does not publish a receiver or send network traffic. |

The v0.13 fixture changes sit within the existing Sections 1–24 evidence mapping above. Operational native admission, independent recovery and revocation, credential custody and invalidation, hosted durability, and provider compatibility remain separate qualification gates.
