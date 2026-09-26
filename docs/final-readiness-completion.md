# FINAL READINESS — Completion, Release & Activation Guardrails

## Objective
Complete DigiForge as a production-ready local-first WordPress platform without enabling any live Etsy, POD, AI, fulfillment, payment, refund, tax/GST, accounting, advertising, worker, schedule, webhook, or other external side effect.

## Release posture
- Plugin release: 1.0.36.
- Database schema: v14.
- STOP ALL remains ON.
- `automation_armed` remains internal and non-user-writable.
- Add `activation_authorized`, internal and non-user-writable, default false.
- Effective automation requires all three conditions: `activation_authorized=true`, `automation_armed=true`, and `stop_all=false`, plus the relevant feature switch.
- No normal REST or admin endpoint may write either internal activation gate.

## Deterministic readiness report
Provide a local-only readiness report that checks at minimum:
- current schema equals expected schema
- STOP ALL is active
- activation authorization is false
- automation is unarmed
- no feature switch is effectively enabled
- audit health has no unresolved failure
- queue has no expired running leases
- operational retention policy is fail-closed
- recovery drill configuration is available

The report must expose only non-secret status metadata and return an overall state of `READY_LOCKED` only when the platform is healthy and externally locked.

## Admin and REST surfaces
- Add an authenticated `GET /digiforge/v1/readiness` endpoint.
- Extend the main DigiForge admin page with release version, schema version, lock state, readiness state, and component checks.
- No endpoint may activate external execution.

## Final verification
Hosted CI must cover:
- version 1.0.36 with schema v14
- internal activation gates are non-writable
- effective switches remain false while either internal gate is false or STOP ALL is true
- deterministic readiness report
- no external HTTP clients
- no secret exposure
- all prior WordPress/MariaDB migration tests remain green
- PHP syntax, PHPUnit, PHPStan and PHPCS
- deterministic ZIP/checksum/release manifest and package boundaries

## Completion boundary
Merging this release means DigiForge is code-complete, tested, packaged, documented and ready for controlled deployment. It does not authorize enabling integrations or external automation. Separate explicit activation authorization is required later.
