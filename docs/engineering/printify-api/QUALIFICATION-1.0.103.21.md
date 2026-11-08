# Printify API .21 qualification and fixture reconciliation

Starting source: `7c10891d3b23ad17e6f0c4437760a493f0ae8d9d`, PR1260. Starting ZIP SHA-256 `e41dfa0c63218438f753c32ecf2caa24662c2ae6e78897d47249094af25cb325` (.20, 3,239,151 bytes). Original ZIP and qualification remain unchanged. New candidate .21 fixes demonstrated software defects; installation remains unauthorized.

## Four-fixture matrix

All test IDs below are `PrintifyPreflightTest::<method>` in `tests/wordpress/PrintifyPreflightTest.php`. Original assertions are retained. Fixtures now use `tests/fixtures/PrintifyGeometryFixture.php`, deterministic documented variant placeholders, response/variant SHA-256, source revision/timestamp/environment, region US, and an explicit isolated installed template-cycle policy. This is **synthetic provider-shaped test evidence**, not a live supplier observation or product certification.

| Exact method / original template | Original exclusion | Classification | Restored acceptance |
|---|---|---|---|
| `testValidatedTemplateRouteIsRequiredAndDryRunNeverAuthorizesExecution` / `wp-preflight`, blueprint321/provider77/variant88 | Handwritten VALIDATED area without source bindings, region or shop policy | INCOMPLETE fixture; rejection was correct | Valid provider-derived template clears missing-template blocker; dry run still cannot execute |
| `testExecutionPermitBindsCurrentPreflightAndRejectsReadinessDrift` / `wp-permit`, 888/44/55 | Same incomplete provenance | INCOMPLETE fixture | Exact preflight/request binding accepted; later order-state drift denied |
| `testReadinessDriftInvalidatesApprovedPackage` / `wp-stale`, 777/22/33 | Same; 1000px template had no supplier-backed geometry | INCOMPLETE fixture | Valid supplier-shaped fixture establishes baseline; order change remains stale and blocked |
| `testRouteVariantMismatchFailsClosed` / `wp-mismatch`, 654/55/67 vs mapped66 | Incomplete template prevented reaching intended variant mismatch | INCOMPLETE fixture, useful assertion retained | Valid source variant67 reaches route comparison and rejects mapped66 |

No original case is deleted or permanently excluded. The old .20 broader fixture failures are retained in [previous qualification](https://drive.google.com/file/d/1f-gQaB_sA77urkrkrgbVMd2FGRHqBWAB/view). A fresh isolated .20 run using repaired fixtures passed the six original preflight methods; four **new** negatives failed because .20 did not check current provenance, missing exact catalog, mapped-area dimensions or altered template fingerprint. This red run distinguishes the incomplete fixtures from a real preflight defect.

## Demonstrated corrections

1. **Current geometry preflight:** `PrintifyProductionPreflight` previously trusted VALIDATED and counted approved areas. It now verifies normalized stored fingerprint, calls the existing current exact-catalog snapshot boundary, and matches approved placement/pixel dimensions. Re-observed/stale/missing/changed catalog or altered/mismatched geometry blocks readiness. No conversion, guessed safe area, bleed, DPI or certification is introduced. Existing non-pixel/unmatched areas require explicit evidence rather than inferred conversion.
2. **Source/installed dependency drift:** source `ProductionTemplateContract` lagged the stronger installed .20 contract. Source now matches that exact qualified file; source repository receives only its existing catalog-check helpers/save guard. Packaging retains the stronger installed repository, including its shop policy/locks, unchanged. This does not import unrelated factory development or modify AI policy/runtime settings.
3. **Production acknowledgement:** `PrintifyControlledTransport` previously skipped parsing when it already knew the requested order ID. Every 2xx acknowledgement now requires bounded JSON and documented valid ID; production ID must equal requested order. Empty/malformed/mismatched/truncated acknowledgement becomes UNKNOWN with original correlation and no retry. Creation's documented ID-only response remains accepted.
4. **Line-item cancellation:** documented separate non-idempotent POST, fresh audited order/line binding and exact reviewed policy; empty204 acknowledges request, not guaranteed fulfillment cancellation. Later exact-line readback required for confirmation. Whole-order cancellation keeps its narrower eligible states. Consumed claims survive unknown outcomes.
5. **Provider detail / GPSR:** governed blueprint/provider detail now retains nullable production-time and decoration-method evidence. Product GPSR read uses exact product selection and current module/shop permissions. GPSR text is supplier evidence, never automatic regulatory certification.
6. **Native order contract:** headless native configuration values may enter an order only with matching successful audited configuration receipt, current approved package/plan and current exact account/product/variant ownership. Out-of-band receipt hashes enter the request fingerprint, never the provider body. Raw/unbound/manual configuration is denied. Existing nonnative orders are unchanged. No provider call is needed for offline validation.

## Authoritative reconciliation

[Master Blueprint v6.0](https://docs.google.com/document/d/1isN_2m7eu5DTjykY7X67woiPx7wluhPc/edit) and [Operating Manual](https://docs.google.com/document/d/1YBvHZktB__bf3iV8qM00hCAobayM-VM1/edit) retrieved through authenticated Drive 2026-11-08; manual copy is draft/proposed. Blueprint sections14/15 require provider-authoritative geometry, immutable selected routes, separate Shop2/3 identities and human-controlled production. Sections18/19/23 require correlation, explicit approvals and fail-closed uncertainty. Explicit current owner restrictions prevail. Official [Printify reference](https://developers.printify.com/) retrieved 2026-11-08. Downloadable OpenAPI was previously inaccessible; fixtures follow accessible official documented contracts, not claimed official machine-schema validation.

The machine-readable [operation ledger](operations-1.0.103.21.json) records supported endpoint/version, implementation, request/response, permissions, shop scope, tests, uncertainty and remaining live evidence per operation. The historical [.20 ledger](OPERATIONS-1.0.103.20.md) remains preserved.

### Scope limits — do not call these supplier-unsupported

Printify supports uploads list/archive and support-request list/detail/reprint/refund/address-change. These admission paths remain **NOT_IMPLEMENTED** in DigiForge. Account-wide upload enumeration also needs an approved local ownership projection before it can safely expose shared-account assets. Support/refund/reprint automation requires distinct consequential approval contracts. Manual provider support remains the controlled fallback; no unsupported-provider claim or blanket write admission is made. This candidate is not a complete implementation of every public Printify endpoint.

Printify `/unpublish.json` acknowledges external unpublication; it does not unpublish an Etsy/marketplace listing. Marketplace unpublication is **PROVIDER_BLOCKED** at this Printify interface and remains manual/separately governed. Native `manual` strategy requires merchant correction and is deliberately not admitted as production-ready headless configuration.

Shop3 mappings remain distinct and configuration is tested, but existing **DEFERRED_SHOP** blocks operational execution. No inactive-shop activation is inferred from API support. No operation is LIVE_READ_VERIFIED; consequential writes are LIVE_WRITE_UNTESTED. Supplier delivery, credentials/scopes, geometry and commercial certification remain external gates.

## Stable PR1259 handoff

Preserve its `a597365c3ffbab022671553675a59a8783931e02` implementation; no POD/artwork code is merged. Continue using ApiReadWorkflow/ControlledTransport for governed reads, ProductPipeline for approved-package product operations, LifecycleWorkflow for quote/cancel/webhooks, and MutationRequest/production permit pipeline for orders. New catalog request is `{kind:provider,blueprint_id,provider_id}`; existing exact ten-request grant and separate exception still required. New governed read is PRODUCT_GPSR_READ with the same product selection as PRODUCT_READ. New lifecycle operation LINE_ITEM_CANCEL needs exact order_id/line_item_id and a fresh ORDER_READ receipt.

Native order metadata: `native_configuration_references=[{line_index:0,configuration_receipt:<sha256>}]`; provider line `personalisation` contains only the exact `personalisation_strategy/personalisation_instructions` returned by the verified configuration receipt. The server resolves references, rechecks exact account/shop/product/variant and approved package, then includes provenance hashes in request identity. Prepared evidence alone grants no order submission or production authority.

Read plans retain newly reviewed max_requests3; catalog grants retain10; lifecycle1. Every physical attempt consumes unified account quota. Old claims and UNKNOWN outcomes never regain retry authority; historical reservation22 is untouched.

## Acceptance and release boundaries

Source CI, exact extracted-package tests and live provider acceptance are separate. Full package tests must include all four restored preflight methods, with no fixture exclusions. New negative tests cover fabricated/mismatched/stale geometry, wrong/malformed production acknowledgement, line cancellation and native reference denial. Automated independent review is not human review. Qualification receipts provide exact final counts/run links and digest.

No new schema or marker, no production migration. Existing InnoDB options/audit/nonce/reconciliation storage is reused. Original .20 baseline remains intact. .21 package uses thirteen exact-preimage modifications, one addition, zero deletions; source-only dependency parity does not replace installed shared classes. Qualified .17 portal/auth/owner/Operators/scheduler bytes remain unchanged.

Installation remains RELEASE HOLD. Require current installed-source/schema compatibility, existing owner-approved recovery acceptance and separate installation authorization. Live GETs require explicit governed grants/scopes; writes, webhook provisioning, production, refunds/reprints or publication require separate authorization. STOP ALL ON, external lock ON, automation OFF, activation unauthorized. Recovery qualification is excluded from this mission.
