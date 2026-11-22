# Shop 2 independent native authority — offline implementation

This service implements real Ed25519 authentication, witness-owned SQLite allocation/CAS and recovery journals, and a separate credential-owning HTTP sender. It is **OFFLINE_TEST only**. It neither advertises new canonical WordPress native-port capabilities nor qualifies production dispatch. STOP ALL, external lock, automation OFF and RELEASE HOLD remain the platform defaults.

## Components and interfaces

| Component | Entrypoint | Responsibility |
| --- | --- | --- |
| Protocol/schema | `protocol.mjs`, `schema.mjs` | Fixed signing domains, canonical JSON, exact versioned identity and request schemas |
| Witness | `server.mjs witness <private-config.json>` | Independent signatures, durable allocation, generation invalidation, replay ledger and recovery controls |
| Issuer/custodian | `server.mjs issuer <private-config.json>` | Workload authentication, pinned witness verification and operation-scoped credential/payload custody |
| Consumer | `AuthorityClient.call(method, body)` in `client.mjs` | Purpose-bound RPCs with independently verified issuer and witness attestations |
| Internal gateway | `WitnessStoreGateway` in `gateway.mjs` | Authenticated state, operation, SENDING, finish and reconciliation calls; no Boolean dispatch permit |
| WordPress retention | `wordpress/bootstrap.php` | Explicit opt-in retained reviewer authorization; see its backend and transaction requirements |

The public methods are `currentCheckpoint`, `admitOperation`, `reserveOperation`, `readOperation`, `requestEmergency`, `readDrain`, `recoverInstallation` and `rotateRoot`. Their executable, exact-field schema is `schema.mjs`; the existing canonical native v1 pin and companion/API sources are unchanged. Request/response purpose and witness signing domains are separate. Trust comes from provisioned pinned keys, never response-supplied keys or WordPress options.

`reserveOperation` uses the existing witness CAS and journal without invoking the sender. It is synthetic-only and respects the existing holds. Its allocations cannot be sent by the legacy combined `admitOperation` path. A separately qualified sender handoff is required before enabling any dispatch of a reservation.

Core must persist the request ID and challenge before attempting `reserveOperation`.  Pass those values as the third argument to `AuthorityClient.call`. If acknowledgement is lost, call `readOperation` with the original tokens, operation and payload identity, original epoch and generation, and an independently retained witness head and sequence. The witness checks that head against its committed history and returns the exact allocation and admitted event. The outer generation describes the readback snapshot, so an older operation can remain readable after STOP. A readback never issues an execution permit or retries admission.

The witness process opens its journal and witness key, not provider credentials or payload files. The issuer opens its own key and operation-scoped private custody files, not the witness database. Actual witness state commits SENDING before any provider write. Uncertain sends remain consumed; recovery requires authenticated readback and dual control. Emergency invalidation pauses admissions immediately; drain reports PENDING until unresolved operations are settled. Recovery and root rotation remain held and do not rearm dispatch.

The service-only dual-signed `ABANDON_UNSENT` control resolves a revoked allocation whose intact witness journal is still ADMITTED. It requires the exact operation, allocation/revision, original generation and current witness head. The witness records FAILED with `revoked_before_send`; it keeps the allocation consumed and denies SENDING/UNKNOWN/terminal or current-generation operations. This is a witness journal decision, not a provider observation. Operations that might have sent bytes still require authenticated readback.

The offline `SEND_RESERVED` control is dual-signed and binds the exact allocation, payload, epoch, generation, committed admission event, and Core journal head and receipt digest. The witness consumes the reservation in the existing SENDING transaction. The reserved path does not enter the legacy combined sender. The separate sender remains limited to synthetic loopback custody. Core can journal a signed UNKNOWN outcome against the exact held reservation without authorizing a repeat attempt.

## Reproduction

Node 24 with built-in `node:sqlite` is required; there are no package-install steps. Run the disposable component/process tests from the repository root:

```sh
node --test --test-isolation=none services/native-authority/test/*.test.mjs
```

Tests generate ephemeral Ed25519 keys and private 0700/0600 fixtures outside the repository. Provider responses come from loopback fixtures. Do not reuse those fixture keys, provisioning records or released test holds for another installation. Configuration requires distinct role keys, identical owner/recovery signatures over installation identity, exact source-build digest, custody inventories, explicit physical shop mapping and bounded grants. Startup rejects partial provisioning and mismatched existing identity. CLI startup emits only its role and loopback address; errors omit private paths and credentials.

Production source is excluded from the WordPress plugin archive through `/services export-ignore`. It requires a separately operated and qualified service/bridge package; there is no activation hook or production migration.

## Remaining qualification gates

- Separate administrative, restoration and credential-custody principals, pinned trust distribution, and authenticated hosted transport have not been provisioned or independently qualified.
- Mandatory provider egress and invalidation of restored/old credential copies require the infrastructure/API owner. A local witness cannot prevent arbitrary privileged code from using a copied provider token outside this service.
- LIVE mode and live readback are denied. The separate API owner must supply exact provider association, sender and reconciliation contracts; loopback tests do not establish provider approvals.
- Native product/order counters here are real persisted counters. Binding external canonical source changes to them requires an approved producer integration; a caller's reference label alone is not that proof.
- WordPress retention supports only its explicitly verified DB/GPO/native backend. Production trigger/schema installation, privileges and backend qualification remain external gates.
- All-root database rollback, privileged DDL/code replacement and clock rollback are outside the demonstrated local protection. No full 151-capability acceptance follows from component test counts.

Audit #5050 remains the preserved certified baseline. This new source needs its own focused evidence and exact-head CI before any certification claim.

## Frozen-source evidence

- [Native tests and N01–N12 dispositions](evidence/native-acceptance.json): 165 passing tests, including actual separate-process CAS, SIGKILL, readback, rotation and held recovery. Eight scenarios are demonstrated offline; N05/N09/N12 remain partial and N11 still requires the canonical tracking receipt consumer.
- [Independent WordPress composition](evidence/wordpress-acceptance.json): 51 tests / 843 assertions, with unchanged PHP source hashes during execution. Actual foreign-reviewed selection reaches plan review, BLOCKED intent/job and held metadata; stale same-pixel revision, seven mutable revocation paths, natural expiry, scope/caller substitutions, contradictory COMMIT and failed pin cleanup are denied. Actual SIGKILL before/after COMMIT preserves the durable nonce and witness and prevents replay.
- [M01–M09 scope and remaining gates](wordpress/evidence/m01-m09-disposition.json): component and composed evidence are distinguished. Remaining permutations are not promoted to complete requirement acceptance.
- The existing shared-projection regression passes 4 tests / 41 assertions. All 32 previously accepted capabilities are preserved; no new 151-ledger promotion is claimed.

Audit #5051 subsequently succeeded on parent `b367652c8d1ae1448cf4da286a8959fe32204b25`; that evidence is reused. The focused workflow checks the new exact PR head without canceling an earlier run. Neither its result nor the preserved parent audit constitutes hosted/production qualification.

The smallest infrastructure owner decision is to designate separately administered disposable issuer/custodian and witness environments and their trusted principals. Production schema installation, provider credentials, egress enforcement and execution require separate exact-scope authorization.
