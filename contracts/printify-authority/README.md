# Independent acceptance authority — offline reference

Status: OFFLINE_REFERENCE / NOT DEPLOYABLE / ACCEPTANCE DISABLED.

`Authority.php` is an executable protocol model, outside WordPress autoloading and
registration. It has no HTTP listener, credential implementation, network sender,
deployed state backend, admission UI, session issuer or recovery-epoch witness.
It does not open any provider execution path. Implementing those ports and proving
their infrastructure properties is a separate, unapproved project.

## Required trusted boundary

The external service must own the final provider request AND exclusive credential
custody. A WordPress rollback must not recover a usable token, signing issuer key,
state-store credential or service-administration authority. External database
checks alone are cooperative if Hostinger can send a token directly to Printify.
All previously usable Hostinger tokens, including recoverable backup copies, must
be made unusable provider-side, or a qualified host-wide egress fence must prevent
their use. Neither action is authorized or established here.

The owner-grant issuer, emergency-stop channel and recovery epoch must be outside
Hostinger's restoration boundary. Existing DigiForge owner identity remains
unchanged; the model does not reenroll the owner or require them to manage a new
private signing key. A future independently reviewed service would manage its own
issuer keys. Tests generate ephemeral keys, never production credentials.

## Protocol

Read and stop envelopes have exact schemas, separate key IDs and signature
domains, and Ed25519 signatures over `Protocol::canonical({key_id,manifest})`.
Canonical data accepts only bounded arrays, strings, integers, booleans and null;
there is no caller-selected signature algorithm. Verify bytes using the same
canonical implementation, not a different JSON serializer.

Read grants bind actor 1, a separately approved owner-proof digest, review digest,
external recovery epoch, safety generation, account reference, opaque credential
binding, internal shop 2 / personalized_pod, integration 3, provider shop 28930593,
session, one-use slot, nonce, request fingerprint and frozen GET-contract hash.
The synthetic account reference is a fixture, not discovered production identity.
Provider-account association and real scopes remain UNKNOWN.

Server-approved sessions independently pin every request slot; a signature alone
cannot expand them. Catalog reads additionally require a registered unrevoked
grant bound to the session, generation, exact request and expiry. No session or
grant issuance endpoint is supplied. Ten slots, five-minute maximum grants,
three account-wide admissions per rolling minute, three-page allowlist,
1 MiB body maximum, 20-second timeout and no automatic retries are retained.

The pinned `PrintifyAcceptanceContract` must be vendored/frozen in the external
service release, never downloaded from the live WordPress installation. Its hash
is checked before admission. Requests contain no host, headers, credentials or
caller transport options. The ProviderPort must hard-code HTTPS api.printify.com,
certificate verification, zero redirects and one attempt; it must enforce the
byte limit while receiving the body. No such real adapter is implemented here.

## Transaction and revocation semantics

`AtomicState::transaction` must provide serializable, durable commit-before-ACK
and independent account-wide serialization. Test copy-on-write state is a model,
not proof of storage durability or real multi-worker concurrency.

1. Verify the signed read and independently stored session.
2. Atomically consume slot/nonce and quota and persist signed RESERVED evidence.
   Lost ACK denies dispatch; the claim remains consumed if committed.
3. Recheck current epoch, generation, owner/session and custody immediately at
   final ADMITTED transition. This transition is the dispatch linearization point.
4. Send once using the external-only ProviderPort. The admitted lease remains
   visible through completion. No retries or automatic lease expiry/reaping.
5. Recheck authority and persist signed TERMINAL evidence. A stop after admission
   makes the result UNKNOWN, without response observations. Persistence failure
   yields UNKNOWN and leaves an admitted lease requiring separate reconciliation.

A separately signed stop atomically increments generation even when already
stopped, consumes its nonce, and records evidence. It never rearms acceptance.
DRAIN_PENDING means at least one admitted request may still be sent or complete;
it is not a promise that no HTTP packet can leave after stop admission.
DRAIN_ACKNOWLEDGED is returned only when there are no admitted leases. It does not
cancel provider work or certify a terminal provider outcome. A request reserved
but not yet admitted cannot pass the new generation. A fresh stop can observe a
later drained state; earlier receipts remain immutable.

Stop admission/ACK failure is UNKNOWN, not durable success. If the independent
authority is unavailable every new dispatch must fail closed. An admitted remote
request may already exist; its result remains unknown. Emergency fail-stop still
depends on the independently qualified service, administration and network path.

## Recovery and audit limitations

Authority stores can be restored too. Cloudflare Durable Objects provide atomic,
strongly consistent storage AND documented point-in-time restoration. Neon also
supports restoration. Neither is intrinsically an unrewindable monotonic witness.
Before restoring authority state, a separate non-restored recovery epoch and
issuer/deployment control must revoke the old epoch. A simultaneous privileged
rollback of authority code, keys, state and epoch witness is outside this model's
guarantees and must not be represented as solved.

Receipt values are minimized to bounded HTTP status, operation/template, hashed
account/correlation, scope and field-type observations. No raw response, buyer
data, tokens or exception strings escape. OBSERVED only means bounded JSON was
observed, not provider-schema certification. Receipts and hash-chained events are
signed; durable off-service anchoring/retention and read-side chain verification
still require implementation. A trusted state adapter cannot be replaced by
caller-supplied flags or an ordinary rollbackable WordPress table.

## Qualification scope

Synthetic tests cover rollback of caller state, stop races, replay, missing scope,
outages, quota exhaustion, signer/custody failure and uncertain commits. They do
not prove actual Hostinger egress control, provider credential revocation,
multi-worker durability, Cloudflare/Neon availability, owner authentication to an
external issuer, deployed allowlist enforcement or live Printify compatibility.
Keep the existing WordPress acceptance bridge non-executable.
