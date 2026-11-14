# Customer personalization editor and digital product previews

This follow-on is stacked on audited PR #1168 (`118e635fe2e5541d5062ebc632941b3e9c6396b4`). Main was inspected at `844107ab9baf7e1303cd47708e9eab3bc700b348`. Earlier branches and finance #1147 are preserved.

The website exposes `[digiforge_personalization_editor session="<operator-issued-key>"]` (or `df_editor` on a page containing the shortcode). Adding or publishing a production page is deferred. Customers sign in to a WordPress account with `read`; they receive no operator capability. A stored Etsy buyer reference is never inferred to be a WordPress user ID.

## Workflow and boundaries

1. An operator binds a customer account to one validated Personalized POD order/line using a protected, hashed access document. Every read and write rechecks the live order scope, buyer reference, access hash/audit and revocation. An unbound account cannot read drafts, upload or approve preferences.
2. The customer uploads a cropped transparent PNG or chooses experimental offline SAM Small + PyMatting. The automatic option requires an explicit interior head rectangle/foreground points; it is not automatic semantic head detection. Existing model/hash/license/runtime isolation, CPU/RAM/time limits and capacity lock apply. Unavailable inference produces an uncertified failure revision retaining the validated original; a transparent replacement is supported.
3. JPEG/PNG normalization uses the existing decoder, orientation handling and metadata stripping. Original bytes are retained privately with hashes. Draft display never exposes protected paths, reference mattes or operator evidence. Limits remain 4.5 MB, 1,048,576 pixels, eight image slots and twenty revisions per session. Unknown formats/options, opaque manual inputs and invalid subject selections fail closed.
4. Typed personalization fields reuse the approved stored schema. Drafts can be incomplete; publication requires the original full required-field contract. Session locks, compare-and-swap heads, immutable parent hashes and request-key receipts prevent racing replacements and replay. Decoded artifacts are staged until record-size, audit and head checks succeed. Invalid/stale/oversized requests do not retain files or run inference.
5. The existing operator intake and independent visual-review workflow must certify the exact draft pixels before publishing a product preview. Automatic artwork is reprocessed with the actual segmentation method and must reproduce the exact draft cutout; it is never relabeled as manual extraction. Customer declarations are `DECLARED_UNVERIFIED`, and customer preference cannot substitute for independent review.
6. The approved canonical renderer re-verifies actual stored source/cutout, schema, template and render. A digital mockup additionally requires an operator-approved, rights-bound base image and exact provider/blueprint/variant/panel print-area evidence. The approved display mapping currently supports **orthographic rectangular raster placement only**. Curved/perspective/UV product simulations require an approved contract and fail closed; supplier dimensions are not guessed.
7. The customer sees original/cutout comparison, checkerboard or light/dark backgrounds, zoom, typed fields, replacement history, extraction-failure feedback, a separate digital mockup, refresh and explicit digital preference approval. Unsaved edits disable approval. Server approval recomputes current evidence. Artwork, template, variant, mapping, source, review, access or supplier-evidence drift blocks the preview or changes its context hash, invalidating stale preferences. Restoring a variant with a new mapping revision does not revive an old approval.

All decisions retain `supplier_certified=false` / `external_execution_authorized=false` where applicable. STOP ALL ON, external lock ON, automation OFF. No controller issues production permits, changes safety settings, activates suppliers, promotes catalogs, submits orders or calls external providers.

## Access and display documents

REST namespace: `digiforge/v1`. All editor routes require the authorized signed-in account and `X-WP-Nonce` for `wp_rest`, including reads. Customer endpoints: `GET /customer-editor/{session}/view`, `POST .../upload`, `.../fields`, `.../approve`. Operator-only endpoints: `/customer-editor/access`, `.../{session}/revoke`, `.../{session}/intake`, `.../{session}/publish`, `/customer-editor/mockup-evidence`.

Access proof: protected `pod-editor-evidence/shop-personalized_pod/<key>.json`, exact fields `schema=1`, `shop_key`, `order_id`, `line_item_id`, `customer_user_id`, `buyer_reference`, `local_personalization_access_authorized=true`. The grant also supplies the original document SHA-256. This records an operator's authorization, not verified buyer identity by itself; the owner must establish that account binding.

Digital mapping: protected `pod-mockup-evidence/shop-personalized_pod/<key>.json`, exact schema keys in `CustomerProductMockup::mapping`. It binds the current order/render/template hashes, Printify blueprint/provider/variant/panel, canonical print-area hash, protected base receipt, bounded display rectangle, rights-document hash and evidence kind. `projection=ORTHOGRAPHIC_RECTANGLE_V1`; print-area geometry and pixel display placement are distinct. Protected `pod-mockup-rights` document records exact base SHA, permission for local customer digital display, shop and evidence kind. `SYNTHETIC_TEST_ONLY` evidence is visibly a local regression fixture, never commercial certification. Operator approval is immutable, audited and independent of the customer.

## Source-to-behavior evidence

Original source records are preserved; these rows are not new distinct Blueprint requirements or a regeneration of the 1,771-record inventory.

| Source / boundary | Implementation | Behavioral evidence |
| --- | --- | --- |
| Blueprint section 12, `word/document.xml/p80`: exact template/schema/geometry/preview flow | `CustomerEditorRepository::publication`, `CustomerProductMockup::compose` | `RenderArtifactCertificationTest::testCustomerCertifiedMockupApprovalInvalidatesOnReplacementAndVariantDrift` |
| Section 12 production gate, `table16/r1/c1/p1` | operator-only intake/publication, separate customer preference, unchanged production gates | same test; `testCustomerEditorManualReplacementIsOwnedImmutableAndNeverArtworkApproval` |
| Sections 13–14, `p97`, `p105`, `p107`: authoritative geometry, frozen versions, reusable engine/template separation | current certified template and exact variant/panel binding; immutable revisions | certified mockup test and existing production-template/canonical-render tests |
| Section 14, `table19/r8/c1/p1`, `table19/r10/c2/p1`, `table19/r13/c2/p1`: schema, common approved preview/render payload, human approval | approved schema validation; stored-artifact reproduction; exact reviewed cutout binding | manual replacement, certified mockup and existing deterministic renderer/independent review tests |
| Customer auth/consent/resource/correction boundary (current user-authorized extension) | `CustomerEditorWorkflow`, `CustomerEditorRepository`, operator-only VisualReview projection | `testCustomerEditorRestIsolationRevocationTamperingAndAutomaticFallback`; `testCustomerEditorStaleAndConcurrentUploadsCannotRetainArtifacts` |
| Responsive review/preview preference UI | `assets/customer-editor.js`, `.css`, shortcode markup | `node tests/browser/customer-editor.cjs`: three synthetic offline journeys at 390/768/1280px, positive transparent replacement, nonce, accessible controls, zoom/background, stale approval and unsafe-file rejection |

The WordPress fixtures exercise genuine database contracts, protected storage, independent actors and actual canonical artwork generation using synthetic artwork/supplier evidence. Browser fixtures verify UX only. Neither establishes artistic quality, legal rights, commercial approval or physical Printify certification.

## Remaining owner / operational evidence

- Establish each real customer's WordPress account/order binding and provide its hashed operator-approved access proof. No automatic Etsy identity migration or customer accounts are invented.
- Configure the approved isolated offline runtime/weights outside Git where this workflow will eventually run. Head extraction remains experimental; poor hair, ears and whiskers require correction and independent review. Existing provisional photo pilots and missing reference/rights/consent evidence remain unchanged.
- Supply licensed mockup base artwork with explicit customer-display rights and an approved display mapping for each supported exact supplier variant/panel. Perspective, curved and UV placement need their own approved specifications.
- Supply current authoritative supplier catalog/geometry snapshots, independently reviewed cutouts/reference mattes and actual artistic/print/supplier certification. All fifteen MISSING commercial fixture statuses and twelve MISSING pilot cases remain unchanged.
- Existing release permissions, artifact access, published-environment revision evidence, finance dependencies and deployment authorization are unchanged. Overall final-product acceptance remains INCOMPLETE.

## Consolidated local verification — 12 October 2026

- Legacy foundation/Product Factory/Digital Factory suites: PASS.
- PHP unit: 1,278 tests / 7,657 assertions, one existing optional-model test skipped in CI-compatible configuration.
- Fresh-database WordPress suite: 453 tests / 5,641 assertions, two optional-model tests skipped in CI-compatible configuration. Intentional negative database/audit outage diagnostics appear in the log; the suite passed.
- New customer real offline matting test: PASS, one test / 23 assertions with actual pinned SAM Small + PyMatting. PHPUnit elapsed 14.853 seconds; this is test elapsed time, not an isolated model runtime/RAM benchmark. The resulting soft-alpha draft, original/result hashes, idempotent replay and uncertified/blocked preview state were checked. Existing public-domain benchmark fixture was reused; no new commercial fixture certification was claimed.
- Offline regressions: 106 tests PASS. Browser: three desktop/tablet/mobile journeys PASS, including positive transparent replacement and decoded PNG display. Browser fixture-contract tests PASS.
- Required PHP syntax, static, standards, security/controlled-HTTP scans and diff checks: PASS. Additional editor static analysis with actual local WordPress/repository symbols: PASS.
- Composer strict validation and installed-dependency security advisory audit: PASS (no advisories/abandoned dependencies). Both pinned offline processor and SAM/matting published-dependency advisory audits: PASS.
- Independent security review findings (operator reference projection; rejected-upload storage growth) fixed and regression-tested. Final targeted review found no remaining concrete security defect.

The model-enabled combined local suite exceeded Composer's default five-minute process limit without a reported assertion failure. The complete CI-compatible suite and separate focused real-model path above are the completed evidence; the timed-out attempt is not claimed as PASS. Remote exact-head Engineering & Safety Audit remains mandatory before accepting this candidate. Packaging/audit results are recorded on the PR and in the task report after publishing; no self-referential commit hash is fabricated here.
