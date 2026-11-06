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
