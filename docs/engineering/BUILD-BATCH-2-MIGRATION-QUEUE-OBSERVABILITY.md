# BUILD BATCH 2 — Migration, Queue & Observability Hardening

## Outcome

Batch 2 hardens the database and job-intent boundary without enabling execution. Schema upgrades are ordered and resumable, job mutations use explicit legal transitions and compare-and-set updates, leases expire, retries are bounded, dead letters are terminal, and audit persistence failures become observable.

## Database migration baseline

- Schema version 5 adds queue lease ownership, lease expiry, next-attempt scheduling, maximum attempts, and dead-letter timestamps.
- Migration versions advance in order and preserve the last completed version.
- Schema failure records contain a stable error code and timestamp without SQL or sensitive context.
- WordPress integration tests run against MariaDB and verify the installed schema.

## Queue safety baseline

- New job intent remains BLOCKED.
- Worker dispatch remains inert.
- State changes require a legal transition from the expected current state.
- State transitions use compare-and-set SQL to reject concurrent stale updates.
- A lease has a validated owner and bounded expiry.
- Retry scheduling releases the lease and records a bounded next-attempt time.
- Exhausted attempts move eligible jobs to DEAD_LETTER.
- Terminal jobs cannot be restarted through the transition policy.

## Observability baseline

- Health snapshots report schema currency, effective automation lock, expired leases, dead-letter count, and audit persistence state.
- Audit write failures expose only a stable error code, event type, and timestamp.
- Audit contexts remain recursively redacted.

## Verification

- Legacy structural regression tests.
- PHPUnit migration-plan, job-state, lifecycle, configuration, and health tests.
- WordPress and MariaDB schema integration tests.
- PHPStan, PHPCS, PHP syntax, diff, credential-pattern, external-HTTP, and legacy-name gates.
- Deterministic production-package build and checksum verification.

## Safety

No worker, schedule, external connection, AI task, marketplace action, provider action, publishing, fulfillment, or deployment is enabled.
