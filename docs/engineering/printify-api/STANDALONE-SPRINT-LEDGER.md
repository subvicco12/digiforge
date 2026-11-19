# Standalone Printify acceptance ledger

This is an offline engineering checkpoint. The standalone package is passive and
advertises zero provider capabilities. The existing qualified operation ledger
remains reference evidence for the integrated API implementation.

| Component | Verified starting SHA | Scope |
| --- | --- | --- |
| API #1260 | 035b693713fe162cf3f74edd6d5e2f6217cff9e1 | Sole integration target |
| Companion #1263 | 36a713cbbc8af36a04497e9bf4679c1976793f71 | Read-only compatibility reference |
| Core #1264 | c02b248c14eb71a571e5dae881fe0a10823b5560 | Read-only contract reference |

| Lane | Owned paths in this checkpoint | Offline evidence | Remaining standalone acceptance |
| --- | --- | --- | --- |
| A, provider | includes/POD/PrintifyApiRequest.php, includes/POD/PrintifyApiReadEvidence.php, tests/unit/PrintifyApiBoundaryTest.php | Order validation enforces actual request limit and preserves it on continuation. | Bind the qualified catalog, geometry, upload, product, shipping, order, cancellation and tracking operations through explicit standalone services. |
| B, native | contracts/cloudflare-dispatcher/NativeObservationConformance.php and test/core-observer-conformance.test.mjs in that tree | Future held-journal events are rejected. Existing native contract identity stays pinned. | Authoritative REGISTER, CLAIM, allocation and recovery remain gated by the existing Core and independently qualified checkpoint. |
| C, execution | contracts/cloudflare-dispatcher/sender.mjs and test/custody-fencing.test.mjs in that tree | A late synthetic response body is cancelled after a deadline or custody failure. UNKNOWN and one-use slots are retained. | Exclusive usable credential custody, independent recovery and hosted revocation/drain qualification remain external prerequisites. |
| D, standalone | standalone/printify-api/ | Passive bootstrap, inert status, exact Git-blob manifest, deterministic ZIP and extracted bootstrap checks. | Configuration and operator UI, complete capability discovery, supported runtime composition and disposable WordPress installation acceptance. |
| Integration | .gitattributes, .github/workflows/printify-standalone-offline.yml and this ledger | Standalone tree excluded from Core export. Dedicated offline CI tests source and rebuilt artifact. | Await exact-head hosted CI and add end-to-end standalone provider acceptance. |

Existing integrated API operations and their approval rules are documented in
[the operation ledger](OPERATIONS-1.0.103.20.md). Their offline tests do not imply
standalone capability or live provider acceptance. The native and synthetic
sender paths remain distinct from the standalone bootstrap.

The governing 19 November 2026 architecture document and its sections 1–24
were not located during this checkpoint. Obtain the exact source before final
feature-by-feature certification. Do not substitute an earlier blueprint.
Shop 2 and its WordPress diagnostic were excluded.

STOP ALL and external safety lock remain on. Automation remains off and RELEASE
HOLD stays active. No production installation, activation or provider execution
is authorized.
