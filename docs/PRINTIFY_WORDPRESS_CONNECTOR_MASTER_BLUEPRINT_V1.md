# DigiForge Printify WordPress Connector — Master Blueprint v1.0
11 November 2026 | Design specification; no deployment or live-write authorization.


## Executive architecture
Build a native WordPress/PHP Printify connector as a DigiForge-compatible module or companion plugin, not a second independent product controller. Existing DigiForge remains the system of record for internal shops, products, personalized artwork, proof approval, audit, emergency STOP and owner authorization. The connector owns only provider-specific API transport, normalization and synchronization. Cloudflare is not required for initial GET-only integration.


## Authority and current baseline
Ultimate Master Blueprint v6.0; First Launch Master Plan v1.1; Personalized POD Four-Day MVP; existing DigiForge Printify modules; frozen PR #1260 at f0ab0f74e2263e2bd7f7ce9c8003907524ce8f24. Cloudflare registration PR #1261 remains unmerged. Do not merge either as a shortcut. Existing main contains PrintifyCatalogClient, PrintifyCatalogSyncService, PrintifyMutationRequest and scoped authorization; audit and reuse these before creating new classes.


## Simultaneous plugin compatibility
YES, a companion plugin can run alongside DigiForge if it has a separate WordPress plugin namespace/prefix, no duplicate PHP class names, no duplicate cron hooks, explicit versioned interface contract and one authoritative owner of credentials, shop mappings, product state and external-operation permissions. The connector must fail closed if DigiForge is missing, incompatible, STOP ALL, locked or disabled. Avoid installing a second independent controller that can bypass DigiForge safety. Prefer a thin companion adapter that calls DigiForge-defined interfaces and can be independently disabled without affecting core DigiForge functions.


## Official Printify capability families
Authentication with scoped personal access token or OAuth as appropriate; shops, catalog blueprints, print providers, variants, shipping, products, uploads, publishing, orders, fulfillment, webhooks. Use documented V1/V2 endpoints per resource. Pin schema and version; verify current official API reference at implementation time. Printify native Etsy integration is separate from DigiForge portal and should be reconciled, not duplicated blindly.


## Stage 0 — Inventory and isolation | 0.5–1.5 days
Inventory exact current modules, contracts, DB schema, WP plugin hooks, credentials and acceptance tests. Select companion plugin vs internal module based on actual source-level conflicts. Define interface: ConnectorInterface, ShopBinding, CredentialReference, ProviderResult, ExternalOperationIntent. New isolated draft PR; no production installation.


## Stage 1 — GET-only offline connector | 1–3 days
Implement scoped WordPress HTTP API client, HTTPS exact-host allowlist, bounded timeouts/body sizes, JSON validation, retry only safe GET on explicit policy, rate budget and 429 Retry-After, credential-reference injection (synthetic in tests), catalog/shop/product/order read-models and sanitized receipts. No mutating methods exposed. Test 401/403/404/429/5xx, wrong shop, malformed response, redirects, SSRF, secret leakage and STOP denial.


## Stage 2 — GET-only hosted acceptance | 0.5–2 days plus approval
Separately authorize a read-scoped Printify credential and reviewed Hostinger plugin installation. Verify account and intended shop 28930593 (Shop 2 personalized_pod) with live GETs only. Pop-Up shop 28925875 must never be substituted. Validate actual blueprint/provider/variant/shipping and current product mapping. No writes, no order submission, no Etsy publication. Preserve redacted evidence.


## Stage 3 — Artwork and uploads | 2–4 days
Artwork proof, DPI, bleed, safe area, print geometry, image rights, immutable asset hash, exact provider placement and native personalization constraints. FL-03 Two Stems, One Date mug and FL-04 Our Shared Chapter poster first. Provider upload is an external write; separate approval and one-shot journaling required.


## Stage 4 — Product draft mutations | 3–6 days
Create/update staged Printify product drafts, variant mapping, provider identifiers, print-area layout, mockup reconciliation and durable intent records. Bind exact internal shop, provider shop, product version, approval hash and credential. No blind retry on uncertain POST/PUT/DELETE; UNKNOWN remains fenced. Explicit human approval for every mutation.


## Stage 5 — Etsy publishing | 2–5 days
Reconcile Printify channel publication support and actual Etsy listing IDs. Validate listing title/tags, personalization instructions, mockups, processing time, shipping, taxes/fees and margin. Start with approved listings only. Printify-native Etsy handoff is fallback; no bulk publishing or unattended re-publish.


## Stage 6 — Orders and webhooks | 3–6 days
Read/import orders; verify inbound webhook signature per Printify documentation, event identity, shop, replay, out-of-order delivery and payload limits. Persist dedupe, lineage to Etsy order and personalization proof, address validation, status and audit. Order writes remain disabled.


## Stage 7 — Controlled fulfillment | 4–10+ days
Order submission, explicit send-to-production, cancellation/exception reconciliation, shipment/tracking, refunds, financial controls. Requires independent recovery authority and exclusive credential custody before unattended writes. Manual-only controls are not a substitute for permanent revocation guarantees in fully automated mode.


## Stage 8 — Scale and operations
Multi-shop expansion, provider abstraction, reconciliation dashboards, rate budgets, webhook observability, finance, audit retention, disaster recovery, verified backups, deployment rollback and controlled automation. Shop 3 deferred until Shop 2 pilot accepted.


## Data model
Connections: internal_shop_id, provider_shop_id, integration_id, credential_ref, scopes, state, revision. Catalog snapshots: blueprint, provider, variant, geometry, shipping, observed_at. Product bindings: internal version, Printify product ID, Etsy listing ID, last reconciled state. Assets: hash, rights, dimensions, upload ID. Operations: intent ID/hash, approval, lease/fence, SENT/UNKNOWN/terminal receipt. Webhooks: event ID, signature result, dedupe. Orders: source order, proof, approval, status. Costs: COGS, shipping, currency, margin.


## Security invariants
STOP ALL and external safety lock supersede all connector settings. Automation OFF by default. No secrets in GitHub, WordPress options, logs or browser. Vault-backed server-side custody; shop scope verified on every call. Exact provider host allowlist; HTTPS and SSRF controls; WordPress capability/nonce checks; redacted logging. Never retry unknown writes. Historical reservation #22 remains PENDING/UNKNOWN and cannot be replayed. Coordinated rollback of all trusted roots and old credential copies remain unresolved blockers to unattended external writes.


## Acceptance gates
A source compatibility: no duplicate classes/hooks, exact SHA, full tests. B offline GET-only: no write paths, fault fixtures and credential isolation. C live read: shop/account/provider proof. D upload: asset rights/geometry and idempotent reconciliation. E draft write: explicit owner permit, durable UNKNOWN. F publish: exact Etsy listing and pricing confirmation. G orders: signed webhook and dedupe. H recovery: independent revocation and exclusive credential custody. I production: staged install/rollback and separate owner approval.


## Parallel DigiForge lane
Connector Lane A: Stage 0–1 in an isolated PR. DigiForge Lane B: FL-03 and FL-04 artwork, personalization fields, proof, mockup, pricing and listing package in separate PR. Work Lane C: provider/current shipping/rights research. Do not edit overlapping modules concurrently. If Stage 2 live read cannot be safely authorized in a short time box, continue DigiForge commercial development and preserve connector candidate.


## Planning and limits
Stages 0–2 estimate 2–7 working days, conditional on reuse and approval; later writes/fulfillment materially longer. No new Cloudflare, VPS or paid service prerequisite for read-only connector; existing Hostinger capacity must be checked. No live credentials, installation, publishing, provider requests or merges authorized by this document.


## Primary references
https://developers.printify.com/ ; https://github.com/subvicco12/digiforge ; https://github.com/subvicco12/digiforge/pull/1260 ; canonical DigiForge Drive folder https://drive.google.com/drive/folders/1bbbVgrB8G4ekbk5itrH2asTu_yLnbbPg

## Development governance (normative)

- This file is the connector's versioned design baseline. Before every connector PR, compare the planned diff with the relevant stage, security invariants, and acceptance gate; document deviations and seek owner approval before changing this blueprint.
- Stage completion requires an exact commit SHA, test/acceptance evidence, remaining blockers, and a separate owner decision for any external operation. Passing source tests does not grant live authorization.
- DigiForge remains the sole authority for shop scope, credential references, operation approval, STOP ALL, and emergency controls. The companion must fail closed when its interface is absent or incompatible.
- Keep connector work in isolated draft PRs. Do not merge, install, deploy, publish, submit orders, enable automation, or use credentials solely because this document is committed.
- Full automation is blocked until independent recovery/revocation and exclusive credential custody are demonstrably qualified.
