# Printify Catalog Sync Foundation

This change introduces a **non-executing, fail-closed normalization boundary** for future Printify catalog synchronization.

## Safety boundary

This batch does **not** call Printify, Etsy, Gelato, AI providers, or any other external service. It does not publish listings, create provider products, submit orders, run workers, enable schedules, or change STOP ALL / automation controls. Credentials remain in DigiForge's encrypted integration vault.

## Business and product-program separation

DigiForge treats supplier catalog data as shared infrastructure, while business activity is explicitly scoped above that catalog.

- `DigiCraftifyGoods` is restricted to `PERSONALIZED_POD`.
- The future original-design POD business uses `ORIGINAL_DESIGN_POD`; its consumer-facing business/store name remains TBD until separately selected.
- A shared physical product such as a T-shirt, hoodie, mug or poster is synchronized once from Printify and may be mapped independently by multiple DigiForge businesses.
- Listings, designs, personalization schemas, pricing, research decisions, mockups, publishing controls, orders, financial records and analytics remain business/store scoped.
- Business-active mappings must provide both `business_id` and `store_id`; they are not inferred from a supplier blueprint.
- DigiCraftifyGoods cannot accept an `ORIGINAL_DESIGN_POD` mapping. The normalization boundary rejects that combination fail-closed.

The resulting hierarchy is:

`DigiForge → Business → Store → Product Program → Product/Listing → Shared Supplier Catalog → Production Template`

This prevents original non-personalized designs from drifting back into DigiCraftifyGoods while avoiding duplicate supplier/product definitions.

## Normalized supplier identity

A Printify catalog variant is represented in the existing POD catalog using:

- `provider = printify`
- `provider_product_key = blueprint_id`
- `provider_variant_key = print_provider_id:variant_id`
- provider/environment-specific cost, shipping, availability and source revision evidence

Exact production geometry is normalized separately from the catalog variant. A print area requires `position`, `decoration_method`, `width_px`, and `height_px`.

The deterministic template fingerprint includes provider, environment, blueprint, provider+variant identity, and sorted print-area geometry. A geometry change therefore produces a different fingerprint rather than silently rewriting an approved template. Invalid/non-finite geometry and JSON encoding failures are rejected rather than collapsed into a shared fallback hash.

## Intended future flow

1. External HTTP client fetches Printify catalog data only after a separately reviewed execution batch enables that capability.
2. Raw provider responses are mapped into `PrintifyCatalogContract` input arrays.
3. The contract validates IDs, money, environment, availability, timestamps and exact print-area geometry.
4. Shared normalized supplier evidence is persisted through the existing POD repository/schema.
5. A business/store/product-program mapping references that shared evidence.
6. Supplier scoring selects a primary/backup regional route.
7. Only after supplier selection can production-template generation proceed.

This preserves the DigiCraftifyGoods rule: **demand first → supplier second → template last**.

## 40oz tumbler exception

The market opportunity remains valid, but the currently researched Printify 40oz route is not approved for US template generation. DigiForge must keep the concept in alternative-provider research until a commercially acceptable US physical product is selected.
