Catalog and geometry fixture recovery (offline only)

Source tests: portal-access e957ba, unchanged originals retained outside this overlay.
Runtime: exact qualified .17 extracted package; no runtime validation changes.

- All four catalog read-model failures originated from fabricated parent headers with no actual catalog children. The positive V2 header fixture also lacked 500 children and source migration metadata. Complete local normalized parent and V2 service ingestion now establish canonical row hashes, reconstruction fingerprints, lineage and ordered migration metadata. Negative overrides remain after valid persistence, so they reach the intended V2 conflict checks. A new missing-parent-child test proves rejection remains enforced.
- Catalog persistence previously supplied an invented fingerprint and only source_dg_id lineage. It now uses service-produced canonical rows and migration metadata while retaining the original GovernedCatalogRepository replay and changed-parent conflict assertions. The temporary preparation version is removed before testing the unique fingerprint boundary. Header insertion checks now query the committed header rather than relying on rows_affected after COMMIT.
- Four preflight tests attempted VALIDATED Printify geometry without persisted provider bytes, source digests, placeholder bindings, region, shop ownership or template-cycle policy. They now seed synthetic local variant snapshots, derive bindings through ProductionTemplateContract, and seed a local policy/owner. The original preflight, route mismatch, readiness drift, dry-run and permit assertions remain. A new unbound-geometry test asserts runtime still rejects caller-only dimensions.

Existing patterns reused:
- digiforge-cloud/tests/support/MasterCatalogStoredEvidenceFixture.php (normalized complete V1 persistence and explicit cleanup because catalog transactions commit).
- digiforge-cloud/tests/support/MasterCatalogCandidateFixture.php (complete 500-row local V2 master/migration shape, 428 retained plus additions).
- digiforge-cloud/tests/wordpress/Support/PrintifyTemplateFixture.php and PrintifyPreflightTest.php (synthetic variant persistence, derived bindings and local template policy).
- digiforge-printify-api/tests/fixtures/PrintifyGeometryFixture.php (independent confirmation of transport provenance and explicit region).

Helpers are embedded in overlay files; no dependency on these other worktrees remains. Synthetic evidence demonstrates contract behavior only; it does not certify original workbook bytes or actual supplier data. No live network calls, credentials, installs, commits or publishing are involved.
