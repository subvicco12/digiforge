# Stage 0/1 connector implementation and acceptance

Governing blueprint: PR1262 at761fb79abb2813455b7a90a56aa1fac8e6994f22. Starting connector PR1263 head4c7aac5c0eab21d10eb9e38997c6723667856eb4. No merge or production installation is authorized. Final exact head and CI results are recorded in the PR handoff to avoid self-referential source hashes.

## Architecture and compatibility decision

Use a thin companion plus an additive DigiForge-owned v1 module. The companion (`DigiForgePrintifyConnector\\Plugin`, version0.1.0) exposes only `connector()` and safe `status()`. It neither owns nor retrieves credentials, stores mappings, schedules work, registers REST routes, changes permissions nor dispatches HTTP. Its sole plugins_loaded hook is idempotent, priority20. Missing/incompatible DigiForge API returns unavailable. The primary DigiForge bootstrap, portal, scheduler, migrations and existing provider mutation code are untouched.

The core autoloader already maps `DigiForge\\` to includes/. New `DigiForge\\Connector\\Printify` names do not duplicate existing classes. `Bridge::connect('1.0.0')` returns ConnectorInterface; unsupported versions deny. Existing PrintifyCatalogClient gains a separate fixed-contract, single-attempt entry point using its SAME existing transport. Legacy catalog methods and their retry behavior are unchanged. Existing vault/retriever, native capabilities/shop access, reviewed connection scope and Logger remain the authorities. No second token store, product store, owner model, dispatch authority or HTTP implementation is introduced. The existing catalog normalizer/persistence remains authoritative; this interface adds a minimized observational projection, without manufacturing missing costs, currencies or geometry certification.

Two independently installable controllers would conflict with the blueprint. An internal-only module is simpler but cannot be disabled independently; a standalone companion with its own transport/credentials is rejected. This thin companion is independently disableable while all ordinary DigiForge functionality remains intact. It requires the compatible core module/client addition; the companion ZIP alone would not upgrade the host.

## Stage 0 inventory

Current baseline is main-derived DigiForge1.0.103 source; it is NOT asserted identical to installed production1.0.103.17. Existing catalog client/sync/normalizer/persistence, scoped vault retriever, integration repository and WordPress CoreSettings were reviewed. Frozen API candidate f0ab0f74 was inspected for interface reuse, not merged or overwritten. Its native ShopAccess, ScopedProviderConnection and PrintifyConnectionBoundary contracts are consumed when present. They are absent from this main-derived baseline: native requests fail closed. No WordPress administrator fallback or new owner enrollment exists. A compatible production-lineage host port is a Stage2 gate; do not install this repository baseline over production.

Existing stores are integration registry, integration_secrets, reviewed provider-connection evidence where supported, wp_options and audit_log. No tables/schema/version markers/migrations are added. ReadBudget stores only bounded non-secret timestamps and hashed intent identities in a new wp_options namespace; original idempotency table and reservation22 are untouched. SQL requires standalone InnoDB transactions and a full single-column unique option_name index. Actual MariaDB fixture verified. Standard MySQL transaction-state compatibility is UNVERIFIED and must remain denied; no unsupported fallback is introduced.

## V1 interface

- ExternalOperationIntent(actorId,shopKey,integrationId,operation,input,intentId,sourceReferences=[]): exact canonical read operation; IDs and inputs bounded; 32hex request identity.
- ShopBinding: immutable canonical local shop/provider/integration/environment/revision/hash/expiry, non-secret CredentialReference, optional receipt-owned upload IDs. Fingerprint binds every field.
- HostInterface: authorize, reserve, consume, record. NativeHost is the production adapter; synthetic host fixtures are not authority grants.
- ReadConnector::read(intent): resolves fresh native authority, reserves consumed intent/budget, persists STARTED audit, consumes existing vault material only inside callback, checks current authority before/after HTTP and final receipt, projects minimized data, emits ProviderResult. No URL/header/body/credential inputs.
- ProviderResult: immutable data projection plus correlation receipt, read_only=true and mutation_authorized=false. Audit includes intent/operation/shop/integration, request/binding/response hashes, UTC observation timestamp and bounded status evidence. Persistence failure denies; uncertain outcomes are not automatically retried.

Native pilot admission is ONLY integration3, personalized_pod/localShop2/provider28930593 in production. Pop-Up28925875 and Shop3 cannot substitute. Current actor, native capabilities and ShopAccess, enabled CONFIGURED integration, current reviewed provider tuple, connection material association, expiry and existing safety gates all remain mandatory. Uploads additionally require existing owned-upload receipts, resolved through DigiForge's existing PrintifyUploadOwnership; unknown assets are never authorized by account listing alone.

## Implemented GET ledger (all OFFLINE_VERIFIED)

| Operation | Official endpoint | Scope/projection |
|---|---|---|
| SHOPS_READ | GET /v1/shops.json | Only the bound provider shop is returned |
| BLUEPRINTS_READ | GET /v1/catalog/blueprints.json | Bounded catalog metadata |
| BLUEPRINT_READ | GET /v1/catalog/blueprints/{blueprint_id}.json | Exact requested identity |
| PROVIDERS_READ | GET /v1/catalog/print_providers.json | Bounded provider list |
| PROVIDER_READ | GET /v1/catalog/print_providers/{provider_id}.json | Exact provider identity |
| VARIANTS_READ | GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{provider_id}/variants.json | Variant options and explicit placeholder dimensions; not production geometry certification |
| SHIPPING_READ | GET /v2/catalog/blueprints/{blueprint_id}/print_providers/{provider_id}/shipping[/{standard,priority,express,economy}].json | Official JSON:API methods/regions, explicit handling time and money; no invented values |
| PRODUCTS_READ | GET /v1/shops/{shop_id}/products.json | Bounded shop product page |
| PRODUCT_READ | GET /v1/shops/{shop_id}/products/{product_id}.json | Options/variants/placement metadata; no asset URLs |
| ORDERS_READ | GET /v1/shops/{shop_id}/orders.json | IDs/status/non-personal line-item mappings, no buyer PII |
| ORDER_READ | GET /v1/shops/{shop_id}/orders/{order_id}.json | Exact order and product/variant/provider/quantity mapping, PII/tracking redacted |
| UPLOADS_READ | GET /v1/uploads.json | Only receipt-owned asset references |
| UPLOAD_READ | GET /v1/uploads/{image_id}.json | Exact receipt-owned metadata; no download URL |

Current official OpenAPI3.0.3 source was fetched from https://developers.printify.com/openapi.json; GET excerpts and published shipping fixtures are committed. Initial long-page documentation extraction was truncated; the OpenAPI fetch resolved the endpoint/schema gap. Provider credentials/scopes are still unverified. V1 is used where documented, V2 for shipping. No unsupported capabilities/endpoints are invented.

## Limits and security

One attempt per read; no automatic retry/sleep. HTTPS api.printify.com only, fixed path grammar, GET only, redirects0, TLS verification,20second timeout,1MiB response bound, maximum3pages/10records per page. Catalog collections have explicit larger bounds and all responses remain under the body limit. Provider links are never followed. Integration-wide budget is3 per rolling minute/10 per rolling five minutes, shared across users/sessions; permanent intent markers prevent replay, with no automatic outcome reset. Malformed JSON, response/resource/shop mismatch, unsupported methods, caller headers/URLs and credential reflection deny. Decoded strings AND keys are checked, including escaped slash/Unicode representations. 401/403/404/429/5xx become bounded classifications; Retry-After is evidence only (0..60seconds), never an automatic retry. Error/receipt paths never return raw response, token, address, email or tracking details.

STOP ALL and external safety lock continue to supersede every read. Existing CoreSettings currently also requires automation/activation gates for Printify; no new diagnostic exception, activation switch, read grant or policy reinterpretation is created. Under mandated OFF/locked controls, live GET remains BLOCKED_POLICY. A future owner decision consistent with governing policy is required before Stage2; this implementation does not arm normal operations.

## Verification evidence

- Full unit suite:1264tests/7734assertions PASS, including29 connector tests/215assertions.
- Full WordPress suite:251tests/2362assertions PASS. Four connector tests exercise actual WordPress hooks, current-user scope, existing HTTP API through an isolated synthetic interceptor, privacy projection and real MariaDB/InnoDB budget persistence/replay/limits.
- Initial full WP run: one existing overlapping-worker readiness assertion failed because the child process did not inherit the custom isolated DB config. Mounting the SAME config at the standard library path corrected only the fixture; fresh empty DB full rerun passed without test/source suppression.
- A subsequent full regression exposed an existing permit-test wall-clock race: timestamp captured before DB seeding could predate the issued permit. The test now uses the actual issued_at for the valid case and adds explicit not-yet-valid denial assertions. Production consumer/expiry code is unchanged; no assertion was removed. Fresh full-suite rerun passed.
- Existing offline suite:61PASS. Configured PHPStan level8 (six-file baseline) and PHPCS PASS. New modules/companion lint PASS. Existing unit bootstrap undefined-$brief warning and inherited dynamic-property deprecations remain disclosed; expected negative SQL/audit diagnostics are synthetic.
- Separate AI code review reproduced an escaped-token leak. Regression test failed first, recursive decoded comparisons corrected it, reviewer confirmed the repro now rejects. No independent human review or hosted/provider certification is claimed.
- Real parallel MySQL workers are not certified by modeled concurrency tests. Real MariaDB budget transaction semantics were exercised; unsupported DB/transaction/storage states deny. Hosted recovery/durability and standard MySQL support remain unqualified.
- Exact-head hosted Engineering & Safety outcome is reported separately; runner DNS failure must not be called an application PASS. No CI changes or weakened checks are included in this PR.

## Parallel POD lane

PR1259 stays separate and clean at a597365c3ffbab022671553675a59a8783931e02. Focused FL03/FL04-related tests:51unit/284assertions,40offline and12WordPress/121assertions PASS in separate empty DB with HTTP/mail disabled (103 distinct tests). No overlapping source edits or new product artwork. FL03 native limits13/13/18 retained. Authentic FL04/R4 bindings/geometry/fonts/rights remain missing; synthetic limits are not production defaults. Proof/product packages stay held, publishing/submission false.

## Stage2 readiness and owner requirements

Offline source is ready for review, not production installation. Before live GET: qualify the additive interface/client against the installed production lineage (without downgrading to main), compatible native owner/shop/connection contracts, actual MariaDB/index prerequisites, independently reviewed integration3/account/credential/shop28930593 association and read scopes, owner-consistent read safety authorization, reviewed installation and separately authorized GET-only acceptance. Existing credentials must not be exported. No Cloudflare dependency is introduced for initial reads. Account ownership and live API responses are NOT VERIFIED.

The blueprint's0.5–2day controlled hosted acceptance estimate applies only after those approvals and host/policy gates are satisfied; no operational date is promised. Unattended writes remain blocked by independent recovery/credential custody requirements. No production installable release ZIP is claimed or built from this main-derived development baseline.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD active. No live Printify requests, credentials, provider writes, Etsy publication, production changes, Cloudflare deployment, merge or reservation22 retry.
