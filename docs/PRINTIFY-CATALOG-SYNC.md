# Printify Catalog Sync Foundation

This change introduces a **non-executing, fail-closed normalization boundary** for future Printify catalog synchronization.

## Safety boundary

This batch does **not** call Printify, Etsy, Gelato, AI providers, or any other external service. It does not publish listings, create provider products, submit orders, run workers, enable schedules, or change STOP ALL / automation controls. Credentials remain in DigiForge's encrypted integration vault.

## Normalized identity

A Printify catalog variant is represented in the existing POD catalog using:

- `provider = printify`
- `provider_product_key = blueprint_id`
- `provider_variant_key = print_provider_id:variant_id`
- provider/environment-specific cost, shipping, availability and source revision evidence

Exact production geometry is normalized separately from the catalog variant. A print area requires:

- `position`
- `decoration_method`
- `width_px`
- `height_px`

The deterministic template fingerprint includes provider, environment, blueprint, provider+variant identity, and sorted print-area geometry. A geometry change therefore produces a different fingerprint rather than silently rewriting an approved template.

## Intended future flow

1. External HTTP client fetches Printify catalog data only after a separately reviewed execution batch enables that capability.
2. Raw provider responses are mapped into `PrintifyCatalogContract` input arrays.
3. The contract validates IDs, money, environment, availability, timestamps and exact print-area geometry.
4. Normalized evidence is persisted through the existing POD repository/schema.
5. Supplier scoring selects a primary/backup regional route.
6. Only after supplier selection can production-template generation proceed.

This preserves the DigiCraftifyGoods rule: **demand first → supplier second → template last**.

## 40oz tumbler exception

The market opportunity remains valid, but the currently researched Printify 40oz route is not approved for US template generation. DigiForge must keep the concept in alternative-provider research until a commercially acceptable US physical product is selected.
