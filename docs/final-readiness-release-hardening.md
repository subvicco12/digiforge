# FINAL READINESS & RELEASE HARDENING

## Objective
Complete DigiForge's production-readiness layer after Batch 10 without enabling any live external automation. This batch verifies that the platform can be packaged, reviewed, deployed, and operated safely while Etsy, POD providers, AI execution, fulfillment, payments/refunds, tax/GST filing, workers, schedules, and every other external side effect remain disabled.

## Safety boundary
- STOP ALL must remain active and fail-closed.
- `automation_armed` must remain false and non-user-writable.
- No external HTTP execution may be introduced.
- No Etsy publish/draft API call, POD submission, AI execution, fulfillment, shipping, refund, payout, payment, tax/GST filing, accounting sync, ad mutation, worker execution, cron dispatch, or webhook processing may be activated.
- Production credentials may be inventoried only through non-secret metadata; secret values must never be returned or logged.
- Any canary is a dry-run readiness simulation only and cannot invoke a provider.
- Emergency-stop testing is local verification only and must not alter the current safe state.
- Explicit owner approval for live activation remains NOT GRANTED in this release.

## Release version
- Plugin version: 0.11.0.
- Database schema remains v13; this batch adds no database tables and no schema migration.

## Production readiness review
The readiness report must deterministically check:
1. installed schema equals the expected schema;
2. STOP ALL is active;
3. automation is unarmed;
4. every externally actionable switch is effectively disabled;
5. the queue scheduler remains inert;
6. no production integration is enabled for execution;
7. integration credential checks use metadata only and expose no plaintext;
8. finance retention/recovery safeguards remain available;
9. release package/version metadata is internally consistent;
10. live activation approval remains false.

Any failed requirement returns `REVIEW_REQUIRED`. There is no automatic path from readiness to activation.

## Credential and environment verification
- Enumerate integration provider/environment/status/enabled state through the existing public integration registry only.
- Never return ciphertext, plaintext credentials, API keys, tokens, passwords, client secrets, or authorization headers.
- Production connections may be configured for future use but must remain disabled while this release is locked.
- Sandbox/test/production records remain isolated.

## Least-privilege verification
- Verify the DigiForge administrator capabilities required for local management are declared.
- Readiness cannot grant, widen, or mutate roles/capabilities.
- No public/anonymous activation endpoint is added.

## Canary dry run
Provide a deterministic canary plan for Etsy, Printify, Gelato, AI, fulfillment, finance/tax, and publishing domains. Every canary result must be `BLOCKED_BY_STOP_ALL` or `NOT_ACTIVATED`. The canary must not enqueue jobs, make HTTP calls, mutate provider state, or change safety settings.

## Emergency-stop drill
Provide a local deterministic drill that verifies:
- STOP ALL is on;
- automation remains unarmed;
- all externally actionable switches resolve disabled;
- the scheduler implementation is inert;
- readiness does not expose an activation mutation.

The drill returns an evidence hash and does not change settings.

## REST/admin surfaces
Add authenticated local-only surfaces for:
- release-readiness report;
- canary dry-run report;
- emergency-stop drill report.

These are read-only. No endpoint may arm automation, disable STOP ALL, store credentials, execute jobs, call providers, or approve live activation.

## Tests
Hosted tests must verify:
- version 0.11.0 with schema v13 unchanged;
- deterministic readiness result/hash;
- fail-closed result when STOP ALL is not asserted in supplied context;
- canary results are non-executing and blocked;
- emergency-stop drill is deterministic and non-mutating;
- live activation approval remains false;
- no external HTTP clients;
- scheduler remains inert;
- PHP syntax, PHPUnit, PHPStan, PHPCS;
- WordPress/MariaDB tests remain green;
- reproducible ZIP/checksum/package boundaries.

## Exit criteria
The exact final PR head must pass the complete hosted Engineering & Safety Audit, including WordPress/MariaDB integration tests and package verification. No unresolved P0/P1/P2 issue affecting correctness or safety may remain.

Merging this batch completes the DigiForge build and release-readiness layer only. It does not authorize or activate Etsy, Printify, Gelato, AI, fulfillment, payments/refunds, tax/GST, accounting, ads, workers, schedules, webhooks, publishing, or any other external side effect.
