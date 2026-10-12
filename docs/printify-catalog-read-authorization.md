# Expiring Printify catalog read authorization

Base: current main `844107ab9baf7e1303cd47708e9eab3bc700b348`. This independent main-based branch does not modify finance #1147 or PRs #1161–#1170. It adds no live calls, deployment, credential configuration, shop activation or safety transition.

## Safety boundary and actual readiness

The literal capability is `printify.catalog.read`; it is not a generic feature switch and never invokes `Settings::activatePrintify`, changes settings, arms automation or releases STOP ALL. Main's existing catalog sync and general scoped credential retriever remain unchanged. They require general Printify authorization; this new path has its own owner grant but still honors `Settings::safety_locked()`.

**Under the required STOP ALL ON / external safety lock ON / automation OFF posture, catalog retrieval returns `catalog_read_safety_locked` before credential decryption or HTTP.** Recording a local grant does not override this. The lock is derived by existing `Settings::safety_locked()` from `activation_authorized`, `automation_armed` and `stop_all`; this PR introduces no exception. Do not turn these switches on/off to get a catalog response. A separately reviewed owner approval and software policy change explicitly permitting ONLY catalog GET under the preserved locks would be required for that alternative. Alternatively, supply authentic offline supplier exports for validation without provider access.

No real DG2-001 blueprint, provider, variant, panel geometry, mockup/display license or supplier certification is established here. PP-001 is a source candidate ID, not a Printify SKU. Test IDs 101/2/202 are synthetic and cannot be used as supplier evidence. Later personalization/mockup PRs remain separate dependencies for a real product preview; this branch does not duplicate or activate them.

## Owner grant and installation binding

Before a grant can be recorded, an authorized later installation must define `DIGIFORGE_MAIN_SITE_URL` to the exact canonical WordPress `site_url()` of the **main website**, independently of mutable integration metadata. Missing or mismatched pins deny grants and evidence access. Existing grants also bind blog ID/origin. Retrieval additionally requires WordPress's `production` environment; staging/development/local origins cannot retrieve. This task neither defines the production constant nor changes any website.

An owner is an authenticated WordPress account with `manage_options`, `manage_digiforge_connections` and `manage_digiforge_pod`. The recipient requires both DigiForge capabilities; every read is restricted to that exact account. Owner permissions, expiry, revocation, current ACTIVE business/store/program relationships and integration/credential-metadata fingerprint are rechecked. A credential must already exist in the vault; no token may be submitted through these endpoints.

The existing CONFIGURED, explicitly enabled Printify integration must have `config.catalog_read_scope` exactly equal to the grant's `business_id`, `store_id`, `product_program`. This is an explicit local owner-reviewed integration/shop binding, not inferred from supplier IDs. Editing the binding, integration configuration/status, or secret fingerprint invalidates prior grants. SQL/storage uncertainty fails closed.

Exact grant JSON keys:

| Field | Contract |
| --- | --- |
| `capability` | Literal `printify.catalog.read` |
| `integration_id`, `account_id` | Positive integers identifying existing integration/account |
| `business_id`, `store_id` | Exact configured lowercase keys or ID strings |
| `product_program` | `PERSONALIZED_POD` or `ORIGINAL_DESIGN_POD`; existing business restrictions apply |
| `expires_at` | Absolute ISO timestamp with timezone, strictly future and at most one hour |
| `max_requests` | Integer 1–10; failures after reservation consume an attempt |
| `requests` | List of 1–10 explicit scopes below, with no URLs, wildcards or unknown keys |

Request objects:

- `blueprint`: `kind`, `blueprint_id` → GET `/v1/catalog/blueprints/{id}.json`.
- `providers`: `kind`, `blueprint_id` → GET `/v1/catalog/blueprints/{id}/print_providers.json`.
- `variants`: `kind`, `blueprint_id`, `provider_id` → GET that provider's variants.
- `variant`: `kind`, `blueprint_id`, `provider_id`, `variant_id` → GET the same variants endpoint, but return only the exact unique requested variant. Missing/duplicate identity fails closed.

All supplier IDs must be positive bounded integers. Whole-catalog discovery, shipping, products, shops, uploads, orders and mutations are excluded. Only grant-approved request indexes can be selected; callers cannot supply a transport URL/method/body or override IDs.

## REST and durable evidence

Routes are under `digiforge/v1/printify/catalog-read`. All require authenticated account capabilities and an explicit valid `X-WP-Nonce` (`wp_rest`); batching is not enabled.

- `POST /grants`: exact JSON above, bounded to 8 KiB; owner-only; `Idempotency-Key` of 8–100 ASCII letters/digits/underscore/hyphen. Replaying an approval is rejected.
- `POST /{grant_sha}/revoke`: owner-only, no body/query; irreversible immutable local revocation.
- `GET /{grant_sha}?request_index=N`: account-bound read, required bounded `Idempotency-Key`; no request body or extra query fields. Route is GET-only.
- `GET /{grant_sha}/evidence/{request_sha}`: account-bound historical result read under a still-current grant; no network. `request_sha` is SHA-256 of the original request key. Expired/revoked/drifted grants cannot expose results through this endpoint; immutable records/audit history remain retained.

Grant-scoped named locks serialize read/revoke and request accounting. InnoDB transactions atomically write sealed reservations plus audit anchors before decryption. Quota comes from at most ten immutable request receipts cross-checked against matching audits, including the grant/request hashes; there is no resettable quota counter. Missing, altered, moved or deleted receipt evidence, conflicting replay, unavailable audit/storage, failed commit or lock release fails closed. A failed/uncertain request is not automatically retried; a new explicit request key is needed within the remaining approved budget.

Existing `PrintifyCatalogClient` supplies the actual GET transport. Its new scoped method pins HTTPS `api.printify.com`, TLS verification, zero redirects, ten-second timeout, one attempt and a 1 MiB body limit (plus one byte to detect truncation). No Retry-After sleep/retry is used on the scoped path. JSON depth is bounded; raw and decoded protected credential content/keys are rejected. Tokens are decrypted only inside the guarded operation, never returned/logged/persisted with evidence; upstream exceptions/errors are sanitized. Existing legacy retry behavior is unchanged.

Results retain exact raw response-body SHA-256, endpoint, absolute observation timestamp, original response bytes in protected local options, selected data, grant/request hashes, account/site/integration and configured shop scope. Sealed/audited receipts return `DRAFT_UNVERIFIED`, `supplier_certified=false`, `mutation_authorized=false`, with private/no-store responses. Public evidence excludes raw response bytes. These receipts are separate from shared `pod_catalog`, approved templates, supplier mappings, orders and activation state. They do not certify geometry/rights, promote a source concept or manufacture provider authority. Stored raw bytes remain local privileged evidence; normal protected WordPress database/backup controls apply.

## DG2-001 smallest owner next step

1. Supply the exact sock blueprint ID from an authentic existing Printify export/dashboard, plus the intended store/account/integration IDs. PP-001 and synthetic fixture IDs cannot substitute.
2. Supply the actual canonical main-site URL for the independent installation pin, and approve the integration's exact business/store/program catalog-read binding. This task does not change that configuration.
3. Resolve the lock policy explicitly: preserve the locks and supply authentic offline blueprint/provider/variant exports, **or** commission and approve a narrowly defined catalog-read exception as a separate reviewed change. Current runtime retrieval remains denied; general Printify activation is not the remedy.
4. Once independently authorized, the owner records a one-hour-or-shorter grant for blueprint/providers retrieval; after choosing the exact provider/variant, record a new explicit variant grant. Supplier geometry, safe zones, bleed, licensed mockup/UV maps and independent artwork review remain additional preview blockers.

## Behavioral verification

`PrintifyCatalogReadContractTest` covers strict operation/field/ID/expiry/budget boundaries and exact variant filtering. `PrintifyCatalogReadTest` uses encrypted dummy credentials, real isolated WordPress/database persistence and synthetic HTTP only: lock preservation/no transport, draft evidence, replay, quota, cross-account/shop drift, revocation, grant/receipt tampering and deletion, escaped credentials, protected keys, oversize/malformed/redirect/rate responses, expiry, independent main-site pin, concurrent named-lock reservation, audit/commit failure, nonce/capabilities and registered GET-only routes. Synthetic tests establish software behavior, not actual Printify access or sock compatibility.

Mandatory Engineering & Safety Audit is unchanged except for adding a real installed Composer dependency-advisory audit after installation (main has no tracked Composer lockfile). Final exact-head CI evidence belongs to the published PR; no self-referential or fabricated PASS is recorded here.

Consolidated local candidate validation: 1,155 unit tests / 7,353 assertions; 207 WordPress tests / 1,972 assertions (eight new scoped-read tests / 132 assertions); 61 offline tests; configured static/standards plus targeted WordPress-aware static analysis; syntax, controlled HTTP/secret/legacy scans and diff checks all passed. Installed Composer audit reported no security vulnerability advisories. Existing main/test-runtime PHP deprecation and dbDelta diagnostics were visible; no checks were disabled. Independent code review's credential, origin and quota findings were corrected and behaviorally tested. No provider request or website configuration was executed.
