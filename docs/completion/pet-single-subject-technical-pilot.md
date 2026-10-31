# Pet single-subject technical input pilot

2026-10-31. Branch `integration/full-product-certified-20261031`, parent `82654d18bc29630e0a8e33305ca2cdb6bff5c060` (audit4834 PASS). Final exact engineering head/audit belongs in the GitHub run receipt and issue1209; this document does not attest to a future run.

The owner-supplied archive is 72,823 bytes, SHA-256 `30dcdc757b79b1191ae66aa49878fe0456d0f23151ec1ef2ac2b598eb05b49d2`. All70 manifest entries match; five schemas pass Draft2020-12 metaschema validation and all29 proposed profiles validate using local-only references. No attached program was executed. Exact member identities and hashes are in `pet-single-subject-input-provenance.json`. All15 supplied binary fixtures are procedural SYNTHETIC_TEST_ONLY; no licensed source/reference pair is supplied.

`PetSingleSubjectInput` pins the original720-byte pet_single_subject profile by hash. All21 IDs match the package mapping and exist in the unchanged Master500 source. The profile remains PROPOSED, geometry/fixtures MISSING, creative contract unset. The filtered21-row CSV is a derived review reference, not a new product registration, template assignment or catalog routing decision.

## Implemented and executable

- Optional `TransformationWorkflow::preparePetInput(shop,key)` reuses the existing authenticated, shop-scoped, locked, transaction/savepoint-backed and immutably audited lifecycle. An INPUT_VERIFIED PET_PORTRAIT job with one declared subject is required. Rights, exact current source/template/version and actor authorization are revalidated. Missing rights, foreign/revoked access, cancelled jobs and unsupported engine/state fail closed.
- Fully decode the existing supported PNG subset and losslessly reencode to the existing codec's RGBA8 representation. Preserve exact original bytes in the existing shop-scoped artifact store. Never resize, crop, apply a mask, render text, substitute glyphs, strip unsupported metadata or convert color profiles. JPEG/HEIC/profile-bearing/ancillary/malformed PNG and over-limit inputs are rejected.
- Receipt binds original/prepared/pixel hashes, byte lengths, dimensions, decoder/preparer/zlib versions and job-limit hash. Prepared artifact and receipt are immutable journal fields; reads reconstruct/compare both bytes and receipt. Backend changes fail closed and require explicit version/evidence handling, not silent receipt regeneration.
- Retain the1,048,576-pixel ceiling and explicit lower input/output/time/job bounds. Preparation is idempotent under the existing job lock. Audit failure rolls back the attempted journal step; retained content-addressed blobs are not destructively cleaned. Own transient temporary files are removed.
- State remains INPUT_VERIFIED. No result, artistic QA PASS, approval, cost certification, external execution or production authority is created. Two-shape synthetic input remains semantic subject count UNKNOWN; this is not a pet/species detector.
- Existing typed personalization schema validation is exercised without inventing pet fields or copying FC01's48-code-point policy. No arbitrary prompt, mask or crop field is added to the runtime contract. Existing stored-result import/review remains unchanged, including jobs without a preparation receipt.

## Verification and traceability

Behavioral RED: new unit cases failed because the preparation class was absent; three WordPress cases failed because the journal-bound step was absent. GREEN: codec/mime/bounds, exact21 mapping, original preservation, deterministic pixel identity, forged receipt/output drift, current typed text bounds; journal replay, missing rights, foreign/revoked access, artifact tampering, audit failure and retry. Full certification commands/results are recorded with the final exact-head audit; no synthetic test establishes provider or commercial acceptance.

Requirement `NF-personalization-engine-coverage`: add bounded implementation/test references only. Preserve all268 criterion classifications,22 UNVERIFIED non-Finance and23 incomplete Finance criteria. All500 source IDs,20 review waves and140 artistic-execution blockers remain unchanged. This work completes technical preparation, not21 artistic concepts.

## Remaining contracts and owner inputs

The package's ENGINE_CONTRACTS explicitly leaves photo/EXIF/ICC adaptation(T1), crop/mask/layout(T2), style execution(T3), multiple subjects(T4) and high resolution(T5) separately specified. No new composition semantics are authorized by a PROPOSED profile. To advance: provide exact reviewed style/configuration and representative licensed source/reference pairs, approved crop/mask/layout and text behavior if desired, independent artistic/linguistic review, and exact supplier/variant/placement geometry plus rights. No assumed DPI, safe area, bleed or full-size export is introduced.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No provider transport, paid AI, new credential, production operation, order, publication, merge or deployment. Engineering: bounded technical pilot only. Artistic/commercial/operational release: NO-GO.

## Retained-result preview stage — 2026-10-31 follow-up

Parent63bab8f13c1e18ff195211d6db186c054f1a9ebe/audit4835. Reuses input preparation unchanged. The packet's ENGINE_CONTRACTS preview rule specifies deriving preview from the retained result, not making a second artistic output. Added `preparePetPreview(shop,key)` to the existing journal; no new API, table, queue or renderer framework.

| Stage | Executable behavior and boundary |
| --- | --- |
| Original/input preparation | Existing protected original, bounded full PNG decode and lossless technical preparation; unchanged. |
| Artistic result acquisition | Existing exact rights-bound stored-result import. No portrait generator, mask, crop, style or provider execution added. |
| Review preview | New EXACT_RETAINED_PNG_BYTES derivation from that retained result, with a versioned immutable receipt. Reuses the same content-addressed artifact without copying/resampling. Requires PET, prior preparation and GENERATED state. |
| Provenance/parity | Receipt binds job, original, preparation, request, template, personalization, attribution, result, preview and pixels. Reads rederive it and verify exact preview/result metadata. Current rights/template/source/authorization checks remain mandatory. |
| Bounds | Existing1,048,576-pixel ceiling, lower job pixel/output/time limits and complete PNG validation. No requested dimensions, scale, DPI, bleed or supplier claim is synthesized. |
| QA/review | Existing technical geometry QA and independent human review remain separate. Preview creation stays GENERATED and cannot request review before technical QA. Synthetic fixtures remain synthetic. |
| Production/art | BLOCKED: exact approved creative configurations, licensed source/reference pairs, mask/layout specifications if needed, independent artistic/linguistic review and provider geometry/rights. |

Reproduced malformed-input defect before editing: valid PNG chunks containing a valid zlib stream plus hidden trailing IDAT bytes were accepted by gzuncompress and silently discarded. Decoder now checks complete compressed-stream consumption and end status after its existing bounded decompression. Valid pixels/encodings and decoder metadata are unchanged; previously accepted malformed records now fail closed on reread. No input-preparation implementation was repeated.

Behavioral regression coverage: malformed compressed payload; immutable exact preview parity and original preservation; preparation prerequisite; foreign shop/revocation/tampering; cancellation; independent database-connection lock contention; failed audit rollback and exact retry; production-sized result denial. The three new preview regressions first failed because the stage was absent; the malformed PNG regression first failed because invalid bytes were accepted. Canonical record equality avoids treating JSON object-key order as a content change.

All prior acceptance classifications,21 proposed concept mappings,500 identities,20 waves and140 artistic blockers remain unchanged. No portrait art has been generated or certified. Final pushed SHA and terminal exact-head audit evidence are recorded on issue1209 after certification; this text does not attest to a future run. STOP ALL/external lock ON, automation OFF, RELEASE HOLD/NO-GO.
