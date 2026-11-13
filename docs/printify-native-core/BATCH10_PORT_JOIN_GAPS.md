# Batch 10: native operational port join gaps

Update: the later bounded core-governed ORDER_READ producer/source registry and read-only registered-observation inventory are documented in [NATIVE_SOURCE_OBSERVATIONS.md](NATIVE_SOURCE_OBSERVATIONS.md). The requirements below still apply to canonical operational business versions, allocations, dispatch producers and independent epochs; observation revisions do not satisfy those requirements.

The native protected records support local evidence inspection; they do not yet supply the canonical operational authority required by `includes/Connector/Printify/native-core-port.v1.json`. This assessment does not authorize provider writes, change STOP/lock/release-hold policy, or qualify rollback recovery.

| Canonical port | Existing core evidence | Required missing join |
| --- | --- | --- |
| Mutation and operation inspection | `NativeHeldJournal::read()` and candidate transitions | Operational binding identity, current approval/product version, producer-authenticated receipt, and independent checkpoint |
| Publication admission | `NativePlanEvidence::productPlan()` and `PrintifyProductPipeline::approvalPayload()` | Exact publication operation and canonical product/version binding, current approval, mutation/checkpoint linkage |
| Review queue and decisions | Protected product plans; local order and fulfillment-plan review state | Consistent canonical snapshot, review revision, current product/order versions, recovery/checkpoint epochs, transactional review CAS |
| Workflow intake and snapshots | Protected plans; existing order observations | Core-owned opaque identity registry, authenticated intake provenance, atomic event claim/audit, ordering/conflict lineage, scope-safe inventory |
| Allocation and fulfillment | `ApprovedPodMappingResolver::resolve()`; order line quantities; approved local fulfillment plans | Exact provider-order/read-receipt lineage and approved tracking-to-line quantity allocation, current versions/approval revision, epochs |
| Recovery | Held candidate state | Independent checkpoint generation, enforceable revocation generation and dispatch-lease drain before acknowledgement |

## Evidence boundaries

- `NativePlanEvidence::productPlan()` verifies the protected plan, exact reviewed policy payload and current connection scope. Its result explicitly declares `native_product_order_version_join_verified=false` and `independent_checkpoint_verified=false`. Plan, workflow, package and request hashes identify immutable content; they are not native product/order version counters or canonical operation identities.
- `PrintifyProductPipeline::plan()` and `receipt()` validate protected records and audited receipt references. Existing receipts lack the canonical native producer/fence/recovery-epoch join; `NativePlanEvidence::receipt()` explicitly retains `native_producer_fence_join_verified=false`. A successful local receipt cannot be relabelled as a canonical provider receipt.
- `NativeHeldJournalState::prepare()` and `valid()` preserve `approval_verified=false`, `dispatch_permitted=false` and `independent_checkpoint_verified=false`. A held local fence is not a dispatch permit or an independent checkpoint.
- `OrderSchema::statements()` defines order state and timestamps but no canonical order-version counter or opaque native order-identity registry. `order_line_items.product_version_id` identifies a product-version row; it does not establish the canonical expected/current product version join for a native operation.
- `ApprovedPodMappingResolver::resolve()` establishes one approved listing/product-version/environment mapping. It does not prove shipment tracking allocation. `Orders\Repository::createPlan()` captures reviewed local fulfillment evidence, not an authenticated provider allocation receipt.
- `OperationsReadModel::recent()` uses Etsy shop predicates and includes external order references. Its raw output is not a redacted native companion snapshot.
- `workflow_view` has no structural epoch field, but still requires authoritative identities and a trustworthy scoped inventory. Neither an invented identity mapping nor an empty result substituted for unavailable evidence establishes that inventory. Product plans include create/update/upload operations; they must not be inferred to represent publication workflow items.

## Prerequisites before canonical success

1. A core-owned, scope-bound identity index must explicitly link canonical opaque operation/order/product identities to existing protected plan/workflow/package records, provider references and authoritative product/order version records. It must reject ambiguous or cross-scope matches; hashing an arbitrary caller value or reusing an immutable plan hash is not a substitute.
2. Current approval and version checks must join that identity index to the exact approved operation and expected/current versions, with transactional CAS and read-back where the contract requires it. Define version ownership and changes explicitly before mapping existing database IDs or timestamps to versions.
3. A native operational journal must atomically bind identity, authority, events and receipts. Receipt provenance must come from the core-owned authenticated producer and its actual dispatch fence, rather than caller-supplied receipt claims or local candidate fences.
4. Independently enforceable checkpoint/recovery authority must supply actual epochs and generations, revoke on every emergency event, and acknowledge only after dispatch leases drain. Do not create placeholder epochs, derive them from content hashes, or equate local database durability with independent recovery evidence.
5. Snapshot and allocation producers must read those joins consistently, preserve uncertainty/conflicts, redact buyer/provider identifiers, and prove exact tracking-to-line quantities without inference.

Until these prerequisites exist, no additional mutation, inspection, publication, workflow, operations, allocation or recovery capability is justified by these local records. Preserve typed denials and advertise only independently completed capabilities. Batch 10's separate governed GET binding uses existing sealed `PrintifyApiReadWorkflow` references and normal business safety; it adds no authentication authority or dispatcher and does not close these operational gaps.

Companion acceptance context: `docs/printify-connector/BATCH9_NATIVE_ACCEPTANCE.md` in the companion repository records the same missing operational joins and distinguishes held candidate persistence from dispatch/recovery authority. No companion changes are part of this document.
