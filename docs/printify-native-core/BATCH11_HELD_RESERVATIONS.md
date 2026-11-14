# Native reservation continuation — local candidate evidence only

## Reconciled starting source

PR1264 advanced from d154c601b3c5ed0ec93eaaac268bc4bba26758f4 to9e9094097992453a8cb6ecddd7f936c3584eccef through c60928b4 and9e909409. Both commits were retained by fast-forward, not overwritten. Audit4969 failed three CLI-worker tests; the subsequent test-only salt repair addresses parent cached salts versus rolled-back generated salt options. Exact-head Audit4973 succeeded on9e909409: https://github.com/subvicco12/digiforge/actions/runs/43405375492 . This baseline success does not certify changed source.

Companion1263 reviewed at e31c20bd38dec08846ad99f5b474c4f78b6e8b18. Since ac3f61c1 only acceptance tests/docs changed; canonical spec/interfaces unchanged. Version1.0.0/spec SHA2566d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434. Companion runtime pin remains independently owned and unchanged; no aggregate GET_READ or operational capability added here.

## Implemented interfaces

`NativeHeldJournal::register(array $binding, string $candidateKey)` reuses actual SQL named lock, transaction, unique keys, sealed event history, audit anchors and postcommit readback. Initial insertion stores candidate claims only. Exact replay returns the **current** verified record; it does not append events, renew authority, reset cancellation/UNKNOWN, rearm a fence or return a cached successful dispatch. Different payload/approval/actor/key is rejected. `prepare()` retains its strict duplicate-denial behavior.

`NativeHeldJournal::transitionPinned(string $operationId, string $shop, int $actor, int $revision, string $eventHead, ?string $fence, string $action)` compares the expected current revision, complete event head and fence inside the existing transaction/row lock before append. Replay, wrong head/fence and terminal transitions deny. Existing `transition()` remains available with its original revision CAS; no existing consumer is silently reinterpreted. New pinned calls use the stronger exact-head boundary.

Generation here is **local** record revision/head, not the canonical independent credential or revocation generation. Registration is **candidate reconciliation**, not the canonical native REGISTER/CLAIM acknowledgement. All outputs preserve approval_verified=false, dispatch_permitted=false, independent_checkpoint_verified=false. No native exchange, sender, callback, job, route, nonce exception, migration, lifecycle registration or credential access added. STOP ALL and external lock may coexist with local evidence persistence, but do not admit provider execution. Exact replay requires current actor/session/module/shop authorization just as initial persistence does.

## Durability evidence and limits

Two independent PHP registrars produce one stored immutable generation/event (a busy contender may deny). Two independent pinned fence claimants produce exactly one winner. Lost commit acknowledgement is UNKNOWN; an explicit exact registration reconciliation can read the persisted current candidate, but never dispatch. Existing abrupt precommit exit rolls back, postcommit lost acknowledgement preserves the consumed local fence, SQL/readback/audit failures deny. Tests use real disposable InnoDB tables, public synthetic test salts and signed synthetic shop authority only.

The retained all-root rollback counterexample is intentional: restoring journal/events/audit roots can revive local candidate state. No local hash, option, STOP boolean, WP salt, TTL or monotonically numbered row fixes that trust boundary. No positive hosted power-loss/independent recovery certification is claimed.

## Exact remaining prerequisites

| Boundary | Existing code reused | Still missing; interface stays disabled |
| --- | --- | --- |
| Operational mutation grant | Current signed session/module/shop; reviewed plan/policy/connection; seven governed GET joins | Canonical operation/product/order version ownership, current exact action approval, physical duplicate identity, independent epoch/revocation join |
| Native journal/reservation | SQL candidate insert/replay, row/head CAS, permanent local fence, UNKNOWN | Native atomic authority/nonce/receipt join and independently witnessed generation; no qualified execution lease |
| Credential custody | Existing core vault/transport remains exclusive in current paths; no secrets exported | Independently enforceable generation-aware leases, cached/in-flight revocation fence, fail-stop enforcement and drain-before-ack proof |
| Producer/receipt | Protected plan and core receipt-reference checks | Authenticated producer bound to actual dispatch fence/epoch/sequence/output, not caller hashes or fixture receipts |
| Workflow/allocation/publication | Existing approved mapping and reviewed fulfillment state, documented in Batch10 matrix | Canonical opaque identity index/version semantics, exact tracking-to-line quantities, snapshot/review CAS with current independent epochs |
| Recovery | Detect journal-only rollback with retained audit roots; preserve UNKNOWN | Separately governed durable authority outside coordinated rollback domain; repeated-STOP generation advance, crash-safe drain/restart reconciliation and host evidence |

No required independent storage/custody trust primitive is configured in this offline environment. Implementing a second database/option-based witness or a fake producer would violate the contract. Canonical mutation/publication/allocation/inspection/recovery remain typed denials. Missing supplier/media/pricing/geometry/routing remains held; no manual personalized-order approval is bypassed.

## Preservation

Only native held journal, its own tests/worker and native docs changed in this increment. Accepted POD1265 SHA8517778a47dfe9aaae26bd7dd48e9ef1c1f15ba8 and original ZIP72b63f31ba42fa43730d533c958d1c1faafb543641c78e6bf6ad4b412d822a8e retained unchanged. No separate API implementation, shared portal/Digital/scheduler/owner/schema/bootstrap/uninstall edits. Fresh changed-source exact-head audit and independent acceptance are required. No production install, migration, merge, provider call, order, publication or payment.

## Focused source verification

112 selected actual WordPress/native tests /1806 assertions, zero failures/errors, one source-build-only conditional skip (run again against extracted stamped artifact). Includes all25 journal SQL cases, current governed read grants and preserved native/order evidence tests.11 standalone tests/86assertions,601runtime PHP syntax checks, configured PHPStan/PHPCS and three legacy suites passed. New registration/head-pin tests were first run red (4failures/16assertions), then passed against real SQL. Expected AUDIT_WRITE_FAILED output belongs to deliberate failure injection. Independent read-only review found no concrete defect within this nonoperational increment. Final exact-head artifact/CI receipts are delivered separately; no result is inferred before completion.
