# Portal acceptance and accessibility milestone

All 19 non-finance portal views render under each of the four configured shop profiles. Local behavioral tests cover anonymous/subscriber denial, restricted operator access to AI policy and integration forms, shop-isolated AI policy projections, visible database failures, recovery visibility and unchanged STOP ALL/safety lock/automation controls. Rendering does not contact providers or change business/recovery state. Existing provenance anomaly observations retain their append-first/last-observed audit behavior; rendering is not claimed to be SQL-write-free.

Browser acceptance executed 228 cases: 19 views ×4 shop profiles ×390/768/1280px, with zero failures and zero network requests. Navigation identifies the current view; controls have accessible names and44px target heights; keyboard field traversal, focus outlines, scrollable evidence-table access and mobile overflow were checked. This found and fixed missing AI-stage/review-notes labels and overflowing long POD evidence text. The browser harness requires the complete76-file export; empty, partial, empty-file and unexpected exports fail. Representative four-view mode must be explicitly selected.

The AI spend display now identifies recorded ledger amounts and warns that pending charges can make zero incomplete evidence. It does not certify provider invoices.

Validation: unit1,164/7,379; WordPress231/2,423 including focused5/495; legacy/PHPStan/PHPCS pass; browser fixture coverage contract regression passes. Independent review has no remaining important findings. Private HTML exports contain local nonces and are never committed.

This does not certify server submissions, every operation-specific human workflow, all downstream shop data/authorization, WCAG conformance, real buyer experience or deployed themes. Global counts and global operator views remain global unless explicitly labeled shop-scoped; the four-shop render matrix does not prove tenant-specific access rights. Those acceptance boundaries remain open.

Depends on integrated #1155 at6be26179f242b815ed5d40986735a7de43bdbbbf. No Finance#1147 changes, production deployment, external Etsy/POD action, catalog promotion or provider activation. STOP ALL ON, external safety lock ON, automation OFF. Require exact-head Engineering & Safety Audit before merge.
