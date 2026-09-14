# BUILD STAGE U3 — Automated Product Factory & Asset Orchestration

Status: implementation branch

## Objective

U3 closes the post-opportunity gap between approved research and Gate 2 Product Approval. It reuses DigiForge ProductFactory, Production, Research, encrypted AI connector, audit, idempotency and portal architecture. It does not activate Etsy publishing, Printify, Gelato, order automation, fulfillment or GST automation.

## Human approval model

1. **Gate 1 — Opportunity Approval**: existing Research candidate must be explicitly `APPROVED`.
2. **Gate 2 — Product Approval**: generated product assets + separate marketing assets + QA stop at `PRODUCT_REVIEW_REQUIRED`. A human must Approve Product or Reject / Revise.
3. **Gate 3 — Listing/Publish Approval**: intentionally outside U3. U3 never creates a live Etsy listing or authorizes publication.

A candidate that remains `PENDING` cannot enter U3 because `Orchestrator` reuses the existing `ExecutionEngine::develop()` approval check.

## U3 flow

`RESEARCH_PENDING -> RESEARCH_APPROVED -> SPECIFICATION_READY -> ASSET_PRODUCTION -> MARKETING_ASSET_PRODUCTION -> QA_RUNNING -> QA_FAILED | PRODUCT_REVIEW_REQUIRED -> PRODUCT_APPROVED`

Listing states remain defined in the operator-facing Workflow projection but are not executed by U3.

## Automatic continuation after Gate 1

`ApprovalAutomation` listens to the existing audited `research_candidate_reviewed` event. Only `APPROVED` decisions are eligible. It resolves `digital` vs `goods` from the originating research source configuration and schedules the internal `digiforge_u3_build_product` action.

The action invokes only the U3 Orchestrator. It does not invoke Etsy, Printify, Gelato, orders, fulfillment or tax functions. Existing AI + Product Development switch checks remain authoritative inside the reused development engine.

If Action Scheduler exists, DigiForge uses an asynchronous Action Scheduler action. Otherwise it falls back to a one-time WordPress cron event. The product-build idempotency key is stable per candidate/shop.

## Actual product asset production

`LocalAssetProducer` writes generated assets under the WordPress uploads tree:

`digiforge/product-factory/{product_version_id}/`

Supported U3 local formats:

- PDF (valid locally generated text PDF)
- TXT
- CSV
- JSON
- HTML
- SVG
- customer ZIP package

Every stored file receives a SHA-256 checksum, byte count, MIME type and local storage reference. Atomic temporary-file -> rename writes are used for normal files.

For digital products, customer files are ZIP-packaged separately from marketing graphics.

For POD opportunities U3 is limited to artwork/preproduction. No Printify/Gelato catalog mutation, upload, product creation or fulfillment call is present.

## Product assets vs marketing assets

The separation is explicit in Production asset specifications:

- `product_asset` — customer-delivery/production content
- `product_package` — customer ZIP package
- `marketing_asset` — Etsy-facing sales graphic

Marketing assets are not included in the customer ZIP. U3 currently requires marketing visuals to be self-contained SVG assets so they can be generated/stored locally without introducing an additional external image-provider permission surface.

## QA

`AutomatedQa` records deterministic checks against each asset revision:

- file exists
- non-empty file
- SHA-256 checksum match
- extension/format match
- format integrity
- credential-leak indicator scan
- ZIP consistency for the delivery package

Each check is persisted to `production_qa`. Asset revisions move from `PENDING_QA` only to `QA_PASSED` or `QA_FAILED`.

Gate 2 is fail-closed: `ProductReview` requires every required asset's latest revision to be `QA_PASSED`, with at least one QA record and no non-PASS/non-WAIVED checks. It then performs the explicit human approval transitions and deterministic bundle readiness validation.

Semantic/editorial QA (spelling, policy/IP risk, specification consistency) remains represented in the production brief and should be expanded in the next U3 hardening increment before live candidate approval. Deterministic Gate 2 cannot be bypassed by semantic output.

## Product Approval Inbox

`Portal\U3ApprovalInbox` extends the existing single DigiForge Approval Inbox. It shows:

- product/version
- channel
- required asset count
- QA blockers
- bundle state
- workflow state
- optional reviewer notes
- **Approve Product**
- **Reject / Revise**

Approve is disabled in the UI when QA blockers are present, and the backend independently re-checks QA so UI manipulation cannot bypass the gate.

## API

Existing endpoints remain supported. U3 adds:

- `POST /digiforge/v1/launch/candidates/{id}/build-product`
- `POST /digiforge/v1/launch/product-versions/{id}/review`

Both are capability protected. Mutations require DigiForge idempotency handling.

`GET /digiforge/v1/launch/status` explicitly reports that Etsy publish, Printify, Gelato, orders and GST execution are false for U3.

## Safety invariants

- PENDING research candidates cannot be developed.
- U3 does not auto-approve products.
- QA failure cannot be human-approved through the Gate 2 backend.
- Product Approval does not build or publish an Etsy listing.
- Product and marketing assets are distinct.
- Credentials are not accepted in generated structured payloads and are scanned for obvious leakage in local assets.
- No schema version bump is required; U3 reuses schema v13 Production tables.
- Current OFF external controls are not changed by this branch.

## Files introduced/changed

- `includes/ProductFactory/Workflow.php`
- `includes/ProductFactory/LocalAssetProducer.php`
- `includes/ProductFactory/AutomatedQa.php`
- `includes/ProductFactory/Orchestrator.php`
- `includes/ProductFactory/ProductReview.php`
- `includes/ProductFactory/ApprovalAutomation.php`
- `includes/Portal/U3ApprovalInbox.php`
- `includes/REST/LaunchController.php`
- `includes/Core/Plugin.php`
- `tests/unit/bootstrap.php`
- `tests/unit/U3WorkflowTest.php`
- `tests/unit/U3ProductFactoryStructureTest.php`

## Candidate #1

No code in this stage changes Candidate #1's review status. Because the U3 trigger requires an actual future `APPROVED` audit event, an already-PENDING candidate remains PENDING throughout build/test/review of this branch.
