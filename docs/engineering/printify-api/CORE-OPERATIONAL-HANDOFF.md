# Core operational handoff — pinned source inspection

Status: READ_ONLY_SOURCE_VERIFIED / CANONICAL_MUTATIONS_BLOCKED.

Inspected 20 November 2026 through the authenticated GitHub read interface. PR #1264 was open, draft, unmerged, at exact head `3ca5fa241694843628b52f4800c9662bc33521dc`. No core edit, commit, push, installation, credential use, provider request or test execution was performed for this inspection. This document is not runtime or operational certification.

## Authoritative source

- `includes/Connector/Printify/NativeCorePort.php`, Git blob `8d8257d07a969fa3a59c056e822d4a719caf68f0`.
- `NativeAuthorityAdapter.php`, Git blob `568048f1f6a793e0ebf4523b87d109e19d41b594`.
- `NativePortContract.php`, Git blob `99f77fbcd985ce4938f7e17c06dd7b3a292830ba`.
- `native-core-port.v1.json`; contract DF_NATIVE_CORE_PORT_1 / 1.0.0; fixed SHA-256 `6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434`.

Source registration advertises only PANEL and GET_READ. It explicitly declares external_execution_authorized=false. Registration does not establish installed availability or live execution permission.

## Exact exchange request

Required fields, with no additional top-level properties:

```text
contract_version = "1.0.0"
action = AUTHORIZE | REGISTER | CLAIM | RECOVER | CANCEL |
         RECEIPT_READ | RECONCILE | OUTCOME
binding = canonical binding
context = canonical context
evidence_references = at most 20 lowercase SHA-256 strings
duplicate_key = lowercase SHA-256
data = bounded JSON object
```

There is no RESERVE or RECEIPT_IMPORT action in this version. Transactional reservation would have to implement CLAIM according to the canonical contract, rather than inventing another action.

Binding requires operation_id, payload_fingerprint, intent_fingerprint, scope_fingerprint and approval_reference hashes; shop_id, shop_key, integration_id, credential_reference, actor_id, authorization_revision; provider_shop_id, environment, product_version and expected_product_version. Context requires actor_id, logical_reference, approval_reference, authorization_revision, product_version and expected_product_version. Duplicated context/binding fields must be exactly equal. Credential reference must equal integration ID. Shop keys personalized_pod/standard_pod must correspond to internal IDs 2/3. Schema membership is not authority.

Before action-specific handling, mutationScope requires the actual authenticated WordPress session, current module/shop permissions, current reviewed provider connection and matching authorization revision/provider shop/environment/scope fingerprint. The underlying integration must be CONFIGURED and enabled, and actual connection records must match. This is identity preflight, not mutation approval. It does not grant an acceptance exception or relax STOP/external locking.

## Actual action behavior

| Action/path | Current source behavior |
| --- | --- |
| AUTHORIZE, two evidence references and product-source locator | Parses `product-source:<positive product-version row ID>:<positive production-plan ID>`. Reads actual protected association using first reference; compares review hash against second reference and plan-policy, payload and scope hashes against binding. Even after all checks, returns product_revision_authority_required. Row IDs are not canonical revision counters. |
| AUTHORIZE, one evidence reference | Resolves real product plan and NativePlanEvidence, validates method POST/PUT/DELETE, operation, request fingerprint, approval hash and canonical wire hash. Then returns journal_and_independent_checkpoint_required. |
| Other AUTHORIZE shape | Returns journal_and_independent_checkpoint_required after identity preflight. |
| REGISTER / CLAIM / RECOVER / CANCEL / RECONCILE / OUTCOME | No successful implementation branch. Returns journal_and_independent_checkpoint_required after contract/identity checks. No reservation, dispatch or automatic recovery occurs. |
| RECEIPT_READ | Requires exactly data.receipt_reference as SHA-256. Reads actual held journal; requires UNKNOWN state, exact event hash, duplicate key and full binding hash, then rechecks mutationScope. Returns WP_Error printify_native_held_outcome_unknown, status409, outcomeUNKNOWN, actual journal revision, provider_receipt_verified=false, network_request_attempted=null, external_execution_authorized=false, retry_permitted=false. This is uncertainty evidence, not successful provider receipt import. |

No action returns a successful canonical mutation response array. Such a response requires real recovery_epoch, checkpoint_epoch, journal_revision>=1 and journal_hash in addition to action/binding/data. Do not fabricate epochs or use placeholders to manufacture conformance.

## Other native ports

- read(): delegates to existing native read connector. Source presence is not a live acceptance result.
- inspect(): validates binding and module access, then denies trusted_operation_identity_join_required.
- authorizePublication(): validates input and listings access; explicitly denies STOP/external lock, requires fresh Global Owner, then still denies publication_approval_and_checkpoint_join_required.
- authorizeWorkflow(): permits narrowly scoped read-only inspection actions and returns local evidence authority only. Write actions deny workflow_write_identity_join_required.
- inspectWorkflows(): authorized read-only actual NativeOrderSourceJournal snapshot.
- recordWorkflow / reviewQueue / decideReview / fulfillment / allocation / recovery: named identity/CAS/receipt/allocation/checkpoint joins remain denied.
- withWebhookSecret(): denies exclusive_webhook_custody_bridge_required; never exports a secret to companion callbacks.
- panelAccess(): delegates to current native protected panel adapter.

## Next core implementation boundary

Implement authoritative canonical revision and identity joins, atomic registration/CLAIM journal transitions and trusted receipt producers using existing core authority and storage. Define genuine checkpoint/recovery evidence before returning canonical success. Publication, allocation and recovery need their explicit joins; held records or shared hashes cannot substitute for approved aggregates. Preserve source-evidence and operator panels as non-authorizing observations until those guarantees exist.

The standalone dispatcher must not treat this exchange as a physical sender or substitute its synthetic state for missing core authority. A caller must preserve WP_Error/UNKNOWN and must not retry or restore a consumed slot. Keep independent rollback/recovery and exclusive credential custody gates distinct from these missing software joins.

STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD active. No policy exception, merge, installation or live execution is authorized by this handoff.
