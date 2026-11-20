# Private Printify GET transport seam

`ProviderReadTransport` consumes the exact ordered
`DF_PRINTIFY_CUSTODY_READ_REQUEST_1` JSON as `exchange(wire)`. It returns the
existing `{record, signature, body}` shape and Ed25519 purpose under the distinct
`SIGNED_PROVIDER_TRANSPORT_SIMULATION` evidence class. The existing custody
fixture class and provider-confirmed evidence are separate. This module registers
no Worker, public route, provider URL, HTTP client, or usable credential.

Construction requires exact `shop`, `account_reference`, `credential_reference`,
`source_sha`, `source_revision`, `authorization_revision` and `signer_key`.
The signer pin is a canonical Base64 encoding of the 32-byte Ed25519 public
key. Injected ports receive only the bound request and its exact wire digest.

| Port | Contract |
| --- | --- |
| `authority.reserve(binding, {signal})` | Verifies the current approved binding and atomically reserves operation identity and quota. Returns `null` for denial, `{state:'ADMITTED',token}`, or `{state:'RATE_LIMITED',token,retry_after}`. Tokens are 64-character lowercase SHA-256-format references. The opaque product typed fingerprint must match the exact authorized product selector because the PHP selector dimensions are absent on the wire. |
| `authority.current(binding, token, {signal})` | Returns `true` only while the reservation, revisions, and authority remain current. |
| `authority.finish(binding, token, state, {signal})` | Atomically commits the terminal state at the authority boundary and returns `true` once confirmed. `COMPLETED` requires currentness and a matching reservation at commit. `UNKNOWN` must preserve consumption and may never rearm work. The authority must fence pending work on restart, including when reserve or finalize acknowledgement is lost. |
| `connector.get(binding, {signal, maxResponseBytes})` | Performs one approved GET against the injected synthetic endpoint with redirects disabled. Only this port may resolve the credential reference. Returns `{status, headers, body}` with lowercase selected headers and an async byte iterator. The connector must respect abort and enforce its own destination and response-size policy. |
| `sign(bytes)`, `clock()` | Sign with the pinned simulation key and supply current epoch seconds. The signer is verified before evidence is released. |
| `telemetry(event)` | Receives state, reason, operation name and operation ID only. It is optional and cannot decide admission. |

The enabled selector set is shop listing, provider listing/detail, product
listing/detail and order listing/detail. Pages are bounded to 1–3, with at most
10 records per page. Pagination links are not followed. A single exchange has
a 2-second timeout and a 256 KiB response budget. Malformed or out-of-scope
bodies, redirects, unknown statuses, failures and uncertain authority commits
produce `UNKNOWN`. Signed 429 responses carry only bounded retry guidance.
The transport does not retry. The standalone client independently validates
the signed receipt and typed response projection.

The positive path in the tests uses only synthetic responses, including one
loopback HTTP server with a disposable token held in its test connector. Live
composition requires an independently qualified authority port, exclusive
credential custody, reviewed destination policy, and explicit release
approval. Those dependencies are not supplied by this module.
