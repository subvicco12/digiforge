# Experimental FACE_REPEAT visual QA

Authority: Blueprint v6.0 §§12–14 and Master 500 v2; existing SAM Small + PyMatting architecture in #1165. This follow-on preserves the safety lock, STOP ALL and automation-off gates. It introduces no inference model, supplier execution, catalog activation or artistic approval.

## Measured software criteria

`CutoutVisualQa` fully decodes bounded canonical PNGs (at most 1,048,576 pixels). Measurements bind file/pixel hashes, actual canvas dimensions, nonzero-alpha bounding box, visible/soft-alpha counts, canvas-edge contact and horizontal/vertical alpha total variation. Empty foreground, no transparent background or any nonzero-alpha canvas-edge contact are critical failures; these enforce existing cutout padding rules, not newly invented artistic thresholds. Source/mask/styled-result provenance and deterministic reconstruction remain mandatory.

Optional exact-geometry whole-image or explicitly annotated hair/ears/fur/whisker/subject/background region comparisons calculate normalized mean absolute alpha error, mean squared alpha error and binary IoU at alpha 128. The 128 binarization defines that metric only; it is not an acceptance threshold. Region comparisons bound total sampled pixels and reject out-of-canvas coordinates; region labels are supplied annotations, not machine-identified semantic truth. Cropped-region canvas-edge contacts are descriptive, not whole-cutout rejection. Missing references return null, never zero. Even a byte-identical comparison is `UNREVIEWED_COMPARISON`, never certification. Total variation alone cannot diagnose halos, contamination, blurred hair or lost whiskers.

| Detail | Reproducible evidence needed | Current decision |
| --- | --- | --- |
| Human hair/head preservation | Licensed source, independently reviewed alpha matte and annotated hair/head regions; region alpha errors plus source/light/dark inspection | Reference matte and designated reviewer missing; manual review required |
| Dog/cat ears, fur and whiskers | Rights-cleared species cases including fine-detail regions, independently reviewed matte, negative clipping cases | Dog fixture and all reviewed mattes missing; clipped cat remains rejected |
| Multiple subjects | Rights-cleared source, explicit selected-subject prompts, reviewed matte distinguishing selected/excluded subjects | Real multi-subject fixture/reference missing; synthetic software test only |
| Soft edges/halos/color contamination | Actual foreground/mask/styled pixels, light/dark alpha composites, reviewed reference and operator defect findings | Composites implemented; no automated semantic/artistic PASS |
| Clipping/alpha integrity | Actual decoded pixels; visible foreground, real transparent background, zero canvas-edge contact; strict decode bounds | Mandatory fail-closed local gates |
| Print resolution/seams/safe zones | Current approved Printify blueprint/provider/variant/placement geometry, intended cutout scale, existing canonical renderer/geometry QA | Existing geometry gates retained; no inferred DPI or physical certification |

Numeric artistic tolerances for reference error, hair/whisker preservation or edge quality are not approved by this engineering change. Existing transformation Visual Acceptance Policy v1.2 is not silently extended into a segmentation accuracy policy.

## Operator evidence and recovery

Authenticated `POST /digiforge/v1/pod/customer-artwork/qa` accepts only `shop_key` and `key`. The repository rechecks role, stored order/shop scope, rights documents, ingestion audit, retained artifact hashes, segmentation provenance and styled-result reproduction. It returns computed QA and base64 light/dark PNG previews generated from those exact styled pixels. No caller metrics, references, model paths or review identities are accepted by this endpoint.

Approval still requires every existing defect flag to be explicitly false (eleven for experimental matting), including hair/ears/whiskers, blur, contamination, unnatural alpha and foreground color damage. The server adds a hash-bound QA receipt to immutable operator evidence. Canonical order rendering recomputes QA/preview hashes and checks the exact approval audit; altered measurements or bytes fail closed. Review remains `APPROVED_LOCAL_ONLY`, not commercial/supplier certification.

Pre-QA approval history is never overwritten. A fresh review is stored separately under `df_cutout_review_qa_v1_<cutout hash>`; old approvals remain preserved but cannot authorize current rendering until a fresh operator review binds QA. The same ingestion receipt and result index can be reused. Failed inference or critical QA requires corrected selection or a separately reviewed manual alpha mask, followed by the same QA/review gates.

## Fixture and reference manifest

`tests/fixtures/segmentation/visual-qa-manifest.json` records human, cat, dog and multi-subject coverage, exact source/license-document hashes, offline rights scope, customer consent limitations, reference hashes, claimed reviewers/timestamps, review-document hashes, semantic detail regions and missing supplier certification. `bin/check-cutout-qa-fixtures.py` verifies bounded regular local files, source/reference complete PNG decoding and equal geometry, structured claimed review evidence and in-canvas regions. Traversal, symlinks, tampering, fabricated fields on missing entries, invalid timezones and malformed references fail.

`SUPPLIED_UNVERIFIED` references may be inventoried after these checks but never count as reviewed references. `REVIEWED` is rejected: this offline manifest has no established owner-designated reviewer authority. Hashes and a declared name do not establish identity or approval. Real licensed reference mattes and authorized reviewer evidence must be supplied and independently accepted before certified-reference workflow can be enabled. All fifteen commercial transformation fixture statuses remain MISSING.

## Reproduction and measured coverage

Run `python3 bin/check-cutout-qa-fixtures.py` with PHP 8.3+ on PATH. Optional `php bin/measure-cutout-qa.php INPUT_DIR OUTPUT_DIR` checks retained predictions against the committed #1165 benchmark hashes, applies CS01 and writes measurements plus light/dark previews; weights are not required and remain outside Git. Both directories must already exist. Inputs are predictions, never ground truth.

Committed `face-cutout-visual-qa-report.json` reuses the actual audited cold/warm human predictions: one distinct real human, two exact-replay outputs, 20,535 visible mask pixels, 4,728 soft-alpha pixels and zero canvas boundary contacts. Human/cat sources have upstream rights evidence; dog/multi-subject source fixtures and all four reviewed reference mattes are missing. Accuracy, hair/whisker preservation and commercial quality therefore remain unmeasured. Prior cat boundary rejection is not a successful cat extraction. Synthetic metric/lifecycle tests establish software behavior only.

## Validation evidence

Full WordPress integration: 441 tests / 5,321 assertions passed, including real isolated SAM/matting intake, review and canonical rendering. Serial learned-model unit regression: 1 test / 7 assertions passed; an earlier full-suite model attempt failed closed at the worker boundary, demonstrating resource-sensitive availability, not a certified performance guarantee. CI without owner weights explicitly skips learned-model inference. Full unit suite: 1,278 tests / 7,657 assertions, with one explicitly skipped learned-model case when the SAM runtime is not supplied; the serial configured model case passed separately. Complete offline suite: 100 tests passed, including fixture tampering, missing/claimed review and reference geometry cases. New alpha/detail metric tests: 7 tests / 33 assertions passed. Existing portal layout fixtures and canonical family behavior are reused rather than regenerated. Static, standards, secret/controlled-transport scans and installed Composer/PyPI advisory checks passed; all 25 experimental pins have zero published PyPI advisories at validation time. Final exact-head GitHub Engineering & Safety Audit remains required.
