# Private synthetic custody bridge v1

This candidate is disabled by default. Its only positive path is the synthetic
order fixture. It has no Printify URL, credentials, sender, mutation route,
public ingress, scheduler, or hosted checkpoint. STOP ALL, external lock,
automation OFF, and RELEASE HOLD still govern standalone operational calls.

## Wire and state

The service binding exposes `exchange(wire)` for the exact compact ASCII
v0.15 PHP request JSON, up to 4096 bytes. Object order is checked explicitly.
The older Stage A signing envelope is purpose-specific and cannot be
reinterpreted as a PHP custody receipt. The bridge reuses Stage A WebCrypto
protocol helpers and egress seal, with a separately pinned synthetic signing
key and the unchanged PHP signature input `DF_PRINTIFY_CUSTODY_READ_RECEIPT_1`
plus LF plus exact receipt JSON. The sole accepted path is the bound shop's
`ORDER_READ` for `order-1`. Its typed fingerprint is recomputed.

The SQLite journal binds the complete scope and window, consumes exact
operation identity, and permits three successful fixture admissions in a
rolling minute and at most 24 recorded attempts in a window of at most 300
seconds. The two-second deadline bounds the local signing exchange. The
receipt expires within 30 seconds and no later than the approved window.
The signed 429 receipt has bounded retry guidance. The only other result
classes are non-authorizing typed DENIED, TIMEOUT, and UNKNOWN. Cancellation
or timeout cannot undo an attempted signature or rearm an operation. Pending
records on restart become UNKNOWN and fence further work. Concurrent request
reservations serialize before signing. In-flight revocation fences the
receipt, and revocation persists across restart of the same database. This
local journal gives no protection against coordinated restore of all roots.

## Offline acceptance

- `node --test --test-isolation=none contracts/cloudflare-dispatcher/test/custody-bridge.test.mjs`
- Existing disposable WordPress runner with the extracted standalone ZIP
  executes PHP → private stdio Node process → SQLite → Ed25519 → PHP. It
  checks the held operational path, forged proof and restart replay, with
  a WordPress HTTP tripwire. The process fixture uses public, test-only
  key material outside the deployment closure.

## Minimal hosted proposal

The supplied template is a separate, private-only candidate. It leaves the
frozen Stage A Worker, approved bootstrap branch and manifests intact.
The bridge needs one Worker and one SQLite Durable Object namespace with one
intended singleton, plus an approved private caller service binding. The
existing Stage A manual caller can be the integration target, but it does
not currently invoke this RPC. A new, explicitly reviewed routing revision
must bridge that caller to the PHP test runner without exposing a public
endpoint. The exact template ships with empty scope, no signer and
`DF_CUSTODY_MODE=DISABLED`. The account-specific admission and disabled
provisioning approval are separate from the bounded synthetic invocation
approval. No deployment or workflow run is implied by this source.

Future qualification should read back absent public routes and preview
URLs, pin the exact source, signer and scope, then approve a single fixture
GET. Observe actual CPU, row and request usage. After review, exercise wrong
scope/revision, signature forgery, expiry, revocation, rate limit, timeout
and restart with separately reviewed synthetic controls. A shared authority
or witness outage cannot be qualified by this single-domain fixture. A
lost acknowledgement or UNKNOWN stops the run. Retain evidence and restore
the disabled mode under separately approved teardown. No provider egress
or usable Printify credential belongs in this pilot.

## One-shot Stage A caller build

`stage-a-caller.template.json` is a separately reviewable version of the
existing private caller, for the custody smoke window only. It keeps the
existing Worker name, Workflow definition and Durable Object namespace
binding. Legacy Stage A invocations are denied in this caller version.
The frozen Stage A source, bootstrap and artifacts are preserved. The
caller uses the isolated object name `digiforge-stage-a-custody-caller-v1`
within the existing caller namespace and reuses the frozen CallerJournal.
It never resets or reads the existing Stage A suite's singleton journal.

The only admitted Workflow payload consists of `schema`
(`DF_STAGE_A_CUSTODY_READ_1`), `approval`, and `wire`; the exact instance is
`stage-a-custody-` followed by the pinned 64-character operation identity.
Configure the caller with the exact wire digest, approval identity, shop,
account, opaque fixture credential reference, source SHA/revision,
authorization revision, signer public key, operation ID and approved
start/end. The bridge uses the same scope, key and window. No key material
or enabled configuration ships. The caller permits a window up to 120
seconds, one pinned operation and one dispatch attempt. It verifies the
returned digest, signer, scope, body and freshness independently before
returning a receipt. A signed rate denial is accepted as evidence, with
no retry. The outer Workflow step is five seconds with zero retries.
The private caller and bridge both use two-second deadlines.

### Bounded hosted procedure — separate authorization required

1. Confirm approved exact source and configuration, effective account
   permissions, Free headroom and independent approval. If existing Stage A
   caller resources are absent, the one-Worker/one-namespace incremental plan
   is insufficient. Obtain separate approval for that dependency. The caller
   overlay assumes the namespace and Workflow definition exist. Both
   packages deny HTTP. Confirm both public URL flags, absent routes, disabled
   modes, exact service binding and absence of schedules by readback.
2. For one approved synthetic invocation, capture one compact PHP GET intent
   in the isolated fixture, pin its exact bytes and digest, approve the exact
   Workflow instance, configure the synthetic signer and bounded window, and
   manually initiate once. Check signer pin, timestamps, request and response
   digests, typed receipt and read-only PHP projection. The source tests use
   private stdio for the PHP transport. A hosted PHP invocation/readback
   harness still needs approval and Cloudflare timing acceptance. If it
   cannot satisfy two seconds or the receipt lifetime, stop and retain UNKNOWN.
3. Before the positive run, verify disabled, wrong instance, altered scope
   and expired approval denials. After the single receipt, check duplicate
   denial with the same identities. Use explicitly approved disposable cases
   for revocation and timeout. They must fence spent or uncertain records
   across restart and yield no second dispatch. Do not replay a timed-out
   intent or treat a new operation ID as recovery. An actual Cloudflare
   restart or configuration change requires separate fault authorization.
4. Incremental resources for the smoke case are one private Worker, one
   SQLite DO namespace and its one lazy singleton, plus a new version of
   the existing caller using the existing Workflow and caller namespace.
   Expected happy path uses one manually initiated Workflow instance and
   one step, one caller DO invocation, one private bridge Worker RPC and
   one bridge DO invocation. Both journals are bounded. Measure CPU, SQL
   rows and storage; totals, retention overhead and costs are unmeasured.
   Preserve evidence, close the window, return both modes to disabled,
   and tear down only with separate exact-resource approval.

No provider execution, external credential custody or independent witness
qualification follows from this fixture run.
