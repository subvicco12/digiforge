# BUILD BATCH 1 — Engineering & Safety Baseline

## Outcome

Batch 1 makes the repository fail-closed and establishes reproducible quality and packaging controls. It does not implement or activate integrations.

## Controls

- `automation_armed` is an internal, non-writable setting that defaults to false.
- `stop_all` defaults to true on new installations.
- Effective automation requires an explicit future arming mechanism, STOP ALL off, and the individual switch on.
- Ordinary REST settings cannot arm automation.
- Unknown settings, incorrect types, and coercive boolean strings are rejected.

## Engineering gates

- existing fast structural regression tests;
- PHPUnit policy tests;
- PHPStan static analysis;
- PHPCS baseline;
- PHP syntax linting;
- legacy-name, credential-pattern, and external-HTTP scans;
- reproducible production ZIP and SHA-256 checksum;
- audited CI artifact retention.

## WordPress integration-test foundation

`phpunit.wordpress.xml.dist` and `tests/wordpress/bootstrap.php` integrate with the official WordPress PHPUnit library through `WP_TESTS_DIR`. These tests are intentionally separate from the fast default suite until the CI database fixture is added in Batch 2.

## Safety

Workers remain inert. No schedules, external connections, Etsy actions, POD actions, AI jobs, publishing, fulfillment, or deployment are enabled.
