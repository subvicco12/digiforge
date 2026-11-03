# Held product-package input examples

These FL-02/FL-03/FL-04 examples are schema-complete review inputs, not commercial
artwork, current account readbacks, supplier certification or approved prices.
Real Printify product IDs and historical FL-02 Etsy Draft ID are preserved.
Variant IDs, SKU, provider template, actual media, shipping and fee evidence remain
unknown instead of fabricated. Titles/tags are editable review copy, not approved SEO.

Use ProductPackageRepository::prepareCommercial('personalized_pod', $designHash,
$evidence) with an actual server-resolved approved Day 3 design. The approved
profile's draft reference must match the target product. FL-02 currently has no
Day 3 commercial profile: use prepare() for technical review only, never reuse
an FL-03 commercial approval as a Christmas-overlay approval.

The authenticated service returns a local immutable package hash. Inspect it,
record a separate APPROVE/REJECT decision, then export the exact approved version.
Every export stays held and uncommercialized. The workflow tests demonstrate both
actual A3 mug and poster handoffs with explicitly synthetic source/criteria input.
Source hashes, rights, truthful media and native provider mappings must be supplied
and independently reviewed before a real purchasable product is considered.
