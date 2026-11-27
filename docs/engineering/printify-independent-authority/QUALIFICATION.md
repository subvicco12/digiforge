# Qualification scope and review record

Starting source `4116c56e4f239e5c280e0470cbff7231f3e789aa`.

- New portable protocol: `contracts/printify-authority/Authority.php`.
- Contract/threat/port requirements: `contracts/printify-authority/README.md`.
- Synthetic test ports: `tests/fixtures/IndependentAuthorityFixture.php`.
- Behavioral/negative tests: `tests/unit/PrintifyIndependentAuthorityTest.php`.
- Infrastructure comparison and costs: `DECISION.md`.
- Fault cases and unqualified guarantees: `FAULT-MATRIX.md`.
- Runtime preservation: `PRESERVATION.json`.
- Packaging boundary: `.gitattributes` excludes `/contracts` from plugin exports.

The separate automated code reviewer identified corrupt claim/quota handling,
insufficient catalog-grant scope and non-integer stored expiry comparisons. These
were corrected and behavioral regressions added. Final re-review reported no
further important findings, while expressly retaining real-backend, custody,
issuer and recovery-epoch blockers. This is not independent human review.

Local qualification: 30 focused tests / 165 assertions, included in full unit
suite 1,575 tests / 8,758 assertions. Offline suite 97 tests, one skip. Configured
PHPStan and PHPCS passed; additional PHPStan level 5 over the new reference and
pinned GET contract passed. New PHP files linted. Existing HTTP-client and secret
source gates passed. Existing unit fixture undefined-variable/deprecation output
was observed; no test exclusion or source gate relaxation was added.

The host shell lacks PHP. Initial Python runs outside the container could not
launch their PHP subprocesses; the unchanged suite passed when run entirely
inside the existing PHP/Python container with `--network none`. The first local
WP launch also found no mysql CLI; the disposable local database was created via
PHP mysqli Unix socket instead. These are test-runtime corrections, not production
configuration changes.

WordPress integration and exact-head hosted audit outcomes are recorded in the
final digest-bound Drive handoff receipt and GitHub run, after they finish. No
hosted exact-ZIP certification is claimed. A source CI export is not a new
production-preserving installation candidate.

No runtime plugin, schema, credential, provider binding or owner authority was
changed. 489 runtime files are byte-identical to the starting source. No plugin
version increment or release-installation ZIP is warranted by this offline-only
change. No external dispatcher or database is provisioned. The acceptance bridge
remains non-executable, and production readiness is BLOCKED.
