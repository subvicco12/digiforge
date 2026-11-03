# DigiForge FACE_REPEAT Visual Certification — Pilot v1.0

**Scope:** 12 proposed cases; all evidence is **MISSING**. This pack supplies templates and procedures only, not licensed images, reviewed mattes, approval, or print certification.

## Contents
- `pilot_fixture_manifest.csv`: one row per planned case; fill all evidence fields.
- `reference_matte_instructions.md`: preparation of reviewed continuous alpha references.
- `qa_acceptance_criteria.md`: proposed pilot criteria (not owner-approved thresholds).
- `reviewer_checklist.md`: independent review process and decision rules.
- `codex_handoff.md`: focused implementation/ingestion prompt.

## Sequence
1. Obtain rights-cleared originals and verifiable permissions/consents.
2. Hash each original; store private assets outside Git, with shop isolation.
3. Have a qualified annotator create continuous-alpha references; have an independent reviewer inspect and sign them.
4. Run PR #1167 intake and PR #1165 segmentation/refinement only in a permitted isolated environment.
5. Measure QA, generate light/dark and product previews, collect independent decisions.
6. Reject/return cases with missing evidence or critical defects. Supplier certification is a separate manual gate.

**Governance:** STOP ALL ON, external lock ON, automation OFF. No merge, deployment, provider spending, catalog promotion or production orders.
