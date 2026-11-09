# Printify independent revocation and mandatory dispatch feasibility

Date: 2026-11-09. Starting source: `4116c56e4f239e5c280e0470cbff7231f3e789aa`.
PR1260 / feat/printify-api-completion-20261108.
Status: **KEEP ACCEPTANCE DISABLED**. Offline protocol only, no operational authorization.

## Governing evidence

Revision 1 approved policy: https://drive.google.com/file/d/1bTuqXBhDArPXYHRsg65yQ-6A8Dr29YTE/view

Hostinger qualification, sanitized observations and failure matrix:
- https://drive.google.com/file/d/1eZmfiIiK8JiiUjFZmJZPOpsiULOODR-1/view
- https://drive.google.com/file/d/1obC4t-2UDE_NKk8Z6CPrp9nyAp2BkMG-/view
- https://drive.google.com/file/d/1IYx0o3NnT8Nmvr8N55kAk6o15i7WP9CW/view

The actual sanitized JSON was retrieved through authenticated Drive, materialized
and read. Production reports MariaDB 11.8.9, PHP 8.3.36, WordPress 7.1.4 and plugin
1.0.103.17. `innodb_flush_log_at_trx_commit=2` and `sync_binlog=0`. The current
quarantine requires flush=1. No predicate, production setting or database was
changed. A filesystem fence plus rollbackable database cannot establish an
independent monotonic authority or mandatory outbound dispatch.

The prior independently qualified report records POSIX/mysqlnd availability but
web-PHP exec/system/shell_exec/popen/proc_open disabled. Worker topology, filesystem
crash semantics, host fail-stop and coordinated restoration resistance remain
unqualified. Neither local fsync nor InnoDB alone proves those properties.

This task used read-only website metadata and infrastructure discovery. It did
not execute production PHP, SQL, diagnostics, provider requests or recovery work.

## Existing infrastructure findings

| Surface | Verified observation | Limit / disposition |
|---|---|---|
| Hostinger | Authenticated metadata lists active DigiForge WordPress site on existing account | No qualified mandatory outbound fence, worker supervisor or monotonic state API discovered. Existing supported metadata is not proof none can ever be offered. |
| DigiForge vault | Existing source retains encrypted provider custody, configured integration 3 remains disabled in provided evidence | No live credential read, transfer or token-account validation. Backups may retain usable credentials. |
| Cloudflare | Public Workers and SQLite Durable Objects contracts inspected | No callable account/Workers metadata tool available in this session. Installed skill is not an authenticated account connection. No project or account quota verified. |
| Neon | Authenticated organization metadata reports Free plan and four projects | All four belong to other projects; no DigiForge project. No database, roles, secrets or project data read. No resource reuse or creation. Remaining organization allocation is unknown. |
| GitHub Actions | Existing source-audit workflow supports isolated fixture tests and source packaging | Suitable for tests, not an always-on emergency authority or final provider dispatcher. No live intake/exact-ZIP audit claimed. |

## Architecture comparison

| Option | Independence / mandatory dispatch | Durability and fail-stop | Cost evidence | Complexity / approvals | Decision |
|---|---|---|---|---|---|
| A Hostinger only | Same restoration and application boundary; restored PHP can recover tokens and skip cooperative checks | Observed flush2 fails existing quarantine; host-level fail-stop/egress unverified | Existing hosting; incremental cost not quoted | Requires supported, independently qualified host enforcement; no settings changes authorized | BLOCKED; no safe relaxation |
| B external final sender + independent authority | Can be independent if it exclusively owns usable provider credentials, issuer, final sender and administration | Linearizable state, fail-closed outages and independent recovery epoch required; privileged restore remains a threat | Conditional $0 on suitable dedicated Free service within quotas; not allocated or cost-certified | Dedicated resource approval, secure connection, issuer/custody/egress and recovery controls, separate live approvals | Preferred logical architecture for future qualification, not deployed |
| C external database only | State outside host, but stale WordPress can omit check or send retained token directly | Database alone does not provide mandatory dispatch; PITR can rewind authority | Existing Neon Free plan verified, DigiForge allocation absent | Must add external final sender/custody/issuer; then effectively B | Insufficient alone |
| D remain disabled | No acceptance dispatch | Existing denials retained, no new durability claim | No new service cost | No infrastructure action | RECOMMENDED NOW |

A dedicated Cloudflare Worker with SQLite Durable Object is a plausible small
implementation of B, not a selected/approved live provider or certified service.
An equivalent service is acceptable only if it proves the same boundary. A
managed database plus isolated sender can also qualify, but adds components.

## Minimum security boundary

The actual outbound Printify request must be controlled by the independent
service, not by WordPress after receiving a Boolean permission. The service must
own: exclusive provider credential custody, frozen allowlist, exact identity
binding, independent owner-grant issuance, safety generation, one-use request
slots, final dispatch, minimization and audit persistence.

Moving a copy of a token is insufficient. A future transition must either make
ALL previously usable Hostinger tokens unusable provider-side (including copies
recoverable from archives), or demonstrate a mandatory all-worker egress barrier
that even restored PHP cannot bypass. No such token revocation, transfer or
network change is authorized now. Hostinger must not hold authority to restore
external issuer keys or rewrite independent state.

An external database's connection credentials must never be held by rollbackable
WordPress with write authority. Owner approval must not be minted by a restored
WordPress secret alone. Existing Global Primary Owner identity stays unchanged;
this is service authorization, not owner reenrollment or a requirement that the
owner handle new private signing keys.

## Revocation protocol and limits

See `contracts/printify-authority/README.md` and `Authority.php` for executable
schemas and transitions. Every read binds generation, independent epoch, exact
owner/account/credential/shop/integration and server-pinned slot. A stop increases
generation even when already stopped. No rearm API is provided.

RESERVED consumes budget before any network attempt. Final ADMITTED is the
linearization point. Stop before ADMITTED denies sending; stop after ADMITTED
returns DRAIN_PENDING and the read outcome is UNKNOWN. DRAIN_ACKNOWLEDGED is only
possible with no admitted leases. Failed commit acknowledgments never permit a
retry or a durable-success claim. An orphan admitted lease is never automatically
expired or silently reconciled.

Authority state restoration needs a separate non-restored epoch witness and
restricted deployment/restore administration. Durable Objects explicitly provide
PITR; strong consistency is not unrewindability. Neon restoration has the same
logical concern. This model rejects a mismatched epoch but cannot detect a
privileged simultaneous rollback of code, state, signing keys AND its trusted
epoch configuration. Infrastructure qualification must close or explicitly bound
that threat before any acceptance authorization.

The abstract AtomicState and ProviderPort are intentionally unimplemented for
real infrastructure. Synthetic serialization is not cross-worker crash-durability
certification. There is no HTTP listener, provisioned secret, issuer enrollment,
state backend or runtime WordPress registration. No live bridge is enabled.

## Threat review

| Threat | Offline control | Remaining live evidence |
|---|---|---|
| Restored WordPress skips checks | External final sender rejects stale signed grants | Exclusive custody / non-bypassable egress and independent issuer not established |
| Authority restoration | Epoch and generation checked before final admission | Independent recovery witness and restore permissions unqualified |
| Stop race | Atomic RESERVED/ADMITTED/TERMINAL; pending drain; UNKNOWN | Real serializable backend and worker fencing required |
| Duplicate/ACK loss | Consumed nonce+slot, no retry, UNKNOWN terminal failures | Durable commit semantics under host crash need qualification |
| Proxy/SSRF/write attempt | Frozen fixed GET grammar, no caller URL/headers/body | Real sender must pin TLS host and prohibit redirects |
| PII/credential leakage | Type-only observations, no raw response or exception text | Real custody/transport and retained receipt access review |
| Audit corruption/outage | Signed hash chain verified; fail closed malformed state | External anchoring, retention and authenticated receipt retrieval unimplemented |
| Quota bypass | Account-wide atomic rolling budget and one-use slots | Distributed concurrency tests for selected adapter required |
| Recovered issuer key | Different read/stop domains; external issuer assumed | Issuer custody/admin boundary and emergency availability unqualified |

## Verified public cost contracts, not a spending authorization

Official sources read 2026-11-09:
- https://developers.cloudflare.com/workers/platform/pricing/
- https://developers.cloudflare.com/durable-objects/platform/pricing/
- https://developers.cloudflare.com/durable-objects/api/sqlite-storage-api/
- https://neon.com/pricing

Workers Free: 100,000 requests/day, 10 ms CPU/invocation. SQLite Durable Objects
Free: 100,000 requests/day, 13,000 GB-s/day, 5 million rows read/day, 100,000 rows
written/day, 5 GB total storage. Public paid Workers minimum $5/month plus usage.
Crypto, chain verification and serialization must be benchmarked within CPU and
storage limits before claiming Free suitability. Free quota exhaustion must deny,
never trigger an unapproved paid upgrade.

Neon public Free: $0, up to 100 projects, 100 CU-hours/month/project, 1 GB storage
per project and 5 GB outbound transfer per project. Actual account allocation,
Functions eligibility/region and spare capacity are not independently verified.
The existing four non-DigiForge projects are not approved resources for this task.

The $0 future estimate is conditional; no new cost, resource or billing change was
incurred, and no assurance of production SLA or zero lifetime cost is given.

## Exact next decision

Keep the bridge disabled. If pursuing B, authorize a dedicated DigiForge-only
metadata connection and resource scope first (for example secure Cloudflare
account authorization). Do not approve implementation deployment merely from this
model. Then qualify free capacity, independent issuer/recovery epoch, exclusive
custody transition, real atomic state and mandatory transport in a separate
review. Credential transfer/revocation, policy activation, production installation,
Shop2 binding, integration changes and live GET execution each remain separate
ungranted approvals. Shop3 is deferred; provider account/scopes remain UNKNOWN.

No new installation candidate is produced: production plugin/runtime files remain
byte-identical to the starting commit. The deliverable is a source-contract and
decision evidence bundle. Source CI package generation, if run, is not a new
installed-lineage qualification or hosted exact-ZIP acceptance.
