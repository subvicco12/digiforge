# Separately approved Printify catalog-GET safety exception

Current main: `844107ab9baf7e1303cd47708e9eab3bc700b348`. This new branch started from main and fast-forwarded the exact, independently audited PR #1171 commit `9ac1c81c3bd08b4458db1278dc80e8c3be7016ca`; it adds one dependent exception change. PR #1171's branch/head is untouched, as are finance #1147 and all prior engineering branches. Review/authorization order is #1171's foundation followed by this exception; no merge is performed by this task. The new PR targets main and retains the same foundation commit, rather than reimplementing or cherry-picking it under a duplicate SHA.

## Narrow policy

Only the existing `PrintifyCatalogReadRepository::read` pipeline may use this exception. The owner must separately approve one exact immutable `printify.catalog.read` grant. The exception is not a feature switch, a general token retriever, a capability-policy override or a mutation permit. No Settings, mutation interlocks, live execution transports or general Printify activation are changed.

A valid exception requires all three existing protected settings to be present with type `boolean` and exact values: `stop_all=true`, `automation_armed=false`, `activation_authorized=false`. Existing `Settings::safety_locked()` therefore remains true: the external safety lock stays ON. Missing/corrupt/unavailable settings fail closed instead of relying on defaults. Main-site URL/blog binding and WordPress `production` environment remain mandatory; no staging/recovery deployment or connection is performed.

The exception is valid only for the grant's fixed account, ACTIVE configured shop/program, integration/credential-metadata fingerprint, requests and remaining durable request budget. Its expiry must be strictly future, within one hour, and no later than the original grant expiry. Existing request-kind/ID/GET allowlists, response/time bounds, token protection, nonce requirements, quota, anti-replay and draft-only provenance are reused unchanged.

A grant alone still fails with `catalog_read_safety_locked` while protected. No exception is implicitly issued from a grant, a source-document approval or the presence of credentials. No approval is issued on any real installation during this task.

## Local owner approval and revocation

The authenticated owner must have `manage_options`, `manage_digiforge_connections` and `manage_digiforge_pod`. The recipient account retains its existing exact grant ownership and reader capabilities. Exception issuer permissions are rechecked at use; they can be different from the grant issuer, but both must remain authorized. The immutable exception binds the full grant SHA, site SHA and integration/shop binding SHA, and records issuer, time, approval-request SHA, `method=GET` and `mutation_authorized=false`.

New local evidence routes, all requiring `X-WP-Nonce` (`wp_rest`):

- `POST /digiforge/v1/printify/catalog-read/{grant_sha}/safety-exception`: owner-only, no query fields, 8 KiB body maximum, bounded `Idempotency-Key` header. Exact JSON keys below; unsupported keys/capabilities/mutation requests fail closed.
- `POST /digiforge/v1/printify/catalog-read/{grant_sha}/safety-exception/revoke`: owner-only, no body or query fields. Revocation is immutable and independent of the original grant.

Exact approval body:

```json
{
  "capability": "printify.catalog.read",
  "expires_at": "<absolute ISO timestamp with timezone; at most grant expiry>",
  "acknowledge_catalog_get_only": true,
  "acknowledge_no_mutations": true,
  "acknowledge_locks_remain_on": true
}
```

The placeholder is intentionally not an actual approval/expiry. An owner must supply a real future timestamp. All acknowledgements must be JSON boolean true, not strings or integers. This POST writes local approval evidence only; it performs no supplier call, activation, configuration change or credential decryption.

Grant-scoped named locks serialize exception approval, revoke, reservation and GET. InnoDB transactions atomically store approval/idempotency evidence and immutable audits. Conflicting/replayed approval, failed audit/commit, changed settings or incomplete evidence denies admission. Only one exception can ever be approved per grant; expiry/revocation cannot be renewed in place, reset request counters or reopen consumed keys. A fresh owner-reviewed grant and separate exception are required for another period.

Stored approval plus its exact audit anchor must agree. If an exception row is lost but its audit remains, it cannot be silently recreated or treated as ordinary authorization. A deleted revocation row with a remaining audit anchor still blocks reads. An existing expired/revoked/invalid exception never falls back to an ordinary unlocked grant, even if global settings are independently changed later.

Admission and grant identity are checked before reservation, again immediately before decryption, and after the bounded synthetic/real transport returns. A safety/issuer/integration/grant change during transport discards successful evidence; the reserved attempt remains consumed. Draft evidence and reservation receipts record `safety_exception_sha256`; evidence also identifies `read_admission_mode=CATALOG_GET_SAFETY_EXCEPTION`. No artwork, licensing, geometry, catalog promotion or production certification is implied. Historical draft evidence remains readable under its current original grant after independent exception revocation; that is read-only local audit visibility, never permission to fetch again.

## Exact owner activation steps (not executed)

After a separately authorized future release of the reviewed foundation and exception:

1. Define `DIGIFORGE_MAIN_SITE_URL` to the exact canonical main website `site_url()`. Verify it is the intended production installation, not staging/recovery. Keep STOP ALL ON, automation OFF, global activation unauthorized and external lock ON.
2. Select an existing CONFIGURED, explicitly enabled Printify integration with its already vaulted credential. Bind `config.catalog_read_scope` to the exact ACTIVE `business_id`, `store_id` and `product_program` as documented in #1171. Do not enable general Printify or any order/automation feature. Configuration must be explicitly owner reviewed; this task changes none of it.
3. Supply authentic blueprint/provider/variant IDs and the authorized recipient account. Create the narrow original grant through `POST .../grants` with nonce and unique approval key, explicit GET scopes, expiry and request budget. PP-001 and synthetic 101/2/202 are not real supplier identities.
4. Make the separate `POST .../{grant_sha}/safety-exception` approval above, using a distinct approval key. Record its returned SHA and expiry. Confirm all global locks remain protected; approval is not a read.
5. Only under a separate authorization to make actual provider calls, the exact recipient invokes `GET .../{grant_sha}?request_index=N` with nonce and a unique `Idempotency-Key`. This engineering task prohibits and performs no such live call. Fetch authentic blueprint/providers first only for approved blueprint IDs, then separately approve exact provider/variant scopes if discovery changes what is needed.
6. Review draft source evidence. Independently revoke the exception using its local revoke route when done, or let it expire; optionally revoke the original grant. Do not reuse consumed request keys or guess missing geometry/rights.

Current managed Codex networking does not allow `api.printify.com`; any later task authorized to call it from this environment also needs an explicit managed-network allowlist change. This task does not request or make that change. Main website network readiness is separate and unverified. Licensing/display assets, UV/placement maps, supplier bleed/safe zones/DPI and independent artistic/physical certification remain required for a real DG2-001 preview.

## Offline acceptance

Reuse all #1171 tests and evidence; add pure exception schema/expiry/capability/acknowledgement tests plus real isolated WordPress/database regressions. New cases cover separately approved GET under locks, all general feature/mutation interlocks remaining false, grant-only denial, no general credential/sync access, replay/quota, independent revocation/expiry/posture drift, cross-manager account and grant isolation, tampering/deleted approval, nonce/owner/REST method controls, in-flight settings/issuer changes, deleted revocation anchors, missing safety rows and failed approval audits. Dummy encrypted credentials and synthetic HTTP prove software behavior only. No licensed fixture, real provider permission, approval, response, geometry or commercial certification is manufactured.

Full exact-head Engineering & Safety Audit remains mandatory. No CI/security gate is weakened. Final SHA/audit evidence belongs to the published PR/report rather than a self-referential PASS inside this document.

Consolidated local candidate: 1,157 unit tests / 7,364 assertions; 215 WordPress tests / 2,082 assertions (16 scoped-read/exception tests / 242 assertions); 61 offline tests; configured plus WordPress-aware static analysis, standards, syntax/security/diff checks and installed Composer advisory audit PASS. Existing main/runtime diagnostic/deprecation output remained visible and no check was disabled. Independent read-only security review identified no serious exception defect; its requested in-flight/revocation regressions passed. This establishes local software behavior, not real owner activation/provider/geometry certification.
