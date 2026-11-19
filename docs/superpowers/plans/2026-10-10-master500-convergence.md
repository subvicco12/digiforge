# Master 500 candidate convergence implementation plan

Intent: repair demonstrated non-finance seams, preserve immutable v1 and complete v2 dispositions, and never authorize promotion or external execution. User explicitly authorized autonomous implementation and reviewable PR publication.

1. Add mixed-candidate unit tests using documented distributions (428/37/35; 428/30/42 origins). Observe rejection of legitimate additions before changing code. Add invalid-disposition, identity, vocabulary, shape and authority cases.
2. Update MasterCatalogV2MigrationContract::normalize: validate IDs and bidirectional KEEP mapping; allow blank source only for known additions; allow blank successor only for MERGE/DOWNGRADE. Preserve all 500 migration rows. Retain valid legacy all-KEEP normalization compatibility without asserting raw workbook authenticity.
3. Update MasterCatalogV2IngestionService::ingest to revalidate normalized rows and fingerprint, map new concepts without invented lineage, and persist all migration rows in immutable metadata. Add WordPress database regression covering 500 persisted items, 72 additions, all 500 source decisions, exact replay and tamper rejection. No schema change or production migration.
4. Run complete local Composer tests, WordPress suite, syntax and offline tests. Request independent branch review. Commit/push coherent milestone; verify exact SHA via GitHub connector; open PR and confirm mandatory exact-head Engineering & Safety Audit.

Independent second branch: finish bin/validate-master500-v2.py review receipt with recommended-stage vocabulary, canonical normalized-row SHA-256 and structured row/column exceptions. Write failure-first tests and valid XLSX end-to-end fixtures, verify formatting changes preserve canonical digest while semantic changes alter it. Preserve raw SHA and existing status/errors output; no catalog promotion authority. Full local checks, independent review, exact-SHA verification and PR audit also required.

Review focus: forged normalized evidence; added-concept identity collisions; dropped MERGE/DOWNGRADE traceability; existing legacy compatibility; immutable replay; Unicode/JSON failures; formula cached values and ambiguous spreadsheet identities; no credentials or network execution.

Source limitation: original Blueprint v6.0 / v2 XLSX inaccessible. Ruling: use only documented distributions and existing repository contracts for bounded fixes; do not claim full source acceptance or rebind source hashes. Cost if wrong: original-source reconciliation may add constraints before merge. Finance deferred; no shared files between implementation branches.
