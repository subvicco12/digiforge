# Issue #1209 lane B — separate offline Etsy draft plans

Baseline: PR #1208, `2a234de16cd9dfb9573f16d51bafb44dfdfd5f50`.

## Demonstrated gap and implementation

`BatchListingProjection` counts generic readiness; `ListingFactory` explicitly accepts digital delivery only. Neither composes one personalized design into separate physical-product Etsy draft plans. New `Listings\EtsyMultiProductDraftContract::build($design, $products)` fills this bounded, pure contract gap. It does not replace either service or register a REST route.

The design requires an explicit environment, personalized-POD shop key, family/version/spec hash and declared Etsy shop ID. Each product requires distinct immutable product/version references, version hash and SKU; its own production template, one selected Printify variant and unambiguous placement; pinned shop-scoped local download-template, mockup and rights references; exact download template fingerprint/variant/placement tuple; explicit Etsy draft fields; and family_name/established question mappings. Products with different physical types are separate CREATE_DRAFT plans, never a combined variation/inventory payload. Draft fields are allowlisted; nested products/variations and authority/credential claims are rejected.

Existing `ProductionTemplateContract`, `EtsyPersonalizationContract` and `EtsyDraftListingOperations::create` perform template, question and request-plan normalization. Required family name/optional established remain 48-code-point local behaviors, no normalization/case changes, no auto-shrink, conditional `ESTABLISHED ` prefix and blank-line omission. These declarations do not establish Etsy/Printify native behavior. Buyer text is not rendered by this composer. Proposed locale and FC01 art remain untouched.

Stable exact-byte-preserving hashes bind the entire design/product metadata and operation plan. Reordering independent products leaves the manifest unchanged; changing a pinned artifact changes its draft key. This is deterministic planning, **not durable dispatch replay protection**. No listing, package, permission, credential or provider request is created. Existing persistence, ownership/revocation, review, production and transport controls remain mandatory before any future execution.

## Evidence boundary

All ownership, rights, supplier certification and native compatibility remain UNKNOWN even for well-formed hash-pinned references. The composer does not read referenced files or claim authentic identity, approved geometry, a downloadable API endpoint, licensed use, or review receipts. A DRAFT template only supplies declared geometry; it is not supplier certification. A declared production environment still yields network/publish false. Existing data, FC01 v1/v2 assets and ledger counts are unchanged; acceptance is UNVERIFIED.

Missing required draft/reference structure rejects the entire batch before returning plans. No partial persistence exists. No inferred provider, taxonomy, price, SKU or other supplier facts are generated.

## Validation

- New focused synthetic unit suite: 30 tests / 55 assertions PASS, including separate payloads, retry/order stability, hash invalidation, foreign/deferred shops, incorrect variants, ambiguous placements, unsafe/foreign-shop references, mismatched download tuple, missing rights metadata, duplicate SKU/product/version, invalid field mapping and forbidden inventory/publish/credential fields.
- PHPStan targeted level 5: PASS (no errors).
- Full local unit run: 1434 tests / 8329 assertions, one existing skip and one environment prerequisite error in unchanged `OfflineCutoutPipelineTest::testPipelineRequiresExplicitSubjectAndNeverCertifiesSemanticHead`: local `DIGIFORGE_CUTOUT_PYTHON` is not configured. Hosted workflow configures its pinned offline runtime; no cutout code/tests were altered.
- Exact-head hosted audit is required on the eventual published coherent candidate; no earlier audit is claimed for this new code.

## Remaining inputs and next action

Authentic provider/shop ownership; exact variant/placement/geometry; licensed mockup and downloadable-template evidence; native font/layer behavior; reviewed artwork and linguistic evidence; installed-site acceptance and production authorization remain separate blockers. Obtain an existing-product evidence packet read-only, then resolve it through existing authoritative boundaries. Do not execute these plans directly.

Safety unchanged: STOP ALL ON, external lock ON, automation OFF, release hold; no Finance changes, live credentials/provider calls, production/staging writes, listing publication, merge or deployment. Production impact: none.
