# Template-free Printify geometry acquisition

## Supported boundary

This adds an additive, non-persisting rectangle-evidence projection to the existing scoped `PrintifyCatalogPreview::inspectScoped` path. No routes, credentials, grants, migrations, network operations or certification systems are added. Existing ownership, consent, expiry, revocation, replay, quota, STOP ALL and external-lock checks run unchanged before/after mocked or future separately authorized catalog reads. Legacy `print_area: null` and `geometry_status: UNKNOWN` are preserved.

`geometry_evidence_schema: printify-catalog-rectangles-v1` identifies the projection. Each variant exposes only documented `position`, `decoration_method`, `width` and `height` from catalog placeholders, with `placeholder_dimensions_status: OBSERVED` or UNKNOWN when absent/empty. Dimensions are pixels, not physical dimensions or DPI. The original response SHA-256, observation timestamp and exact endpoint remain bound to the read. `variant_record_sha256` hashes the existing repository's canonical public variant record, not the original response bytes. No raw sensitive response is returned or stored by the preview.

Malformed dimensions, duplicate normalized method/placement pairs, declared-method conflicts and mismatched optional provider envelope IDs fail closed. Other valid placements remain in the projection; no unrelated placement is selected as a front-print replacement. Different variants retain different dimensions, never an averaged/minimum/universal rectangle.

Safe-zone, bleed, DPI, production-tolerance, provider-routing and cross-variant scaling states explicitly remain UNKNOWN. Provider scope in a catalog endpoint is not proof of fixed Choice fulfillment. No response field or hash is treated as commercial rights, product ownership, physical tolerance, linguistic review or production certification.

## Official source policy, checked 21 October 2026

- [Design templates](https://help.printify.com/hc/en-us/articles/11240165642641-How-do-I-use-the-design-templates): only some products have downloadable PNG/AI templates. Templates are not created for products added after September 2024; existing earlier templates remain available. The article describes provider selection and variant-specific design sizing. **Missing ZIP alone is not incompatibility.**
- [Catalog API](https://developers.printify.com/#catalog): GET blueprint, blueprint-provider and provider-specific variant resources. Variant placeholders document position, decoration method, integer width and height in pixels; variants also declare available decoration methods. This rectangle contract does not supply verified numeric safe/bleed polygons, DPI or production tolerances. Never infer these from image size, mockup bounds or a public catalog URL.
- [Choice resizing](https://help.printify.com/hc/en-us/articles/33200256364945-Will-my-design-be-resized-with-Printify-Choice): routing uses a provider network; rare design-size adjustments of up to 7% are documented. This general statement is not a certified tolerance or scale factor for a selected shop product.
- [Apparel sizing](https://help.printify.com/hc/en-us/articles/4483635618961-Will-my-design-be-scaled-up-or-down-based-on-the-garment-size): smaller-size designs may scale down; provider pallet sizes differ, and the Product Creator mockup displays the L-size area. Larger-than-L artwork is not scaled up; sleeves have separate behavior. Do not use a mockup to certify every selected variant.

See `template-free-printify-source-provenance.json` for retrieval provenance. Public documentation establishes source contracts, not actual shop/variant evidence. Existing October21 template-policy evidence is reused from Batch23; no authenticated provider API or editor was accessed.

## Reuse and fail-closed promotion

1. Obtain a separately authorized, exact blueprint/provider/variant catalog observation through existing catalog GET controls. Current locks do not grant permission to run a request merely because this feature exists.
2. Preview dimensions without manufacturing missing fields. Missing placeholder data remains UNKNOWN, not INCOMPATIBLE.
3. Existing `PrintifyCatalogSyncService` / `ProductionTemplateContract::fromPrintifyVariants` already preserve versioned response/variant/placeholder digests and exact method/placement bindings. Reuse those contracts only under their existing authorization. The preview itself does not persist or automatically promote into them.
4. Existing production template validation rejects inconsistent placement sets or different selected-variant geometry; split the template/version as required rather than silently rescale. A rectangle alone cannot authorize production; rendering still requires reviewed safe/bleed, region, rights, glyph/overflow and current supplier evidence.
5. FC01v2 OptionA and all v1 sources remain unchanged. Exact buyer UTF-8, 48-code-point limits, optional whole-line omission, no automatic shrink/substitution and preview/final parity remain separately required. Native Printify scaling cannot be substituted for FC01 overflow rejection.

## Acceptance and remaining evidence

Synthetic tests demonstrate projection, malformed/ambiguous evidence rejection and unchanged security boundaries only. No authentic blueprint/provider/variant/SKU or front-print binding is added for store28930593/product6ad647d23c2fe90c6102cb1c. Observed3951x4800px/300DPI and the five embroidery guides remain separate. All22 non-Finance and23 Finance-dependent unresolved criteria remain UNVERIFIED; no acceptance counts or Master500 commercial states change.

Minimum authentic input: exact selected variant/product identity, provider/Choice program binding, method/placement/version, corresponding API record and authoritative supplemental safe/bleed/resolution/region requirements. Then independent rights, typography, artistic/linguistic and production review. Unsupported inputs remain UNKNOWN.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD; NO-GO. No provider calls, product edits, orders, publishing, production/staging writes, activation, merge or deployment performed.
