# Pilot QA criteria — PROPOSED, NOT CERTIFIED

## Automated metrics (calculate only where suitable reviewed references exist)
- Source/reference dimension, orientation, channel-depth, hashes and provenance must match; otherwise **BLOCKED**.
- Foreground intersection-over-union and Dice for coarse segmentation; report per-case and by subject group.
- Mean absolute alpha error (MAE, normalized 0–1) against continuous-alpha matte; report alpha-band error separately.
- Boundary F-score or contour distance with declared tolerance and image scaling; never compare across image sizes without normalization.
- Unexpected image-border contact count and background leakage; inspect intended legitimate contacts separately.
- Selected vs unselected subject leakage for multi-subject cases.
- Effective print pixel density only when actual supplier variant geometry, crop and physical size are verified.

## Hard-fail criteria for the pilot
- Missing/invalid rights or required consent; missing reviewed reference matte for quantitative certification; missing independent reviewer evidence.
- Wrong subject, other person's/pet's face included, unsafe image, missing provenance or tampered evidence.
- Visibly cropped ears, severe hair/fur loss, missing critical whiskers where visible, obvious halos/holes/background debris, unapproved border clipping.
- Unverified supplier geometry or missing physical print evidence blocks supplier certification.

## Suggested provisional evaluation targets (require owner/reviewer approval before adoption)
- Coarse mask IoU >= 0.95, alpha MAE <= 0.05, boundary F-score >= 0.90, selected-subject contamination = 0, unapproved image-border contact = 0.
- These are **pilot proposals**, not blueprint-mandated, scientifically validated, or existing DigiForge certification thresholds. Passing numeric metrics never overrides critical visual defects or reviewer rejection.
- For each case, require side-by-side source, binary mask, alpha matte, light/dark composites and final FACE_REPEAT preview at intended scale.
- Require two distinct owner-designated reviewers where the applicable project certification policy demands two; no self-approval.

Final state is NOT CERTIFIED until all required approvals and supplier/physical checks are independently completed.
