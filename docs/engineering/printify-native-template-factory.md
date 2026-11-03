# Printify-native personalized template factory

This software adds a fail-closed bridge from persisted Printify catalog variant responses to immutable DigiForge template geometry. It does not certify supplier compatibility, physical print quality, artwork rights, safe zones, bleed, or production readiness.

## Geometry and evidence contract

`PrintifyCatalogClient::variantsWithEvidence()` retains the exact catalog endpoint path, response-body SHA-256, and observation timestamp from a read-only authenticated catalog request. `PrintifyCatalogSyncService` attaches variant identity, environment, and a canonical per-variant digest before the existing catalog persistence boundary. Subsequent identical catalog syncs refresh observation evidence without treating a changed response envelope as material geometry drift.

`ProductionTemplateContract::fromPrintifyVariants()` accepts persisted rows only. It derives blueprint, provider, variant IDs, placement, decoration method, and pixel dimensions from each variant's actual `placeholders`; caller geometry must match exactly. Selected variants must expose the same placement set and dimensions or be split into separate templates. Non-draft Printify templates need per-placement, per-variant provenance bindings and a current persisted catalog match. Rendering checks the exact bound catalog variants again; unavailable evidence, a changed variant digest/revision, or changed/missing placeholder geometry fails closed.

This follows the Printify V1 variant response contract (`variants[].placeholders[]`: `position`, `decoration_method`, `width`, `height`). The upstream API response does not establish physical safe zones, bleed margins, regional supplier quality, or a physical sample result. DigiForge therefore preserves those as separate evidence/QA inputs and makes no defaults or supplier-certification claims.

The existing versioned personalization recipe and artifact verifier remain the single canonical design source for the buyer preview and final output. Their stored-byte reconstruction, dimension, panel, and local visual checks are reused; no renderer or preview implementation is duplicated here.

## Master 500 v2 inventory

`printify-template-support-20261011.tsv` maps every source concept row (including source IDs, family, engine, physical product, supplier gate, template state, and stage) to local engine support and exact supplier blockers. It has 500 source concept rows across 24 families. 360 rows map to an existing deterministic local renderer engine; 140 map to one of the five contract-only transformation engines (`PET_PORTRAIT`, `WATERCOLOR`, `LINE_ART`, `VINTAGE_PORTRAIT`, `AI_TRANSFORM`). All 500 remain `Printify supplier status = NOT_VERIFIED` because a source concept is not a provider variant binding or supplier QA certificate.

Local renderer coverage is engine-level only. It does not imply that every composition, font, region, product, variant, Printify placement, dimension, DPI target, or physical sample has been approved. Specific source concepts still require the exact catalog response and supplier-specific approval evidence before their templates may be locked for a real route. Unsupported geometry requires a separate template; no cross-variant geometry is inferred.

## Verification boundary

Regression fixtures are explicitly synthetic and exercise schema validation, exact variant and placement binding, geometry mismatch, provenance tampering, differing variant shapes, and stale locally persisted catalog evidence. They establish software behavior only. No Printify API call, live provider action, catalog promotion, order submission, or production activation is part of this work.
