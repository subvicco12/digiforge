# Offline qualification — 2026-11-10

Baseline: e14554733ab3bab88bc22d177d3e2be5d891dd7f. No production runtime changes, no installation package, no deployment or provider request.

- Node 24 / SQLite 3.53: 59 tests passed, including PHP Ed25519 parity, concurrent quota/one-use enforcement, emergency drain, timeout, state outage, restart and rollback fixtures.
- PHP unit: 1,575 tests, 8,758 assertions passed.
- Fresh isolated WordPress database: 534 tests, 5,112 assertions, two skips; passed. Docker network disabled; socket-only MariaDB and outbound HTTP denial.
- Offline Python: 132 tests, one skip; passed after supplying the fixture PHP executable in PATH.
- Three legacy PHP suites passed. Configured coding standard passed. JavaScript syntax, changed PHP syntax and artifact HTTP/secret gates passed.
- All 489 runtime file hashes match the baseline preservation manifest.

Two existing WordPress fixtures incorrectly coerced SHA-256 object IDs using WordPress's numeric object_id format. Explicit string formats repair the update/delete evidence and add an assertion that the intended audit row was actually deleted. Runtime authentication and authorization are unchanged. A reused integration database produced duplicate-record failures; that run was discarded and the entire suite rerun against a new empty isolated database.

Independent source review identified sender-request substitution and premature lease completion hazards during development; both were corrected with behavioral regressions. Independent review does not constitute infrastructure certification.

Critical qualification limit: restoring both SQLite and the synthetic independent witness revives consumed authority. The test intentionally reproduces this failure; it is NOT rollback-resistance certification. Production remains disabled without a genuinely independent recovery witness, mandatory exclusive sender custody, workload identity and separately approved infrastructure. Worker-code identity claims are not malicious-code attestation. No live Cloudflare durability, billing, namespace or credential-custody qualification occurred.

The source CI run and exact final source commit are recorded in the separate handoff receipt. Hosted source CI is not hosted exact-ZIP certification.
