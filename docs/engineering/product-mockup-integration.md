# Reusable product mockup rendering and evidence integration

Base: audited PR #1169, `45b4f05b11e89cae1b6cd8b554b1ef60bfb29ff4`. Current main was verified at `844107ab9baf7e1303cd47708e9eab3bc700b348`. This follow-on preserves every earlier branch and finance #1147.

The owner confirmed that no verified licensed mockup assets, customer-display rights or approved variant placement mappings are currently available. The earlier twenty design previews/vector components remain unapproved design inputs; they are not reclassified as licensed product photography or supplier mappings. This change implements infrastructure and synthetic offline acceptance only. **Real customer product previews remain BLOCKED until real evidence is supplied and independently reviewed.**

## Implemented scope

- `ProductMockupRegistry` registers fully decoded, content-addressed protected PNGs with immutable actor/hash/audit provenance. Registration is `REGISTERED_UNREVIEWED`; it does not grant display rights.
- Versioned recipes bind exact shop, Printify blueprint/provider/variant, full current variant-attribute hash, certified template fingerprint, panel and authoritative print-area hash. All bindings are checked against existing current supplier contracts, without external calls or guessed dimensions. Color labels are reviewed metadata tied to the exact variant and base photograph, never speculative recoloring.
- Rights manifests bind every layer's SHA-256, permission for customer display and derivative projection, and an actual protected license-document reference/hash. Missing, altered or wrong-scope evidence fails closed. Hash verification establishes document identity, not legal authenticity; an independent authorized human must evaluate the actual license and product alignment.
- Independent review rejects self-approval, asset-ingester approval, missing flags, critical defects and replay. Intake history and active approved heads are separate: rejected versions remain immutable, corrections can advance, and pending/rejected work does not silently replace the active approved recipe. New active versions invalidate old order bindings/preferences. Renderer source changes also require a new reviewed recipe.
- The local UV16 renderer applies supplied curvature/distortion coordinates, coverage/occlusion and RGB lighting to the exact approved canonical artwork. Fixed-point premultiplied-alpha bilinear sampling avoids hidden-color edge bleed; zero-coverage photo pixels remain untouched. No photographic geometry is inferred. Raster outputs use a maximum 262,144-pixel preview canvas and an eight-second composition deadline, bounded RGBA8 PNG decoding and 64-bit integer arithmetic. Production artwork remains unchanged at its certified dimensions.
- Reusable recipe binding generates a fresh immutable order/render-specific mapping document. Existing mapping approval and editor publication gates still apply. The customer sees digital-preview labeling plus exact variant/color/family, and preview refresh recomputes the current canonical state. Artwork/template/variant/catalog/recipe/asset/rights drift blocks display or invalidates approval.
- Legacy v1 owner-supplied rectangular mappings cannot establish the new rights contract and now fail closed for display; migrate them to reviewed recipes. Legacy synthetic rectangular fixtures remain usable only on a WordPress `local` site with a `test`/`sandbox` order. New synthetic recipes have the same restriction and visible test-only labels. Production, staging and development sites cannot use synthetic display evidence.
- The updated customer script has a content-based cache version so a later authorized release does not keep the previous variant/color display code in browser caches. No production page, account, catalog or provider was activated.

## Registration and review contracts

All routes below are `POST` under `digiforge/v1/pod/product-mockup/`. They require `manage_digiforge_pod`, an authenticated account and `X-WP-Nonce` (`wp_rest`). Customers cannot upload reusable shop assets, submit rights recipes, review or bind variants.

| Route | Exact body keys |
| --- | --- |
| `asset` | `image_base64`, `sha256` |
| `intake` | `reference`, `sha256`, `reference_render_id`, `parent_sha256` |
| `review` | `recipe_sha256`, `outcome`, `flags`, `notes` |
| `bind` | `render_id`, `recipe_key`, `recipe_version`, `recipe_sha256` |

Asset requests are bounded to 8 MiB, decoded files to 4.5 MB, other requests/documents to 64 KiB. Existing approved-render, shop ownership, order identity and current catalog evidence are required. Asset intake uses PNG only; unsupported encodings, oversized images and hash mismatches are rejected.

Recipe JSON resides under protected `pod-mockup-recipes/shop-personalized_pod/`. Exact schema keys are enforced in `ProductMockupRegistry::validate`: `schema=1`, shop/key/version, one of `SOCKS/MUG/SHIRT/PAJAMAS`, reviewed color label, exact renderer version, supplier identities, template/variant/panel/print-area hashes, five registered asset receipts, rights reference/hash and evidence kind. A genuinely new candidate must use the next intake version and previous candidate SHA. Rejected corrections retain their lineage; active display requires the latest approved version.

Required layers share one bounded canvas: `base`, `u`, `v`, `coverage`, `lighting`. U and V encode a normalized 16-bit source coordinate in R-high/G-low, with B=0 and A=255. Coverage uses equal RGB values and A=255 (0 preserves the base; intermediate coverage feathers the projection). Lighting uses RGB modulation and A=255 (255 is neutral). These are supplied/reviewed display maps, not Printify production geometry. Their correct photographic alignment requires actual visual evidence.

Rights JSON resides under protected `pod-mockup-rights/shop-personalized_pod/`: `schema=2`, shop/recipe/version/evidence kind, exact per-layer `asset_sha256`, `customer_display_permitted=true`, `derivative_projection_permitted=true`, and bounded protected `pod-mockup-license` TXT/PDF/JSON document reference/SHA. Review flags are `wrong_product`, `wrong_color`, `wrong_variant`, `misaligned_print_area`, `clipping`, `unlicensed_assets`, `missing_display_rights`, `incorrect_shading_or_occlusion`. APPROVE requires every flag false and an independent currently authorized reviewer; REJECT preserves evidence and permits a corrected next intake.

`bind` returns a schema-v2 protected `pod-mockup-evidence` reference/SHA with state `REQUIRES_OPERATOR_MAPPING_APPROVAL`. Use the existing `/customer-editor/mockup-evidence` approval and `/customer-editor/{session}/publish` gates. Binding a recipe does not approve customer artwork or bypass visual review. Every refresh verifies the actual persisted approved render and exact stored preview bytes before projection. One recipe represents one supplier panel/view; additional views require their own supplied evidence. Customer SKU/billing mutation is not performed: the displayed variant must be the current validated order mapping, and a variant change requires the existing order/render review workflow.

## Source and behavioral evidence

Blueprint v6.0 section 12 `word/document.xml/p80`, production gate `table16/r1/c1/p1`; section 13 `p97/p105`; section 14 `p107`, `table19/r10/c2/p1` require authoritative template geometry and the same approved personalization/render state for previews and production. The implementation reuses their already audited supplier/render contracts. Master 500 family/concept IDs remain unchanged; neither a design family nor a renderer capability establishes actual product compatibility. Original 1,771-record source categories and all missing commercial/pilot fixture statuses are preserved.

- `ProductMockupRendererTest`: eight deterministic tests covering lighting/occlusion, nonlinear UV location, premultiplied alpha, malformed maps, empty coverage, canvas/dimension bounds and synthetic environment restrictions.
- `RenderArtifactCertificationTest::testReusableMockupRecipeRequiresIndependentRightsReviewAndInvalidatesStaleCustomerPreview`: real protected-storage/database/render contracts with synthetic assets; independent approval, self/replay denial, own-order access, production-order rejection, active replacement, tampering and rejected-version correction.
- `testMockupRegistryRejectsMissingRightsWrongVariantsAndUnauthorizedRegistration`: missing rights, wrong variant, malformed/unauthorized asset uploads, REST permission boundary, all four family identifiers and rejection of legacy owner declarations as sufficient display evidence.
- Existing customer/canonical-render/WordPress tests reused; `testCustomerEditorRestIsolationRevocationTamperingAndAutomaticFallback` additionally verifies the new script cache identity.
- `tests/browser/customer-editor.cjs`: three local synthetic journeys at 390/768/1280 pixels, accessible controls, exact variant/color labels, positive transparent replacement, PNG display and stale approval handling. Browser fixture responses are UX evidence only.

Consolidated local validation: full legacy/unit/static/standards checks PASS; 1,286 unit tests / 7,679 assertions (one optional-model skip); 455 WordPress tests / 5,791 assertions (two optional-model skips); 106 offline tests PASS; three browser journeys PASS. The small subsequent script-cache integration is verified by its affected customer regression. Required syntax/security/diff and additional WordPress-aware mockup static checks passed. Dependencies and pinned inference implementation were unchanged from the audited base; mandatory remote audit rechecks dependency advisories. No new model inference, licensed-fixture metrics, photographic quality results or physical print certification are claimed.

## Actual family readiness and smallest owner inputs

| Family | Local renderer/registration | Real asset/placement readiness |
| --- | --- | --- |
| Socks | UV16, occlusion, lighting and exact panel/variant bindings supported; synthetic contracts tested | BLOCKED: licensed sock photograph, correct color/variant, foot/leg/seam view maps and current supplier evidence |
| Mugs | Same bounded map renderer; supplied curvature supported | BLOCKED: licensed mug photograph and reviewed wrap/handle/occlusion mapping for exact variant |
| Shirts | Same bounded map renderer; supplied fabric distortion supported | BLOCKED: licensed shirt photograph, exact color/size and reviewed print-area/lighting mapping |
| Pajamas | Same bounded map renderer; separate approved panel/view recipes supported | BLOCKED: licensed set photography, exact color/variant and reviewed garment-panel/seam mappings |

For one pilot SKU, supply (1) exact current supplier/template/variant/panel evidence, (2) a rights-cleared bounded base PNG plus U/V/coverage/lighting PNGs, (3) the original license document and explicit customer-display/derivative permissions, and (4) an independent reviewer for real alignment/artwork evidence. Codex can validate/import the supplied bytes and run reproducible comparisons afterward. Owner-provided real fixtures and physical supplier certification remain separate; synthetic acceptance does not substitute for them.

STOP ALL ON; external lock ON; automation OFF. Overall final-product acceptance INCOMPLETE. No merge, deployment, provider calls/spending, catalog activation, order submission or external Etsy/POD actions. Final exact-head audit/package results belong to the published PR/task report; they are not fabricated inside a self-referential commit.
