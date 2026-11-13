# Offline custody completion — 2026-11-13

Scope: PR1260 from f0ab0f74e2263e2bd7f7ce9c8003907524ce8f24. Engineering only;
GET-only, separate-plugin boundary preserved. No production installation, provider
calls, infrastructure/credential creation, deployment or owner approval.
STOP ALL/external lock ON, automation OFF, manual order approval, RELEASE HOLD.

## Reproduced defects and correction

The synthetic final sender previously allowed deferred custody to invoke its
physical callback after a deadline or revocation, replay that callback, or mutate
the request after witness inspection. A successful response could also be marked
OBSERVED after custody was revoked/rotated during headers or body handling.
These are important **offline reference-boundary** defects; no enabled production
sender or live exploit is asserted. Initial failing tests are retained in the
external evidence bundle rather than rewritten as passes.

The sender now snapshots and validates an immutable GET tuple, requires literal
true custody flags plus a positive safe-integer generation, captures that generation
for one send and rechecks it before authorization acquisition, at the one-use
physical callback, after headers, each body read and asynchronous rejection-body
cancellation. The existing authority-bound wrapper forwards generation unchanged.
No token value, provider response or private exception is returned in receipts.
A completed sender lifecycle permanently closes retained callbacks. The inner
authority-service wrapper rechecks custody and abort state after its own awaited
independent guard, directly before provider.fetch. Deadline/completion aborts and
cancels unread bodies without awaiting an uncooperative cancellation. Independent
review reproduced both initially uncovered cases; four further RED regressions
proved closure, with additional concurrent retry and body-cleanup cases.

A deadline retains an uncertain witness lease even when custody has not yet
called fetch. It returns UNKNOWN/no retry and never frees the slot based solely
on abort. A late callback cannot acquire authorization or fetch. This is a
conservative hold; there is no automatic release/rearm API. No cancellation of an
already admitted remote request is claimed.

## Executable evidence and remaining gates

| Capability | Status | Evidence / boundary |
|---|---|---|
| Deferred callback, generation/strict custody flags, one-use callback, pinned GET, minimized output | IMPLEMENTED / VERIFIED offline | 26 new focused tests; initial red14/15 and additional3/3 failures retained; final26/26 |
| Existing dispatcher reservation/CAS, signed authority, persistence fault/rollback/corruption, independent drain, quota/retry, restart fencing | IMPLEMENTED / VERIFIED offline | Full193 Node tests including the26 additions; no skips. Actual local SQLite, synthetic independent witness. Full-root rewind counterexample remains BLOCKED |
| Private append/fsync, inode-pinned shared/exclusive dispatch locks | IMPLEMENTED / VERIFIED locally | Existing16 PHP tests /79 assertions, including separate processes, replaced inode, corruption, exhaustion and symlink/hardlink rejection; not power-loss proof |
| WordPress DB rollback/write-failure and independent quarantine | IMPLEMENTED / prior VERIFIED | Reuse existing DURABLE-EMERGENCY-FENCE-QUALIFICATION and reviewed tests; unchanged runtime, no new full WP suite required locally |
| Hostinger filesystem/database persistence | PARTIAL / BLOCKED | Interface/local semantics only. Real host local append atomicity/fsync semantics, locking across all workers, mount/alias privacy and power-loss behavior UNKNOWN. No host probe performed |
| Atomic rename/directory fsync | NOT_IMPLEMENTED as a journal operation / BLOCKED for any future rotation | Existing journal deliberately never renames, truncates, initializes or resets. Do not infer rename/directory durability from append tests |
| Dual persistence failure | BLOCKED: HOST_FAIL_STOP_REQUIRED | Existing code cannot durably record a stop if both channels fail. Old generation can survive recovery; retain existing negative counterexample. Independent supervised fail-stop/rearm required; no successful software substitute |
| Live bounded credential custody/revocation | PARTIAL / BLOCKED | Synthetic generation contract now tested. No real credential store, usable token transfer, irrevocable host-copy invalidation or independent administration qualified |
| Future provider adapter | IMPLEMENTED reference / NOT_IMPLEMENTED live | Fixed GET allowlist and ProviderPort/SyntheticFinalSender contracts. All non-GET remain denied. Future mutations require separately reviewed contracts, not reusing this sender or toggling a flag |
| Companion1263 | VERIFIED static contract identity / BLOCKED operational interoperability | Current e31c20bd38dec08846ad99f5b474c4f78b6e8b18; native-core-port.v1.json SHA2566d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434. No companion/native source imported or modified |

Historical Hostinger flush=2 report is reused as prior evidence only, not a new
measurement. Existing flush-at-commit1 predicate remains unchanged. Missing or
unproven host facts do not grant execution. Source CI is not a hosted durability,
credential-custody or production readiness certificate.

## Companion / future adapter handoff (proposal, not interface mutation)

NativeCorePortInterface and OperationsCoreInterface remain the SAME native CORE
boundary, not a new transport or authority supplied by this separate plugin.
This offline dispatcher does not implement those PHP interfaces and is not mounted
as their fallback. Static contract identity does not prove end-to-end interoperability.

An independently qualified future custody adapter must expose literal Boolean
availability/exclusivity/copy-invalidation and a non-reused positive safe-integer
credential generation. Revocation/rotation must change generation before new
credential use; restoring old storage must not resurrect authority. `perform(fn)`
must own exclusive custody, invoke a callback at most once and surface rejection;
our boundary independently enforces one-use, deadline and pinned generation even
if an adapter defers or retries. It must never return a token to WordPress or logs.
Same-account flags and monotonic counters in rollbackable storage do not prove
independence. All-root recovery remains RECOVERY_BLOCKED.

Lane B can consume the defect/test receipt and propose a versioned port change
through Issue1252 if required. Do not copy this synthetic custody into native
CORE, advertise native write readiness or widen GET grammar. No change is requested
to the current companion JSON or interfaces in this sprint.

## Reproduce

Node24 (native SQLite), PHP>=8.3; no new runtime dependencies:

```
node --test --test-isolation=none contracts/cloudflare-dispatcher/test/custody-fencing.test.mjs
node --test --test-isolation=none contracts/cloudflare-dispatcher/test/*.test.mjs
php vendor/bin/phpunit -c phpunit.xml.dist --filter PrintifyAcceptanceEmergencyFenceTest
```

The initial full sandbox run had six PHP/Node subprocess failures/timeouts.
Re-running the same final source with subprocess execution available passed all193;
initial logs retained. An initial PHPStan parallel run could not bind its local
TCP worker socket in the sandbox; a serial --debug rerun is recorded separately.
The focused PHP bootstrap emits an existing `$brief` warning
in GovernedGenerationBoundaryContractTest:27; tests still report16/79, not a
warning-free whole-suite claim. No broad historical WordPress audit was repeated.
Exact-head hosted CI/run URLs and uploaded-byte hashes live in the final receipt.
