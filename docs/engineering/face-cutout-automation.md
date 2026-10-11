# FACE_CUTOUT selected offline segmentation

Base: audited PR #1163 at `37ed377e26ff32a2d767085a42dc1d3425de4434`, stacked on #1162/#1161. Fetched main: `844107ab9baf7e1303cd47708e9eab3bc700b348`. Ancestor branches and finance #1147/recovery workspace remain untouched.

## Source scope

Blueprint v6.0 sections 12–14 and the existing original-source inventory remain authoritative. Section 13's core engine paragraph is `word/document.xml/p89`. Existing `personalized-design-family-support.tsv` maps FACE_REPEAT candidates DG2-001–020 (socks), DG2-361–370 (leggings), DG2-371–384 (pajamas). Engine compatibility is not supplier or catalog approval.

The starter pack has 20 concepts, of which 19 compile supported local recipes. PT04 Painterly Pet Keepsake requires an actual approved PET_PORTRAIT artistic transformation. Background segmentation does not implement that transformation. FR01–FR08 are separate repeat-layout families.

## Working software and limits

`OfflineCutoutPipeline` provides a replaceable versioned boundary, implemented by the fixed local `offline/segment.py` worker: `selected-grabcut-v1`, OpenCV 4.12.0, NumPy 2.2.6. No learned weights, head detector, provider credentials, API calls or spending are used. A replacement algorithm must introduce a new reviewed version and preserve the deployed immutable worker. Its SHA is bound to receipts; code drift blocks old receipts rather than silently changing them.

An authorized operator supplies 1–8 candidate subject rectangles, one selected index and 1–16 foreground-preservation seed points. Five deterministic GrabCut iterations separate actual pixels within the selected rectangle. Only connected components containing selected seeds survive. Output is binary alpha; background is actually removed. This is **operator-selected automatic segmentation**, not automatic semantic head detection. Seed pixels are preserved, but full facial anatomy, hair, ears, whiskers, fine translucency, touching subjects and complex backgrounds require visual review/manual fallback.

Pillow 12.3.0 fully decodes bounded PNG/JPEG, applies EXIF orientation 1–8 and strips metadata before canonical PNG retention. Selection coordinates refer to the normalized orientation. Animated images, unsupported modes/formats, unknown orientation, unreviewed ICC profiles, malformed pixels and expansion limits fail closed. The original upload's internally computed hash/byte count is retained; its metadata-bearing body is discarded after staging. Actual normalized source, generated mask and styled result are retained in protected content-addressed shop storage. Original-byte replay is not claimed.

The administrator configures an absolute `DIGIFORGE_CUTOUT_PYTHON` interpreter with the exact packages in `includes/POD/offline/requirements.txt`; optional `DIGIFORGE_CUTOUT_PYTHONPATH` is trusted server configuration. Requests cannot select an executable/model/path. Missing runtime, mismatched packages or processing failure produces `customer_cutout_segmentation_unavailable`, requiring separately reviewed alpha fallback. No runtime installation/download occurs. Capability metadata distinguishes configuration from readiness verified during execution.

## Intake, security and review

`POST /digiforge/v1/pod/customer-artwork/segment` accepts exactly `shop_key`, `key`, `order_id`, `source_base64`, `rights`, `style`, `options`, `selection`. Native WordPress REST authentication/CSRF and `manage_digiforge_pod` apply. Scope must be personalized_pod and an existing matching order. Current protected rights/consent/retention documents are verified before processing. Explicit other-shop evidence namespaces are rejected. `rights.segmentation_method` is `offline_selected_grabcut`; ordinary manual intake cannot forge automatic provenance.

Limits: 4,500,000 decoded upload bytes; one static image; 1,048,576 pixels; strictly typed bounded rectangles/seeds/options. The fixed worker receives only bounded bytes via stdin, with no shell interpolation. It has one processing thread, fixed RNG seed, 12 CPU seconds, 2 GiB address space, zero file-output/core allowance and an 18-second parent deadline. Streams are bounded; sensitive stderr is discarded. Temporary files are removed on failure and success.

Immutable evidence binds normalization/input digest, retained source/mask/result pixels, selected subject/seeds, adapter/dependency version, worker SHA and segmentation statistics. Existing ingestion audit, current rights, artifact bytes and style reconstruction are checked on read/render. Replays are exact; changed inputs conflict. Existing order/result approval-rebind prevention remains active.

Generated cutouts are REVIEW_REQUIRED. Automatic results need the six existing negative flags plus `blurred_features=false` and `missing_hair_ears_whiskers=false`. Selection-boundary contact prevents approval; revise the selection or use separately reviewed alpha. No automatic artistic/blur/halo certification is invented. Existing actual-alpha, mask-size, transparency, padding, clipping and style-work checks apply to CS01–CS06.

Approved results enter the existing canonical FACE_REPEAT recipe and SVG/PNG/PDF/preview pipeline. Supplier variant/placement identity, current authoritative geometry, seams, safe zones and effective resolution remain required. No dimensions are invented and no geometry drift is waived. Commercial/supplier certification and external authorization remain false. No background queue/live automation is enabled.

## Behavioral evidence

| Requirement | Code | Behavioral test |
|---|---|---|
| Selected-subject local background removal, repeatability, component isolation | OfflineCutoutPipeline.php; offline/segment.py | tests/offline/test_face_segmentation.py; tests/unit/OfflineCutoutPipelineTest.php |
| Full decode, orientation, metadata removal, unsupported input failure | normalizePhoto / normalize_photo | JPEG orientation and negative animation/profile/orientation/format tests in test_face_segmentation.py; retained source assertions in WordPress test |
| Role/shop/order/rights scope, immutable replay and review | CustomerCutoutRepository.php; PersonalizedDesignWorkflow.php | RenderArtifactCertificationTest::testOfflineSegmentationUploadIsScopedImmutableReviewGatedAndRendersCanonicalRepeat |
| Approved alpha/style/canonical design integration | DeterministicPersonalizationRenderer.php; existing CutoutStyleAdapter.php | Same WordPress test plus existing style, repeat, parity, supplier-drift and ownership tests |
| Missing processor failure | OfflineCutoutPipeline::worker | OfflineCutoutPipelineTest::testMissingRuntimeFailsClosedWithoutCallerMaskFallback uses valid selection and exact failure message |

`tests/fixtures/segmentation/manifest.json` pins actual upstream URLs, original/normalized photo hashes, rights excerpts and selected regions. Upstream documents the NASA Eileen Collins portrait as public domain and Chelsea the cat as CC0 by photographer Stefan van der Walt. These real photos test actual segmentation, not customer consent, ground-truth head accuracy, dog-specific quality, commercial artistic acceptance or supplier certification. The oriented JPEG is a synthetic plumbing fixture. Existing five-engine commercial fixture statuses remain all 15 MISSING.

Consolidated local validation passed: 1,265 unit tests / 7,609 assertions; 437 WordPress tests / 5,246 assertions on stable WordPress 7.1.3; 91 offline tests; legacy contracts; configured PHPStan/PHPCS. Local PHP is 8.4; mandatory CI tests the required PHP 8.3 candidate independently. Intentional negative-case database errors appear in passing WordPress logs.

The actual PyPI advisory lists for NumPy 2.2.6, OpenCV headless 4.12.0.88 and Pillow 12.3.0 currently contain zero advisories. Provisional Pillow 11.3.0 was rejected after advisories were observed and is not shipped. The matching installed Composer development dependency lock reports zero advisories/abandoned packages. CI additionally installs the pinned processor on Python 3.12 and audits its exact dependency versions before the existing mandatory PHP/offline/WordPress/security/package gates. Final exact-head audit evidence belongs to the final commit's GitHub checks, not an ancestor's audit.

## Remaining certification and unsupported cases

- No automatic semantic head detection or soft matting. A future local detector/matting adapter needs approved licensed weights and reproducible dog/pet/human ground-truth fixtures; none is invented/downloaded.
- Fine hair/whiskers, halos, blur, cropped anatomy, touching subjects and commercial quality require real visual evidence/manual review. ICC-managed or unsupported formats fail closed.
- PT04 remains dependent on a real approved PET_PORTRAIT artistic result; all 15 commercial five-engine fixtures remain MISSING.
- Supplier geometry/specifications, physical samples, licensed customer/font evidence and required independent visual review remain external certification inputs. Raster output above the existing one-megapixel capability fails closed.
- Finance #1147 and previously reported release/environment/permission/artifact blockers remain separate. No operational or merge permission is inferred.

STOP ALL ON; external safety lock ON; automation OFF. No merge, deployment, catalog promotion, live provider spending, Etsy/Printify production call or real order. Overall final-product acceptance remains INCOMPLETE.

Mandatory CI exposed generated-ledger drift and a nondeterministic audit-removal fixture: WordPress infers integer formatting for `object_id`, so a hexadecimal SHA with a large scientific-notation prefix can escape deletion. The generator now owns the new bounded evidence; the test uses explicit string formats and asserts deletion. A controlled `2e30...` SHA reproduces the old failure and passes the correction under PHP 8.3. Production approval/audit validation was not relaxed. The complete fresh-database local WordPress run passes all 437 cases.
