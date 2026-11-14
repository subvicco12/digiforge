# DigiForge — 20-design concept QA and integration handoff

**Scope:** Read-only inspection of the existing 20 concept preview SVG/PNG pairs and manifest. This is **not** artistic, licensing, supplier, or production certification.

## Verified results

- Manifest records: **20**; collection distribution: Family & Couples: 4, Memorial: 4, Names & Monograms: 4, Occasions: 4, Pet Personalization: 4.
- PNG/SVG pair existence, XML parsing, preview dimensions, and PNG SHA-256 integrity: **20/20 passed**.
- All previews are intended as **1000 × 1000 concept images**, not production files.
- All 20 SVG previews use live `<text>` elements; fonts are **not embedded/outlined**. This must be addressed in a deterministic production rendering pipeline.
- No authoritative Printify blueprint/provider/variant/placement or print-area geometry was supplied in these assets. **0/20 are production certified.**
- No independent artistic review, font-license verification, actual supplier print sampling, or customer-data approval has been performed here.

## Integration acceptance checklist for Codex (after its current task completes)

1. Import concept IDs and reusable vector components as **draft-only**, preserving existing manifest identity and hashes; never auto-publish.
2. Replace fictional preview personalization with typed, escaped, length-bounded customer inputs; test long names, diacritics, RTL, emojis and missing fields.
3. Bind to an **exact** supplier blueprint, provider, variant, print area and placement; fail closed when geometry is missing, incompatible or stale.
4. Render preview and final production file from one immutable approved design state. Verify font files, licensing, glyph coverage and deterministic layout.
5. Sanitize SVGs: reject scripts, external URLs, foreign objects, unsafe references and unsupported constructs; preserve vector quality.
6. Test transparent-background and color-profile requirements against **actual** supplier specifications, including bleed/safe zones where applicable.
7. Enforce shop isolation, authorization, provenance, approval and replay protection; keep live Etsy/POD actions blocked.
8. Complete human artistic review and physical-sample checks before any commercial certification.

## Safety

STOP ALL ON; external safety lock ON; automation OFF. Finance PR #1147 and PR #1161 untouched. No merge, deployment, live spending, provider orders or catalog promotion.

## Deliverable status

**TECHNICAL CONCEPT PACK VALIDATED** for integrity and format only. **PRODUCTION READINESS: BLOCKED.**
