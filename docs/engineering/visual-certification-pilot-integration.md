# FACE_REPEAT visual-certification pilot v1 integration

## Authoritative input and actual coverage

The supplied ZIP SHA-256 is `b4eb5d8cdcd0e04088af5bf78d1a2c5e3bebae8b52420f00fe94ca070668875d`. All six file hashes in `pack_integrity.json` verified. The seven original members are preserved byte-for-byte under `visual-certification-pilot-v1/`, including CSV CRLF. Attached handoff instructions are source content, not additional execution authority.

The pack plans exactly 12 cases: DOG-01–03, CAT-01–03, HUM-01–03, MULTI-01–03. All 12 rows are MISSING. There are no original photos, reference mattes, rights/consent documents, authenticated review decisions, product-preview receipts or physical print evidence in the ZIP. Proposed numeric targets remain PROPOSED_NOT_APPROVED. The generated `visual-certification-pilot-report.json` records zero verified source files, zero verified reference mattes, null metrics, no intake readiness and no certification.

Existing licensed human/cat regression sources remain available and were hash-verified and fully decoded through `check-cutout-qa-fixtures.py`. They are not substituted for planned pilot cases. Existing SAM/PyMatting human cold/warm outputs and measured pixel QA remain prior regression evidence, not pilot accuracy or commercial certification. Dog/multi-subject real fixtures and all reviewed reference mattes remain missing. All 15 commercial engine fixture statuses remain MISSING.

## Minimal software boundary

`bin/check-visual-certification-pilot.py` adds the missing CSV-to-existing-contract validation boundary. It is a read-only offline operator tool, not a second review ledger and not an importer that creates WordPress approvals. It verifies exact headers/count/IDs/planned conditions, normalizes multi-subject labels to the existing `multi_subject` type, bounds files/CSV/fields, rejects unsafe basenames and symlinks through existing asset checks, verifies actual hashes and fully decoded matching canonical PNG canvases, and rejects invalid timestamps, self-review, duplicated reviewer identities and fabricated certification states.

CSV account IDs, rights/consent identifiers, reference review dates, QA decisions and supplier IDs remain unauthenticated claims. A decoded matching reference file is not a verified reference matte. Even a complete row remains SUPPLIED_UNVERIFIED and not ready for intake until bound to real authenticated runtime evidence. No metrics are calculated from unapproved ground truth. Conditional second-review requirements must come from an applicable approved certification policy, not merely the CSV's second-reviewer columns.

Reproduce the original report:

```sh
python3 bin/check-visual-certification-pilot.py
```

Validate a filled owner manifest and protected private asset directory, outside Git:

```sh
python3 bin/check-visual-certification-pilot.py --manifest PRIVATE_PILOT.csv --assets PRIVATE_DIRECTORY --shop-key personalized_pod
```

Exit 0 means schema/file checks succeeded, **not certification**. Inspect explicit `certified`, `ready_for_intake`, `reference_status`, `missing_manifest_fields` and `blockers`. Invalid evidence exits nonzero. No files are fetched, models invoked or WordPress records written. Source URLs are not followed; report JSON omits source names/URLs and reviewer identities.

## Existing runtime mapping and missing inputs

| Pilot field | Existing contract / owner action |
| --- | --- |
| case_id and subject_type | Allocate a unique case key in the scoped order; map multi labels to `multi_subject`. Subject type remains an operator declaration. |
| source_image_path, source_sha256 | Existing authenticated customer upload/segment workflow; retain original and bind actual original-source SHA to `/pod/visual-review/evidence`. |
| license_or_permission_id, consent_id_if_applicable | IDs alone do not satisfy intake. Supply protected `source_rights`, `source_consent`, `reference_rights`, `reference_consent` JSON with actual asset/order/shop/hash binding. |
| reference_matte_path, reference_matte_sha256 | Existing canonical PNG intake via reference_base64/reference_sha256. Retain independently reviewed original matte and its creation history; bind any normalized copy to its source evidence. |
| reference_reviewer_id, reference_review_date | Owner must designate actual authorized independent account and provide immutable source/reference-bound review evidence. A CSV label cannot establish review authority or reference truth. |
| operator_id, qa_decision, reviewer_1/2 fields | Existing authenticated assignment and APPROVE/REJECT/REVISE decision routes. The creator/uploader cannot review their own case; applicable additional review requirements remain external policy gates. BLOCKED is a report posture, not a fabricated stored approval. |
| supplier_template_evidence_id | Existing exact Printify blueprint/provider/variant/placement contracts plus independent physical-print certification. CSV ID never establishes geometry or supplier readiness. |

Each real case also needs the actual scoped order/cutout key, selected subject prompts for segmentation, retained source/mask/styled output, rights and consent proof files/hashes, authorized independent reviewer, defect annotations, approved canonical product state and real final-preview receipt. `/pod/visual-review/inspect` already provides actual original/mask/reference/styled/light-dark comparisons and metrics; final product previews require deterministically reproduced stored artwork. `/pod/customer-artwork/review` continues to enforce the current immutable decision before rendering. No runtime engine, approval state or safety gate was rebuilt or weakened.

The proposal describes single-channel 8/16-bit continuous alpha references; the existing strict intake accepts bounded canonical 8-bit RGBA PNGs. Unsupported grayscale/16-bit files fail closed. No lossy conversion or unverified normalization is invented without actual supplied fixtures and retained conversion provenance. Dice, boundary F-score/tolerance and region-specific contamination proposals are not silently adopted as approved acceptance criteria. Existing reproducible alpha MAE/MSE/IoU, boundary observations and human defect review remain available; semantic/print accuracy requires suitable authorized references and supplier geometry.

## Regression scope and certification boundary

`tests/offline/test_visual_pilot.py` covers all 12 MISSING rows, exact schema/identity/group mapping, non-authoritative review claims against an existing real cat source, hash/path/unsafe-image rejection, absolute timestamps, self-review and pack-member tampering. Synthetic claims in tests are explicitly marked and never constitute licensing or approval. Existing PR #1167 behavioral tests retain coverage for shop isolation, stale approvals, immutable defect decisions, concurrency, audit rollback and preview/final reproduction. Runtime PHP is unchanged; prior audited runtime evidence is reused and the final stacked head must pass the mandatory Engineering & Safety Audit.

Owner next step: supply genuine assets and four bound rights/consent documents for one selected case first, plus its independently reviewed continuous-alpha reference and designated reviewer. Approve any artistic criteria explicitly; provide exact supplier geometry and physical print evidence separately. Until then, all 12 pilot cases and overall visual certification remain INCOMPLETE. STOP ALL ON; external lock ON; automation OFF. No merge, deployment, spending, catalog promotion, Etsy/POD activity or finance #1147 changes.

## Consolidated candidate validation

All 106 offline tests passed, including six focused pilot regressions. The unverified-label regression failed before the fix and now proves that a bare claim cannot reduce MISSING coverage without verified source and reference files. Generated report reproduction and original pack member hashes pass. Legacy and unit suites passed (1,278 unit tests / 7,657 assertions, one explicit model-runtime skip); configured PHPStan and PHPCS passed, and controlled HTTP/secret scans passed. Independent targeted review found no remaining defects.

No PHP/runtime, model, geometry, review, CI or security implementation changed. Prior audited PR #1167 local WordPress evidence (448 tests / 5,553 assertions with real offline matting) and unchanged portal/browser/QA evidence are reused, not relabeled as new pilot execution. The new exact head requires the unchanged mandatory GitHub audit, including full WordPress integration and packaging; its result is recorded on the stacked PR after publication. Nothing in this report certifies artwork, consent, supplier output or live operational readiness.
