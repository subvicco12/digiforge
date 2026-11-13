# Protected native order observations — offline partial

This adds no provider sender, credential bridge, migration, activation, background task or operational approval. PR #1265 and the independent Printify API plugin are unchanged. STOP ALL/external lock ON, automation OFF, manual approval and RELEASE HOLD remain required.

## Actual source and producer join

`PrintifyApiReadWorkflow::orderSource()` reads an existing successful, sealed, content-addressed `ORDER_READ` receipt. It reconstructs the exact GET request and requires its unique receipt audit, preceding reservation audit, same actual producer actor, original sealed authorization, consumed plan claim and exact original policy revision/hash still approved. Reapproval cannot relabel an old producer authorization. It returns no nonce, session token or credential.

`NativeOrderSourceEvidence::resolve()` additionally requires the current signed session, shop permissions and exact independently reviewed connection. It rechecks source and scope before returning. The provider order identity uses the companion's exact physical-scope rule, with its order ID obtained from sealed source data:

`hash([[shop_key, integration_id, provider_shop_id, environment], trusted_provider_order_id])`

`immutable_source_version` is the actual receipt content hash. It is **not** a numeric business product/order version. `producer_audit_id` references the real governed-read producer audit; it is **not** a canonical native dispatch producer/fence/sequence qualification. Both qualifications remain explicitly false.

## Passive observation persistence

`NativeOrderSourceSchema` is an unregistered SQL proposal, installed explicitly only by disposable tests. Missing tables deny. `NativeOrderSourceJournal::register(scope, actor, sourceHash, expectedObservationRevision, expectedHead)`:

- Validates actual protected source before and inside the transaction.
- Requires real InnoDB dependencies, standalone repeatable-read transactions and declared fsync/binlog durability settings; temporary table shadows deny.
- Serializes each physical order with exclusive SQL-session reservation and immutable primary/unique constraints.
- Pins current observation revision and event head. A new source appends one sealed, audit-anchored event; stale generations, substitutions and older producer observations deny.
- Reconciles only an exact current source replay against its original predecessor or current head. It never creates a fence, dispatch lease or renewed execution acknowledgement.
- Commits event and audit together, releases reservation, then performs a new source/audit-anchored readback. Uncertain commit/cleanup/readback returns denial. No external operation can occur.
- Bounds history at 256 events and detects retained audit history hidden by journal-only rollback. A coordinated rollback of all local roots remains unqualified; local SQL settings are not independent host recovery evidence.

Observation revisions track registered observations only. They must never be copied into canonical `order_version`, `product_version`, recovery epoch or authorization generation fields.

## Read-only native snapshot

`inspectWorkflows()` can return a canonical read-only view of **explicitly registered core observations**, not a remote provider order inventory. It checks both ordered journal/audit identity roots in one consistent snapshot (requested limit 1–20 plus one look-ahead entry), verifies each selected history/source, then revalidates source policy and signed scope after commit. An unavailable table, orphaned root, lost source or revoked policy denies; it is never substituted with an empty success. A real installed empty registry can return an empty local inventory.

Every item is `ORDER_OBSERVATION`, `ORDER_REVIEW_REQUIRED`, manual review required. Aggregate `WORKFLOW` remains unadvertised: canonical `recordWorkflow` receipts require genuine independent epochs and business joins that are still absent.

## Remaining canonical gates

| Interface | Status | Exact remaining condition |
| --- | --- | --- |
| Protected producer/immutable source join | PARTIAL | Core governed-read provenance implemented; native dispatch fence/sequence/independent epoch absent |
| Observation SQL/idempotency/snapshot | PARTIAL | Tested local component; passive schema, no hosted durability or installation qualification |
| Product/order business versions and allocations | NOT_IMPLEMENTED | Authoritative business version ownership and explicit approved tracking-to-line quantities; no inferred allocation |
| Operational mutation grant/publication | BLOCKED | Current exact business approval/version/allocation joins and independently enforceable revocation/checkpoint authority |
| Recovery/custody | BLOCKED | Independent witness/generation, credential lease fencing, in-flight drain and host fail-stop evidence |
| Companion reviewed-source acceptance | BLOCKED | Companion's historical default reviewed-source pin is unchanged; exact new source requires independent acceptance, not relaxed checks |

Audit #4981 at starting `2fb55b6104448825ec9a88b9600af4d8c7442381` failed in the catalog profile: ten native fixture setup errors from retained duplicate or malformed configured connection inventory. The fixture now isolates its synthetic integration inventory and restores original rows byte for byte. Runtime duplicate/malformed-identity rejection is unchanged. The independently committed equivalent fixture fix at `63780e9bc4df6623f02c0a2b74731e5dc7cd50bc` is preserved byte for byte. Focused WordPress 46/273, final fixture regression 2/14, catalog profile 58/11805 and standalone boundary 17/95 pass locally. New source requires a fresh exact-head audit; historical successes do not certify it.
