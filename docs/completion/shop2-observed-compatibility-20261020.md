# Shop 2 observed-product compatibility

## Source and classification

Source: [authenticated UI research receipt](https://github.com/subvicco12/digiforge/issues/1209#issuecomment-6285147372), reviewed 2026-10-20. This engineering task made no provider requests and did not independently access the dashboard. These are attributed UI observations, not API-certified ownership or supplier evidence.

| Identity/fact | Classification and consequence |
|---|---|
| DigicraftifyShop / Printify store 28930593 | UI-observed store association; exact approved connection binding still required |
| Printify product 6ad647d23c2fe90c6102cb1c | UI-observed existing product; not a blueprint ID |
| Etsy listing 4598800484 | UI-observed mapped listing; current ownership/authorization remain unverified |
| Bella+Canvas 3001 / Printify Choice | Dynamic fulfillment; no fixed provider is inferred from name, mockup URL or numeric URL segments |
| 43 selected variants; SKU “Multiple” | Observed aggregate only; exact variant IDs and per-variant SKU mapping UNKNOWN |
| Front 3951 × 4800 px / 300 DPI | Attributed UI observation only; method, safe/bleed rectangles, variant binding and specification revision UNKNOWN |
| Earlier five embroidery guides | Separate original-byte evidence; cannot certify the front print area; original forensic receipt remains unchanged |
| Etsy connection token expired | Connection-readiness blocker; no renewal attempted or authorized |
| Multi-Product Listing warnings | Mockup/variant/pricing edits can affect linked listings; complete dependency inventory UNKNOWN |
| Unsaved changes warning | Persisted product state UNKNOWN; no assumption about earlier edits |

## Reproduced gap and bounded fix

The separate-draft compiler emitted no provider-listing dependency state even when two products declared the same mockup. A focused regression first failed on missing `provider_listing_dependencies`. It now explicitly projects UNKNOWN variant/pricing/linkage state and `independent_edits_permitted=false`, and lists product-version IDs sharing a mockup reference or byte hash. This is declaration-level overlap detection, not discovery of actual Printify linked listings. Different assets never establish independence. The additive projection is included in the manifest hash; existing per-product draft keys and payloads remain unchanged. No new inputs, authority, schema migration, HTTP client or dispatch path are added.

The existing fixed-provider geometry contract rejects a Choice label or absent blueprint instead of fabricating a provider mapping. Existing download-template/variant/placement matching, rights UNKNOWN, ownership UNKNOWN and no-network gates remain intact. Expired-token lifecycle returns unusable authorization and never permits a token request, even when refresh metadata exists. Synthetic positive/negative tests exercise these boundaries; they do not validate real provider records.

## Acceptance and owner actions

No criterion promoted. The 268-criterion ledger remains 246 implemented candidates / 22 UNVERIFIED. BP-printify-shop-scope, BP-etsy-shop-identity, BP-portal-4-provider-connections, BP-portal-7-mockups, BP-portal-7-supplier-readiness and BP-seam-panel-render retain their authentic-evidence blockers.

Smallest next evidence collection: a read-only existing-product export/receipt linking shop/product/listing to exact blueprint, selected variant IDs, per-variant SKUs, decoration method, versioned placement geometry and Choice routing semantics. Collect the complete linked Multi-Product Listing dependency inventory and current saved revision before considering independent edits. Obtain mockup display/derivative rights separately. Owner-managed Etsy reconnection requires separate authorization and is not part of this task. Production authority and installed-site acceptance remain absent.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No Finance edits, production writes, token renewal, provider mutation, merge, deployment or supplier certification.
