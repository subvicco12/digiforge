# Printify v2 shipping contract correction — PR #1195

Source checked: 2026-10-17, official [Catalog V2 API documentation](https://developers.printify.com/#v2-catalog-endpoints), including Shipping list attributes and Specific Shipping attributes. No authenticated supplier endpoint was called. JSON fixtures under `tests/fixtures/printify/shipping-v2-*.json` are synthetic, based on the documented examples; their IDs and amounts establish no actual product, provider, destination availability or price.

## Separate scopes

- `{"kind":"shipping","blueprint_id":1220,"provider_id":105}` constructs only `/v2/catalog/blueprints/1220/print_providers/105/shipping.json`. Its `data[]` contains `type=shipping_method`, string `id`, and `attributes.name`.
- `{"kind":"shipping_method","blueprint_id":1220,"provider_id":105,"method":"standard"}` constructs only the corresponding `/shipping/standard.json`. The exact allowlist is `standard`, `priority`, `express`, `economy`. Every method requires its own explicit request in the existing owner-approved grant. Discovery does not authorize a linked method or cause an additional request.
- Method records require `data[].attributes.shippingType`, country code, positive integer variantId, shippingPlanId, ordered handlingTime bounds and nonnegative integer minor-unit shippingCost amounts with consistent currencies. Resource id/type must agree with the variant, method and country. Duplicate variant/country records fail closed.
- v1 `profiles` envelopes are rejected on both scoped v2 paths. Missing/malformed required rate fields are rejected, never interpreted as zero. Empty `data` means no verified coverage; it is not a free or universally available service.
- Optional response links are never followed. If present, they must match exact fixed-host/provider/blueprint/method paths. The documentation's discovery example repeats the `priority` link key for its express URL; no request authority is derived from that example or response links.

## Evidence and safety

The existing repository/client supplies account/shop/integration binding, expiry, separately approved catalog-GET safety exception, durable quota/reservation/dispatch anchors, replay rejection, before/after authorization checks, token protection, bounded GET transport and immutable response SHA-256/absolute observation timestamp. No alternate authorization or new persistence system is introduced. Legacy client methods, their return shapes and variant source provenance remain unchanged.

Accepted receipts remain `DRAFT_UNVERIFIED`, `supplier_certified=false`, `mutation_authorized=false`. `REST_OF_THE_WORLD` is preserved literally and never expanded into countries or treated as certified destination coverage. Country strings and documented record associations alone do not establish actual destination eligibility, current rates, ownership or supplier certification. Unknown or absent shipping information remains unverified. No live evidence is collected or persisted by this engineering task.

## Behavioral evidence

- `PrintifyCatalogShippingContractTest`: documented discovery/method envelopes, all four exact paths, malformed scope/data, legacy-envelope rejection, type/id/country/method consistency, money/time bounds, ambiguous records, response links and resource bounds.
- `PrintifyCatalogIntegrationTest::testShippingDiscoveryAndMethodReadsAreSeparatelyScopedDraftEvidence`: real synthetic WordPress REST registration, locked denial, explicit exception, scope mismatch, bounded GETs, separate discovery/rate requests, hashes/timestamps, unverified state and replay rejection.
- `PrintifyCatalogIntegrationTest::testMalformedShippingDiscoveryAndRatesConsumeIntentWithoutEvidence`: invalid v2 responses cannot become evidence or be retried with the same intent.
- Existing catalog authorization, revocation, deleted-evidence, shop isolation, expiry and in-flight drift regressions remain mandatory. Updated catalog smoke tests exercise the corrected fixtures; legacy shipping paths and variant evidence compatibility are retained.

Release hold remains. PR #1195 includes PR #1194 ancestry; do not merge it independently. Authentic provider responses, verified product/variant ownership, current destination/rate evidence, supplier geometry, asset rights and explicit operational authority remain separate requirements. This correction does not certify any supplier or authorize activation.
