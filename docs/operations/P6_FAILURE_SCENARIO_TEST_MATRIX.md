# DigiForge P6 — Failure Scenario Test Matrix

## Purpose
This document defines the failure-scenario validation required before DigiForge can be considered ready for controlled production use. The objective is to prove that failures are contained, observable, auditable, and fail closed.

## Mandatory Safety Preconditions
Before any P6 execution:
- STOP ALL = ON.
- Activation authorization = OFF.
- Automation armed = FALSE.
- All effective external switches = FALSE.
- external_actions_performed = false.
- No Etsy publishing or marketplace mutation.
- No Printify/Gelato live orders.
- No AI provider execution.
- No order fulfillment execution.
- No refund/cancel automation.
- No banking/payment action.
- No GST/tax authority call.
- No live webhook processing.
- No external scheduled worker activation.

Live-site execution remains gated on completion of the genuine production database backup and successful recovery readiness.

## Pass Criteria
A scenario passes only when all of the following hold:
1. The failure is detected and surfaced clearly.
2. No unsafe external side effect occurs.
3. STOP ALL remains ON.
4. Activation authorization remains OFF.
5. Automation remains unarmed.
6. Relevant audit evidence is produced when applicable.
7. Queued work remains recoverable, retryable, rejected, or dead-lettered according to policy.
8. System state remains internally consistent.
9. Readiness does not incorrectly report a safer state than reality.

## Failure Scenarios

| ID | Scenario | Injection / Trigger | Expected Behavior | Evidence Required |
|---|---|---|---|---|
| P6-01 | Invalid entity data | Missing required fields, invalid enum/state, impossible references | Request rejected; no partial record; validation error returned | Error response + no unintended DB mutation |
| P6-02 | Malformed REST payload | Invalid JSON, wrong types, oversized/unsupported fields | Controlled 4xx response; no fatal error; no side effect | REST response + audit/error log where applicable |
| P6-03 | Duplicate idempotency key | Replay same mutation with same idempotency key | Original result reused or duplicate safely rejected; no duplicate entity/action | Idempotency record + single resulting mutation |
| P6-04 | Conflicting idempotency reuse | Same key reused with materially different payload | Request rejected as conflict; original state preserved | Conflict response + unchanged original record |
| P6-05 | Expired queue lease | Simulate work item whose lease expires before completion | Item becomes eligible for controlled recovery/retry; no concurrent double execution | Queue state + lease timestamps + audit evidence |
| P6-06 | Duplicate queue workers | Competing lease attempts for same work item | Only one worker owns active lease; second cannot execute same job | Lease ownership evidence |
| P6-07 | Missing integration credentials | Attempt provider path without configured credential | Fail closed before external call | Credential lookup failure + zero external request evidence |
| P6-08 | Invalid/revoked credentials | Provider mock returns authentication failure | Error captured; credential not exposed; no retry storm | Sanitized error + retry/dead-letter state |
| P6-09 | Unauthorized WordPress user | User lacking DigiForge capability calls admin/REST action | 401/403 or wp_die; no mutation | Permission response + unchanged target state |
| P6-10 | Capability boundary mismatch | User has one module capability but not another | Access restricted to authorized area only | Capability test result |
| P6-11 | External provider unavailable | Mock timeout/5xx/unreachable provider | Controlled failure; retry policy observed; no state falsely marked complete | Provider error + retry/dead-letter evidence |
| P6-12 | External provider slow response | Simulated timeout beyond configured threshold | Request fails safely; lease/state not left falsely successful | Timeout evidence + queue state |
| P6-13 | Interrupted queue processing | Abort worker after lease acquisition but before completion | Work remains recoverable after lease expiry; no duplicate side effect | Lease + retry evidence |
| P6-14 | Dead-letter threshold reached | Repeated deterministic failures | Item moves to dead-letter/attention state per policy; retries stop | Attempt count + final state |
| P6-15 | Database write failure | Simulated write error/transaction failure | No logically partial workflow progression | Error evidence + consistency verification |
| P6-16 | Migration failure | Schema migration interrupted or invalid | Activation/readiness fails closed; expected schema mismatch visible; automation remains locked | Schema status + readiness result |
| P6-17 | Plugin update mismatch | Plugin code version incompatible with DB/schema | System reports review/unhealthy; no external activation | Version/schema evidence |
| P6-18 | Plugin update recovery | Restore prior audited compatible plugin package | Compatible state restored without disabling STOP ALL | Package/checksum/schema/readiness evidence |
| P6-19 | Recovery evidence missing | Remove/withhold required recovery evidence in test fixture | Recovery readiness becomes REVIEW_REQUIRED | Recovery report |
| P6-20 | Audit subsystem failure | Simulate inability to persist/query audit evidence | Health/readiness degrades; unsafe operations remain blocked | Health status + readiness check |
| P6-21 | Queue query failure | Simulate queue table/query failure | Health becomes unhealthy/review required; no unsafe continuation | Health snapshot |
| P6-22 | Corrupt/unexpected state transition | Attempt forbidden workflow transition | Transition rejected; state unchanged | Error + entity state |
| P6-23 | Referential dependency violation | Attempt archive/delete/update that would break dependent records | Operation rejected or safely constrained according to repository policy | Dependency result |
| P6-24 | Missing product artifact | Listing/order flow references unavailable artifact | Downstream step blocked; no publish/fulfillment action | Validation result |
| P6-25 | Mock order invalid data | Missing SKU/product mapping, invalid quantity/currency | Order remains invalid/review state; fulfillment not planned/executed | Order state + validation evidence |
| P6-26 | Finance input inconsistency | Unsupported currency/invalid amount/tax classification gap | Ledger mutation rejected or held for review; no fabricated financial result | Finance error/review evidence |
| P6-27 | FX snapshot unavailable | Required conversion snapshot absent | Calculation held/rejected according to fail-closed policy | Missing-FX evidence |
| P6-28 | Emergency STOP ALL during internal workflow | Toggle/fixture asserts STOP ALL while work is queued/in progress | Effective external execution remains blocked immediately; internal records remain inspectable | STOP ALL state + no external action evidence |
| P6-29 | Activation authorization absent | Attempt effective feature execution while authorization false | Execution blocked regardless of individual switch state | Gate evaluation evidence |
| P6-30 | Automation unarmed | Attempt execution while automation_armed=false | Execution blocked | Gate evaluation evidence |
| P6-31 | Single switch accidentally true while STOP ALL on | Test fixture sets one feature switch true with STOP ALL true | Effective switch remains false/blocked; no external request | Effective switch report |
| P6-32 | External-action sentinel | Run complete P6 suite | external_actions_performed must remain false | Final readiness report |

## Execution Order
Run tests in this order to reduce risk:
1. Pure validation and authorization failures.
2. Idempotency/state-transition failures.
3. Queue/lease failures.
4. Integration/provider mocked failures.
5. Database/schema/update/recovery failures in an isolated test fixture.
6. Emergency STOP ALL and effective-gating tests.
7. Final global safety verification.

## Final Safety Verification
After the P6 suite, verify all of the following again:
- STOP ALL = ON.
- activation_authorized = false.
- automation_armed = false.
- every effective external feature switch = false.
- externally_locked = true.
- external_actions_performed = false.
- schema current = expected.
- audit healthy unless a test intentionally leaves the fixture degraded.
- queue query healthy and no unintended expired leases remain.
- no live Etsy, Printify, Gelato, AI, fulfillment, financial, tax, webhook, or scheduled-worker side effect occurred.

## Production Execution Gate
Do not run destructive recovery or failure injection against production merely to satisfy this matrix. Prefer unit/integration fixtures and isolated restoration environments. Any production-side validation must be read-only or safely reversible and must occur only after the production database backup and P1 recovery readiness are complete.
