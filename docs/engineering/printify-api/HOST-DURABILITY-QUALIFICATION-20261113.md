# Host durability and custody — offline qualification

Baseline PR1260 ceb93e5e67d771b8b6a97f5e7adf450dbd3b0227 / Audit4979 reused.
No change to489 runtime files, prior26 custody tests,193 dispatcher tests or16
PHP journal/lease tests. No FPM runtime is installed/qualified by this work.
PHP CLI worker processes exercise the filesystem assumptions shared with FPM;
this is local Linux process-crash evidence, NOT Hostinger/FPM/power-loss certification.
All real provider operations remain disabled. STOP ALL/external lock ON;
automation OFF; manual approval; RELEASE HOLD.

## Reproduced software defects fixed

- AuthorityJournal accepted structurally corrupt persisted roots: invalid generation,
  flags, duplicate/over-capacity leases, invalid nonce count/version/time. Strict
  bounded validation now runs on initialization, reads and before transaction commit.
  Generation exhaustion cannot commit an unsafe integer or admit active authority.
- Signed custody could expire during response headers/body and still yield OBSERVED.
  Completion now rechecks independently verified immutable custody/workload/auth
  expiry before accepting evidence. Expiry produces UNKNOWN/no retry and retains
  the independent unresolved lease. Tests expire only custody; workload proof remains
  valid. STOP's existing signed UNKNOWN completion remains functional.

## Executed local fault matrix

| Fault | Executed evidence | Interpretation |
|---|---|---|
| Partial journal append + SIGKILL | New PHP process snapshot denies poisoned bytes | No old authority silently accepted |
| Complete append/fsync + SIGKILL | New worker observes changed journal digest | Process termination only; not cache/power-loss proof |
| fsync returns false | Namespace interception in test-only child; invalidation denies | No successful durable-stop acknowledgement |
| Temp replacement before rename + SIGKILL | Original complete file remains | No event was published; no stop acknowledgement possible |
| Partial replacement published + SIGKILL | Fresh worker rejects malformed journal | Fail closed |
| Complete replacement published + SIGKILL | Fresh worker sees new digest | Does NOT certify rename/directory fsync durability; production journal never rotates |
| Replaced dispatch lock inode | Existing identity pin rejects drain | No replacement-lock bypass |
| Two shared PHP workers + emergency + kills | Intent changes immediately; exclusive drain denied until BOTH workers die | OS locks release on crash, not durable UNKNOWN resolution |
| SQLite worker killed before/after commit | Real local WAL file reopened: uncommitted lease absent / committed lease retained; restart fences | No automatic rearm; not InnoDB or hardware durability qualification |
| Persisted root corruption/exhaustion | All negative cases deny; failed transaction does not commit overflow | Structural validation, not cryptographic/root-administrator independence |
| Wrong lease identity / duplicate lease | Cannot release another id or duplicate acquisition | Existing fixed internal authority API; not actual secret custody certification |
| Generation stop with in-flight lease | Generation advances, lease retained, new acquisition denied | Empty-drain acknowledgement cannot be inferred |
| Custody expiry during headers/body | UNKNOWN, no retry, independent lease retained | Original provider request may already be in flight |
| DB-only or filesystem-only digest change | Standalone SQLite digest compared with real filesystem snapshot | MODEL divergence only; does NOT execute application database restore/admission |
| Every trusted root restored | Old digest/owner proof can revive in isolated counterexample | HOST_FAIL_STOP_REQUIRED / RECOVERY_BLOCKED, never a recovery PASS |

New coverage:18 Node cases +11 Python-driven PHP process cases. Final dispatcher
suite211 (prior193 retained); original PHP suite16/79 assertions retained.
Initial root-corruption11/12 failures, expiry2/2 failures, generation exhaustion
failure and initial signed-UNKNOWN regression are retained in external logs.
The regression was fixed by checking completion expiry independently of active
STOP admission, without changing the old test or clearing unresolved authority.

## Test-only injection and reproduction

`tests/offline/php_fence_fault_probe.php` intercepts unqualified namespace fsync/fwrite
in a dedicated child process. It is never autoloaded or exported in the plugin.
No runtime test hooks, permissions, journal format, host files or databases changed.
Each parent test owns a0700 temporary directory,0600 files and bounded child waits;
only its own children receive SIGKILL. SQLite is synthetic, no network/provider calls.

```
DIGIFORGE_TEST_PHP=php python3 -m unittest discover -s tests/offline -p test_php_fence_faults.py -v
node --test --test-isolation=none contracts/cloudflare-dispatcher/test/host-recovery.test.mjs
node --test --test-isolation=none contracts/cloudflare-dispatcher/test/*.test.mjs
php vendor/bin/phpunit -c phpunit.xml.dist --filter PrintifyAcceptanceEmergencyFenceTest
```

## Companion and remaining external gates

Read-only PR1263 e31c20bd38dec08846ad99f5b474c4f78b6e8b18 remains canonical
DF_NATIVE_CORE_PORT_1/v1.0.0. Exact JSON SHA256:
6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434.
NativeCorePortInterface/OperationsCoreInterface remain unchanged; no bridge, source
copy or new successful capability advertised. Current core_reference_sha702a4d07
is historical; static compatibility does not qualify Lane B's newer runtime.
Lane B receives hashes, proposed expiry/completion semantics and fault receipts;
no shared interface changes made. PR1264 and frozen1265 untouched.

Host evidence still missing: actual FPM SAPI/worker lifecycle, all mount/alias privacy,
filesystem type/append/flock behavior across all workers, real fsync/directory-sync
and atomic replacement semantics under power loss, InnoDB flush=1/local-autocommit
quarantine, matched database/filesystem failure and independently supervised host
fail-stop/rearm. No existing host predicate weakened, no hardware facts inferred.

Credential custody is SYNTHETIC_REFERENCE_ONLY: no real token store/migration,
exclusive administrator or backup-copy invalidation qualified. Lease ownership,
expiry/generation/revocation and UNKNOWN tests do not certify possession/custody.
All-root rollback and simultaneous storage failure require an independent,
non-restored recovery authority and host fail-stop; neither is manufactured here.
Exact commit, final CI URL, test totals and Drive checksum are in the final receipt.
