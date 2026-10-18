# Master 500 convergence execution record

Plan: docs/superpowers/plans/2026-10-10-master500-convergence.md.

- Ruling: original v6.0 blueprint and v2 workbook are inaccessible. Use only issue #959 and existing documented contracts for bounded fixes; never certify complete original-source acceptance or change source hashes. Cost if wrong: additional original-source constraints may be required before merge.
- Ruling: retain valid legacy all-KEEP normalization compatibility while supporting mixed lineage. Raw workbook identity remains independently gated by existing source SHA and non-authorizing candidate state. Cost if wrong: original-source reconciliation may tighten distribution policy.
- Task 1 complete: failure-first tests reproduced rejection of additions and acceptance of unknown stage/disposition. Normalization now checks explicit origins, supplier/stage vocabulary and bidirectional KEEP lineage, preserving excluded source decisions.
- Task 2 complete: WordPress regressions reproduced incomplete lineage and unvalidated fingerprints. Persistence now revalidates normalization and persists all source rows in immutable metadata; 500 items and exact replay verified, altered decisions conflict.
- Verification: composer test passed: 1,156 tests / 7,358 assertions, PHPStan no errors, PHPCS exit 0. WordPress suite passed: 201 tests / 1,858 assertions on fresh disposable database.
- Fixture finding: reusing a previously exercised database produced eleven failures from durable fixture collisions; fresh database run passed. No production or original fixture database was changed.
- Final fresh-context review: no critical/important catalog findings. Independent offline review found physical-row and missing-field location issues; both received failure-first regression coverage and fixes on the separate branch.
- Existing PHP warnings and expected/known integration database-error output remain visible; passing results are not claims of warning-free execution.

Finance PR #1147 remains deferred and untouched. STOP ALL, external safety lock and automation OFF preserved by no runtime-control changes. No deployment, provider action, promotion, or merge authorized by this milestone.
