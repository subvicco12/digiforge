# Render artifact and review boundary

Hash-only legacy render evidence can be recorded as UNREVIEWED history; it cannot be approved or used for a new review package. The server-only `createFromArtifacts` boundary reads bounded local PNG/JPEG files, derives SHA-256/size/dimensions from bytes and preserves an immutable receipt. Receipt persistence also reads files internally; caller-provided digest arrays cannot establish authority. URLs and stream wrappers are rejected and file paths are not stored.

Review requires the exact stored VALIDATED template, a recomputed normalized template fingerprint, an approved matching supplier/blueprint/provider/variant, current approved personalization on a validated order line, and active DigiCraftifyGoods PERSONALIZED_POD ownership. Canonical policy ownership is `personalized_pod`. Numeric Etsy webhook identities resolve through matching same-environment configured identity evidence and the existing exact approved-business identity contract; arbitrary aliases and other shop IDs fail closed.

Both render and package reviewers require `manage_digiforge_pod`. New package creation and approval recheck current artifact/template evidence; package approval additionally checks readiness and ownership against the immutable package. Review never authorizes provider execution.

Supported evidence is single-panel PNG/JPEG byte parity and dimensions. Multi-panel/PDF/SVG artifacts, full decoding/visual QA, the actual personalized renderer, correctness of the rendered personalization, live supplier geometry/sample certification and authentic deployed buyer previews remain acceptance gaps. A stored VALIDATED status and a synthetic test fixture do not independently establish those operational facts. Legacy history is not rewritten.

Validation: unit1,164/7,379; WordPress236/2,045 including focused12/58; legacy, PHPStan and PHPCS pass. Independent review found and verified fixes for receipt fabrication and shop identity mismatch. All fixtures are local; no provider activation, external Etsy/POD action, catalog promotion or deployment. STOP ALL ON, external safety lock ON, automation OFF. Finance#1147 unchanged.

Dependency: integrated candidate #1155 at6be26179f242b815ed5d40986735a7de43bdbbbf; contains #1148–#1157 non-finance components. Review this PR against #1155 to isolate its delta; main remains unchanged pending governance verification.
