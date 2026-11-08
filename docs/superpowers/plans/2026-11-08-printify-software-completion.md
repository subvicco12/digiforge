# Printify API Completion Implementation Plan

> For agentic workers: execute the approved owner sprint inline, with independent product-read, webhook and quota units delegated to disjoint files. No additional design approval is needed under the owner's execution directive.

Goal: close the .19 API ledger's software gaps without live supplier actions.
Architecture: retain the vault and existing controlled HTTP transport; add immutable, exact-request lifecycle plans governed by current ownership, fresh owner authentication, independently reviewed policy, durable claims and receipts. Use existing InnoDB options/audit storage, no migration. Shared account quotas sit before every approved transport attempt. Webhook ingress records signed evidence, never runs business or external actions.
Tech stack: PHP8.3, WordPress, MariaDB/InnoDB, existing PHPUnit/Docker fixtures.
Spec: owner Full Software Completion Sprint; Ultimate Master Blueprint v6.0 and Operating Manual retrieved via authenticated Drive; official Printify reference captured November8.

## Global constraints
STOP ALL ON; external safety lock ON; automation OFF; activation unauthorized; RELEASE HOLD. No production changes, live supplier calls, owner changes, Etsy work, #22 retry or recovery qualification. Keep PR1260 and branch, preserve1258/1259.

## Review focus
- In-flight revocation: recheck exact shop, module, ownership and approval before each attempt and receipt acceptance.
- Unknown writes: durable fingerprint claim before sending, no automatic replay even after timeout.
- Webhook authenticity: HMAC raw bounded bytes before parsing, exact private binding, durable duplicate/conflict handling and audit.
- Pagination: explicit bounded page descriptions, no following provider URLs or fabricated product mappings.
- Quotas: account identity only, reserve every retry, no outer transaction or partial counter commit.

## Tasks
- [ ] Product reads: ApiRequest/ApiReadEvidence + ProductReadApiTest; test exact product/variant/area and page contracts before implementation.
- [ ] Quota/outcome: AccountQuota/ApiOutcome + pure/WP tests; test cross-shop account sharing, catalog/publish windows, cooldown, errors.
- [ ] Webhooks: WebhookRequest/Inbox/Controller + tests; transactional options/audit, disabled-by-default private config, rotation and dead-letter evidence.
- [ ] Lifecycle: LifecycleRequest/Evidence/Workflow + tests; shipping quote, cancellation and webhook writes through existing transport; product publish/delete/notify through package-bound product pipeline.
- [ ] Integrate quota and bounded read retry into catalog, product, order/reconciliation and generalized read paths without broadening grants.
- [ ] Register only exact webhook callback in existing route/security contracts; preserve all unrelated routes.
- [ ] Run full unit/offline/WP/static/security tests; independent review and exact-head hosted source audit.
- [ ] Compose .20 complete installed-lineage ZIP from pinned .19 baseline with explicit overlays, test extracted bytes, upload/read back canonical Drive.
- [ ] Publish operation-level ledger and stable1259 interface, classify live evidence separately.
