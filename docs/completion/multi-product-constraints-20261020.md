# Real multi-product listing constraints: offline compatibility

Baseline: unchanged draft #1218 `e2a3a3bc91befe35a70379ebe2cde3479b901ee0`, audit #4751 PASS. Main remains `844107ab9baf7e1303cd47708e9eab3bc700b348`. Guidance, original-source inventory and authoritative 268-criterion ledger reused; no duplicate source census.

## Attributed evidence, not certification

[Issue #1209 evidence receipt](https://github.com/subvicco12/digiforge/issues/1209#issuecomment-6285483513), dated 2026-10-20, reports authenticated read-only Printify UI observations. No provider request or token access occurred in this engineering task.

- DigiForge shop: `personalized_pod`; attributed external association: DigicraftifyShop / Printify store `28930593`. Authentic approved ownership is not established by this receipt alone.
- Existing T-shirt product: `6ad647d23c2fe90c6102cb1c`. Enable personalization unchecked; no active fields, limits or preview observed. This is not FC01 personalized-flow evidence.
- Linked Printify listing: `8a2666c5-d3db-4b44-bf6a-4c5b4213815e`. This UUID is a Printify multi-product listing ID, **not** an Etsy numeric listing ID or a local product/version primary key. Earlier UI-associated Etsy listing `4598800484` is not inferred to be the external identity of this entire linked group.
- Primary candle `6ad647d03c2fe90c6102cb18`; additional hoodie `6ad647d14f4a65e4300211e4`, sweatshirt `6ad647d24f4a65e4300211e5`, T-shirt above, stickers `6ad647d01f72f1c0030e41be`, tote `6ad647d04f4a65e4300211e2`.
- UI reports **31 × 16 = 496 combinations**, versus its displayed **Etsy maximum 400**. Do not auto-trim variants, split this existing group or change membership. The 400 constraint is applied conservatively to a single inventory plan; it is not a cap on a sum across separate listing plans, nor proof that an under-limit listing is otherwise supported.
- UI says: “All products in your Multi-Product Listings will share one shipping price.” / “Changes you make here will also update the Single Listing pricing.” Complete pricing/shipping/mockup/variant dependency inventory and reviewed change scope remain UNKNOWN.
- Printify Choice remains dynamic. No fixed supplier inferred from product title, URL or mockup. Blueprint, selected variant IDs, exact SKUs, method, versioned geometry and rights remain UNKNOWN. The earlier front-print 3951 × 4800 / 300 DPI observation and five embroidery guides remain separate evidence.
- Etsy authorization expired; no renewal attempted. No real source observations become signed reviews or production authority.

## Reproduction and minimal fix

`FC01MultiProductDraftJourneyTest::testOfflineInventoryRejectsExcessCombinationsWithoutDispatch` first reproduced acceptance of 401 and 496 inventory combinations (two failed assertions); 399 and 400 already passed. `EtsyDraftListingOperations::inventory` now returns its existing fail-closed inventory error above 400, preserving payloads at/below the limit. It never silently truncates, deduplicates, splits, sends or persists inventory. Each inventory product is one combination; callers must supply the complete inventory, not an aggregate count.

The dependency projection lacked explicit shipping state, and the question-plan projection lacked explicit active buyer-personalization state; focused tests reproduced both missing keys. Add `shipping_dependencies=UNKNOWN` and per-plan `buyer_personalization=UNKNOWN`. Planned questions cannot establish active provider fields; disabled evidence supplied as an unsupported product field remains rejected by the strict input schema. No provider observation import or authority adapter is invented.

Existing `independent_edits_permitted=false`, no-network/no-publish gates and independent ownership/consent/global safety checks remain unchanged. All pricing, shipping, mockup and variant mutations remain unavailable; these UNKNOWN projections are not an executable mutation permission mechanism. No reviewed dependency plan is inferred. The actual observed six-product group cannot be emitted as an executable provider update by this pure planner.

Synthetic fixtures exercise the limits and fail-closed projection, plus existing shop isolation, revocation, missing font, optional field omission, preview/final parity and deterministic retries. Synthetic inventory rows do not stand in for the actual 496 provider variants or certify SKU/geometry compatibility.

## Acceptance and smallest owner actions

No criterion promoted: **246 implemented candidates / 22 UNVERIFIED**, unchanged. BP-printify-shop-scope, BP-etsy-shop-identity, BP-portal-7-supplier-readiness, BP-template-qa-spelling and installed/provider certification remain unverified.

1. Collect read-only exact group membership and selected variant/SKU matrix, existing listing identities, current saved revision and full shared pricing/shipping/mockup dependencies.
2. Owner must choose a reviewed variant/membership strategy at or below the observed limit; this task makes no choice or provider edit.
3. Supply actual enabled native personalization fields, mappings, optional-line behavior, font and parity evidence for a designated FC01 product. The existing disabled T-shirt is not positive evidence.
4. Supply authentic ownership, Choice routing semantics, supplier geometry, rights and independent reviews. Separately authorize any owner-managed Etsy reconnection; production/release authority remains absent.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. Finance excluded. No provider calls, token renewal, production writes, orders, publishing, merges or deployments. **NO RELEASE.**
