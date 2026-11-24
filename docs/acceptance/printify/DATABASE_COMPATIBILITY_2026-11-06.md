# Installed-lineage database compatibility — 2026-11-06

Decision: offline snapshot compatibility PASS; production installation BLOCKED pending the new candidate's release/recovery acceptance and separate installation approval.

## Protected evidence and scope

The owner attachment was materialized privately, not assumed available. Export size: 3,953,280 bytes; SHA-256 `af3f3f5d6f58ce31deed3e7d0cef8b76e856e10d834c67a37580f3abf29fc280`. No dump, production records, keys or private database configuration are committed or packaged. The SQL was parsed and screened before execution: 500 statements, 129 tables, no executable routines/triggers/client commands or file/network operations. Local MariaDB 11.8.6 restored the export from MariaDB 11.8.9; runtime PHP 8.3. The disposable container has network mode `none`; MariaDB listens only on a private Unix socket. WordPress bootstrap and DDL occurred only in isolated local fixtures, never production.

Preserved inputs:

- Installed 1.0.103 baseline: `2f651b5ad7ada503267256e2ba4ef1ef1000e706a1e4e4a13a20082784eb086e`.
- Existing 1.0.103.1 ZIP: `aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1`, unchanged.
- Feature source: `602c3b048a8dddf2df2aaca852520ff39f7f9d43`.

## Schema reconciliation and correction

| Evidence | Result |
|---|---|
| Actual export markers | legacy `digiforge_db_version=23`; global `digiforge_db_schema_version=25`; provider `digiforge_provider_connection_schema=1` |
| All DigiForge tables | 98 tables, 1,146 columns, 392 named indexes match the fresh installed-lineage candidate fixture, including types, nullability, defaults, index order/uniqueness/prefixes and column collations |
| Component 23 / 24 | Both full contracts READY; core marker 23 is not the global migration target |
| Global 25 lineage | Installed baseline's `MigrationPlan::LATEST=25` and Etsy event schema retained; never replaced with the consumer branch's earlier core |
| Two normal candidate boots against restored owner copy | No DDL; markers unchanged; checksums and row counts unchanged across all 129 tables |
| Independent second database restore | All 129 table checksums and counts equal the untouched reference snapshot |
| Configuration and integration records | Preserved byte-for-byte in files and unchanged across all exported database tables; credentials are neither decrypted nor used |
| Current production freshness | UNVERIFIED: owner snapshot evidence, not a new live production observation |

The existing component migrators could write global 23 or 24 after higher migration 25 when component tables were absent. They also accepted an incomplete existing table by name alone. The correction retains their exact CREATE definitions, validates every required column/index plus InnoDB, denies incomplete or unreadable contracts without ALTER, and advances the uncached global marker with an atomic maximum and readback. Corrupt markers and failed writes deny. Higher global versions and the legacy core marker are preserved. Failure evidence is retained. Existing absent-table creation remains possible on boot; it is not needed for this verified snapshot. InnoDB marker writes can roll back; DDL is not represented as transactionally reversible.

Changed runtime files beyond the previous ZIP: `includes/Database/ComponentSchema.php` (new), `ScopedPolicySchema.php`, `CatalogAcceptanceSchema.php`, and the plugin header/version to 1.0.103.2. No other retained runtime file changes. This is a new candidate requiring a new digest-specific acceptance, not a certification of the old ZIP.

## Validation

- Schema failure/monotonicity regression: 12 tests, 54 assertions PASS. Red-before-fix run: 8 tests, 6 substantive failures.
- Installed-lineage WordPress feature/security suite: 26 tests, 204 assertions, 1 explicitly obsolete pipeline skip. Shop isolation, signed-authority denial, imports/exports, routes and real Drive STOP/lock predicates covered.
- Rate ledger: 3 tests, 56 assertions PASS; size-guide grants: 6 tests, 26 assertions PASS.
- Contracts: 42 checks; Drive boundary: 21 checks, zero transport calls.
- Reviewed inherited HTTP/secret policy PASS with existing exact-file pins; no new exception.
- PHP lint: 507 files PASS.

The Drive test initially lacked default rows in the new synthetic fixture; seeding defaults in that fixture corrected the setup. The rate fixture uses an explicit synthetic connection context in a separate PHP process. Neither fixture establishes production credentials or execution authority. No live supplier request was made. An automated second code reviewer found no important runtime defect; its builder-provenance hardening suggestion was applied. This is not independent human review.

## Packaging and acceptance gates

`bin/package-printify-schema-candidate.py` uses the two pinned archives, three committed runtime overlays and the explicit version bump. It checks the builder and overlay bytes against its recorded Git commit. The complete payload is composite installed lineage, not an exact checkout of PR #1253. The preservation manifest binds every member and input to SHA-256. Expected totals: 692 files; 669 original files identical, 9 intentional modifications, 14 additions, zero deletions. Existing production hooks, provider/security classes, settings and unrelated functionality remain retained.

| Gate | State | Minimum next action |
|---|---|---|
| Supplied SQL schema and database-only restore | PASS | Reuse this evidence if installation snapshot has not drifted |
| New exact-package qualification | Pending final package receipt | Verify the generated digest and extracted bytes; use final receipt |
| Full consistent recovery checkpoint and isolated site restore | BLOCKED | Authorize/capture a matching database, plugin, uploads/site files and protected configuration checkpoint; validate restoration without external access. SQL-only restoration above does not satisfy this |
| New candidate release acceptance | BLOCKED | Owner must accept the new digest and its evidence under repository governance; old digest-bound audit approval does not transfer |
| Hosted exact-artifact audit | NOT RUN | Existing workflow remains pinned to old digest; no policy bypass or fabricated CI PASS |
| Installation | BLOCKED | After recovery and release gates, approve installation separately; preserve all safety controls |

Do not install until these gates pass. Once separately authorized: verify the download checksum, take the accepted checkpoint, use WordPress Plugins → Add New → Upload Plugin and inspect the replacement comparison for `digiforge/digiforge.php` version 1.0.103.2. Stop on any schema/source drift or unreviewed migration. Installation can boot migration code and is not authorized by this engineering task. Recovery must restore the matching files and database, not merely switch ZIP versions. No merge, production deployment, live API request, grant or activation is authorized. STOP ALL ON, external safety lock ON, automation OFF and RELEASE HOLD remain required.

## Final exact-package receipt

Candidate: **DigiForge 1.0.103.2**, 3,062,782 bytes. SHA-256: `800a29e7bca92bcf76804747d4a23f15329b38606649d570df25e1f109fe7677`.

Correction/builder commit: `c46175e3a7246efc8edba02360e708d668f51797`. This commit, the pinned parent ZIP and baseline jointly establish provenance. Subsequent test/documentation commits do not alter this package. Every ZIP member was independently verified against the full manifest and the tested candidate, checked for unsafe paths, modes, unexpected private files and high-confidence secret signatures, then freshly extracted. The schema, installed-lineage WordPress, rate-ledger and inherited HTTP/secret-policy checks were rerun against those extracted bytes and passed. PHP lint and pure contract checks were reused only after all 692 payload files proved byte-identical to the tested staging tree. See `DATABASE_CANDIDATE_RECEIPT_2026-11-06.json` for digest-bound results and test-log checksums.

Local exact-package qualification: **PASS with one obsolete pipeline skip**. Full production installation: **BLOCKED**. Hosted exact-ZIP audit: **NOT RUN**. Source CI is separately recorded on PR #1253 and must not be represented as ZIP certification. First source CI run `42153297671` exposed two pre-existing literal-spacing contract assertions; their numeric checks were preserved with whitespace-tolerant assertions. No candidate bytes changed for that test correction.

The broader source audit `42153651616` additionally exposed regression-fixture isolation issues: WordPress rewrites CREATE to TEMPORARY tables, unavailable in MariaDB 10 information_schema; hardcoded marker restoration leaked global 25 into the source fixture (whose latest is 24). The tests now use explicitly cleaned-up physical disposable tables and restore each original marker. Two historical migration assertions now verify that direct V6-plus-component calls retain legacy 22 until the core migrator runs. This preserves the intended separation of global and legacy markers. A fresh isolated source migration/lifecycle regression passed: 47 tests, 415 assertions. Runtime payload and ZIP digest remain unchanged. A noncanonical broader local run was not accepted as release evidence because its blanket no-egress filter conflicts with connection mocks and its fork fixture lacks the CI environment; the canonical hosted source workflow supplies the full-suite decision.
