# Hostinger recovery transport contract

Verified 2026-09-30 against Hostinger's official documentation:
https://docs.hostinger.com/api-reference/endpoints/wordpress/installations/import-wordpress-website.md
https://developers.hostinger.com/openapi/openapi.json

POST /api/hosting/v1/accounts/{username}/websites/{domain}/wordpress/import
requires archive_path and sql_path, both relative to the target website root.
The connector's database_path is mapped to sql_path. SuccessEmptyResource only
promises a message. Neither a provider operation reference nor an Idempotency-Key
contract is documented. DigiForge therefore treats documented acceptance as
UNKNOWN / RECONCILIATION_REQUIRED, never as restore success.

The connector must bind artifact_evidence_hash (the exact evidence pair hash), database_backup_identifier and plugin_package_identifier
to the verified artifacts represented by its pre-uploaded staging files. Missing
bindings and unsafe paths refuse dispatch. The server-side credential vault is
read only at dispatch; provider error messages, bodies and arbitrary reference
fields are never returned as evidence.

STOP ALL and the external safety lock must both remain active, with activation_authorized=false and automation_armed=false checked independently. Only the exact
connector-approved HTTPS website root is permitted. The current site and known
DigiForge production domain are refused. Multiple enabled configured Hostinger
connectors fail closed rather than choosing an arbitrary account.

Before invoking a provider, orchestration atomically inserts a permanent global
recovery interlock and an operation-key claim containing provider identity,
artifact identities and exact evidence hash, target, UNKNOWN state and RECONCILIATION_REQUIRED dispatch
state. It persists and checks the active record before invoking the adapter.
The adapter requires the exact persisted claim, then inserts a second unique
provider dispatch record containing integration identity and a hash of the
exact endpoint and artifact request before HTTP. Plain insert-only database writes against unique option names prevent concurrent or repeated
claims. WordPress add_option uses an upsert and is deliberately excluded; reads bypass object caches. Ledger writes and reads use a separate database connection with autocommit and database identity verified on initialization, every use, and every reconnect, so caller rollback or a crash cannot erase a dispatched claim; the caller transaction is never committed or rolled back. No claim is removed or expired automatically, including claims abandoned
before HTTP. New keys cannot bypass the global interlock.

Any ambiguous, invalid or thrown provider result retains the pre-dispatch UNKNOWN
claim. Failure to persist a classified provider result also retains UNKNOWN.
Snapshot external_actions_performed becomes unknown (null) after a dispatch claim;
commerce_execution_authorized stays false. Acceptance never writes drill PASS
or RecoveryDrillEvidence. There is no automatic retry.

A future separately certified reconciliation procedure must verify the isolated
site's restore, health, schema, intended audited plugin/database pair and STOP ALL
state and retain the permanent operation history. Until then the global interlock
remains blocked. Do not manually delete claims, edit SQL, execute shell/WPCode,
or change governance to unlock it. Actual import requires explicit human
operation authorization; engineering tests intercept every HTTP request. Committed safety settings, exact evidence, connector uniqueness/configuration and vault ciphertext are checked independently of caller transactions.

## Defects reviewed for PR 702

- Audit 3559: invalid namespace separator; corrected in the incoming branch.
- Audit 3561: stale transport source assertion; corrected in the incoming branch.
- Audit 3562: unregistered external HTTP boundary; narrowly registered with
  endpoint, one-call, TLS, redirect, vault and durable-claim assertions.
- Incorrect database_path request field; corrected to documented sql_path.
- Unsupported provider reference/idempotency assumptions; removed.
- Non-atomic WordPress add_option upserts, dispatch, crash, concurrent replay, direct adapter and new-key retry gaps;
  permanent pre-dispatch claims and global interlock added.
- Post-dispatch write failure and thrown/raw provider errors; safe UNKNOWN state.
- STOP ALL incorrectly standing in for the independent safety lock, mutable same-ID artifact evidence, missing artifact bindings, unsafe/current/production
  targets, ambiguous connector selection, and invalid credentials; fail closed.
- Stale non-destructive class documentation and misleading external-action flag;
  corrected.
- Behavioral WP tests now cover documented acceptance, missing references,
  timeout, exceptions, reentry, replay, missing artifacts/credentials, disabled
  connectors, target refusal, pre/post-dispatch persistence, caller rollback, two overlapping claim workers, stale caches, ledger read failure and secret echoes.
