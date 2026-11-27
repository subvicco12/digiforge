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

### Disabled first-create and asynchronous handoff

Run `python3 contracts/cloudflare-dispatcher/custody-bridge/build_first_create.py EXACT_SHA OUTPUT_DIR`.
The first-create caller archive adds the frozen class's initial SQLite migration. Use it
only on a fresh target. The older caller overlay omits the migration for an existing
namespace. The bridge archive defines its own SQLite class. The caller
has the sole private service binding, to the exact bridge Worker, and carries the
manual Workflow definition. Both manifests are disabled, route-free, and have empty
scope. No Workflow instance or signer is created by the builder.

After separately recorded account selection, current Free eligibility and quota
headroom, effective permissions, independent approval and exact digests:

1. Approve disabled provisioning only. Use the reviewed atomic-private creation
   mechanism to create both Workers with public URL flags false before uploading
   code. Install the disabled bridge first, then the disabled caller; use the ZIP
   manifests exactly. Read back script IDs, deployment versions, disabled URL
   settings, empty routes, two SQLite class migrations, one Workflow definition,
   and the caller's exact bridge binding. Stop on ambiguous creation, any public
   exposure, or a configuration mismatch. Preserve the protected bootstrap and
   its existing rulesets. The package does not authorize a general Wrangler
   first upload or validate Cloudflare's atomic API behavior.
2. On the authorized disposable local WordPress fixture, prepare one intent:
   `wp --path=LOCAL_WP --user=FIXTURE_OPERATOR eval-file standalone/printify-api/tools/review-hosted-custody.php prepare < scope.json > intent.json`.
   Supply the local shop, account reference, opaque fixture credential reference,
   exact source SHA and revisions, and the synthetic signer's public key in the
   scope envelope. Keep the prepared operation ID and request digest locally.
3. Obtain separate approval for the exact request bytes, Workflow instance,
   synthetic signer, deployment versions and a window of at most 120 seconds.
   Pin the caller config and bridge scope to that evidence. Manually create
   exactly one `digiforge-stage-a-manual` instance with the generated
   `stage-a-custody-…` ID and payload `DF_STAGE_A_CUSTODY_READ_1` containing the
   separately approved identity and compact wire. No provider egress or token.
4. Read the same Workflow instance via the authorized private Cloudflare
   management plane. Export only `{instance_id,status,output}`, translating the
   control-plane instance identifier to `instance_id` and preserving the
   exact signed record, signature, and body strings. Supply that export
   as a JSON string in the review input envelope with the original scope,
   operation ID and approved request digest. Run `wp --path=LOCAL_WP
   --user=FIXTURE_OPERATOR eval-file standalone/printify-api/tools/review-hosted-custody.php review < review.json`.
   The PHP importer does not contact Cloudflare. It verifies the signed evidence,
   not the control plane itself. Finish verification inside the original receipt
   freshness window. Missing or uncertain output is UNKNOWN. Never invoke a
   replacement run to extend a receipt or resolve uncertainty.
5. Close the window, restore disabled mode and preserve replay evidence.
   Stop if additional quota, paid resources, or separate fault/teardown
   authorization is needed.

This path is asynchronous. It preserves the original signature and request
digest, body cap, and the two-second private RPC limit. It does not establish
that a management-plane round trip can complete inside PHP's synchronous
two-second exchange deadline. The local fixture tests do not certify hosted
CPU, persistence, billing, or private first-create behavior.

Expected footprint is two private Workers, two SQLite Durable Object classes
with one singleton each, and one manual Workflow definition. The smoke run
uses one instance and step with one caller and one bridge DO invocation.
USD 0 is conditional on a verified Free account with spare quotas. Hosted
measurements and owner authorization are outstanding.

#### Offline control-plane rehearsal

Run the targeted suite against the two already reviewed first-create archives:

```sh
DF_FIRST_CREATE_ARCHIVES=/path/to/reviewed/zips python3 -B -m unittest discover \
  -s contracts/cloudflare-dispatcher/custody-bridge -p test_private_first_create.py -v
```

The fixed account-scoped sequence is:

1. Check Free entitlement, headroom, zero incremental cost, atomic private
   creation support, both ZIP digests, and empty Worker, namespace and Workflow
   inventories. Use one durable SQLite journal in the fixture.
2. POST twice to `/workers/workers` with public URL flags false and no modules.
   GET both immutable Worker IDs and verify both URL flags and empty public
   routing before either first version upload.
3. POST the bridge version to `/workers/workers/{id}/versions` and deploy its
   exact version via `/workers/scripts/{name}/deployments`. Observe its SQLite
   namespace. Repeat for the caller with the bridge service binding and the
   caller's initial SQLite migration.
4. PUT `/workflows/digiforge-stage-a-manual` with no schedule. Read back the
   definition and verify zero instances. Upload and deploy the final caller
   version with the Workflow binding and the same caller namespace ID, omitting
   the migration. Verify both active versions, exact binding sets, two private
   Workers, two unchanged SQLite namespace IDs, one Workflow and zero instances.
5. Stop on any mismatch or uncertain result. Preserve the consumed local attempt
   and request fingerprints. Reconcile by read-only inventory of the exact
   account and known resource IDs. Deletion needs its own approval. Rehearsal
   neither automatically retries nor deletes resources.

Paths above are relative to `/accounts/{account_id}`. The fixture uses normalized
observations for route inventory, uploaded version content digest, deployed
version ID and Workflow instance enumeration. It does not assert Cloudflare
returns those fields on the depicted calls. A hosted adapter must independently
establish each observation through the documented APIs, including complete
routing inventory (zone routes and Worker custom domains), pagination and
version-content readback, or deny before any code upload. In particular, tests
do not establish that Cloudflare honors private flags on the first create or
that a timeout rules out a committed write. The rehearsal has no physical
transport, credential integration, GitHub workflow or operation to create a
Workflow instance. The clock budget checks bracket returning fixture calls.
A separately reviewed physical adapter would also need hard I/O deadlines.

#### Governance handoff

The protected `cloudflare-synthetic-deploy-execution` ref is reserved for the
accepted inert bootstrap. A manual workflow must exist on the repository's
default branch and the selected ref must contain the runnable reviewed version.
The deployment environment allows only the protected ref. A default-branch
registration by itself would leave the only eligible ref inert. This work
does not supply an eligible live workflow or substitute for independent
deployment authorization. Before disabled provisioning, obtain a separately
approved governed source promotion and workflow registration path, verify the
effective environment approval and credential custody, and obtain current
account-level entitlement, effective permission and atomic-privacy evidence
for the exact frozen artifacts. Hosted invocation remains separate.
