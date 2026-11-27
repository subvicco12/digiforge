# Printify companion connector — Stage 0 implementation baseline

**Governing blueprint:** [PR #1262](https://github.com/subvicco12/digiforge/pull/1262), `docs/PRINTIFY_WORDPRESS_CONNECTOR_MASTER_BLUEPRINT_V1.md`. This is an implementation workstream, not authorization to merge the blueprint or deploy.

## Verified reuse candidates on main (3758d1385f7d4a62947248822c1fe1c89ec9818e)

- `includes/POD/PrintifyCatalogClient.php` already implements allowlisted V1 blueprint/provider/variant GETs and V2 shipping GETs, with WordPress HTTP transport, HTTPS host checks, no redirects, and 429 retry bounds. **Do not duplicate it.**
- `includes/POD/PrintifyCatalogSyncService.php` already checks scoped Printify authorization, integration state, vault-backed credential retrieval, and normalized catalog persistence. Its current requirement for an enabled CONFIGURED integration means live GET acceptance needs a **separately reviewed, scoped read-only authorization path**, not a bypass of STOP ALL.
- `docs/printify-scoped-authorization-foundation.md` explicitly denies external HTTP unless authorized and leaves production order mutations denied.
- `includes/POD/PrintifyMutationRequest.php` exists. Do not wire it to a live transport in Stage 1.

## Required Stage 0 compatibility decisions

1. Inventory existing Printify classes, autoloading, REST routes, cron hooks, tables, vault access, portal components and tests across main and the frozen PR #1260. Decide if a **thin companion plugin** adds value over a versioned module without introducing two dispatch authorities.
2. Define one DigiForge-owned shop/credential/approval authority. Companion must not store a second independent provider token or shop mapping.
3. Define an explicit versioned interface for a read-only connector, with hard failure if DigiForge is absent, incompatible, STOP ALL, external locked or integration not authorized.
4. Freeze allowed read-only endpoints and shape contracts for shops, catalog, products, orders and uploads metadata. Verify endpoint versions against official Printify docs before implementation.
5. Create mock-driven tests for forbidden methods, unexpected shop, 401/403/429/5xx, malformed JSON, redirects, oversized bodies, leaked credentials, and no unintended cron or provider requests.
6. Preserve production WordPress runtime and keep existing PR #1260 and #1261 unchanged.

## Stage 1 acceptance

- Code implementing missing GET-only capabilities, **reusing** existing catalog client and integration vault.
- No external API requests in tests or development, no live credentials, no mutating endpoints.
- Exact-head PHP/WP tests and static/security checks; report any GitHub runner DNS infrastructure blocker honestly.
- No production install, no merge, no Etsy publication, no automated orders.
- Document exact SHA, tests, compatibility results and remaining Stage 2 live GET-only approvals.

## Parallel lane

DigiForge FL-03/FL-04 product and personalization development continues on a separate PR with non-overlapping file ownership.

**Safety:** STOP ALL ON, external safety lock ON, automation OFF, RELEASE HOLD. Historical Printify operation #22 remains UNKNOWN and never retried.
