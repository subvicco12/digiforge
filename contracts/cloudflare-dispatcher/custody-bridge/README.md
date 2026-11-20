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
