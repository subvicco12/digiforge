# BUILD STAGE U3 — Automated Product Factory & Asset Orchestration

Status: implementation branch / PR #55

## Objective

U3 closes the post-opportunity gap between approved research and Gate 2 Product Approval. It reuses DigiForge ProductFactory, Production, Research, encrypted AI connector, audit, idempotency and portal architecture. It does not activate Etsy publishing, Printify, Gelato, order automation, fulfillment or GST automation.

## Human approval model

1. **Gate 1 — Opportunity Approval**: existing Research candidate must be explicitly `APPROVED`.
2. **Gate 2 — Product Approval**: generated product assets + separate marketing assets + deterministic QA + independent semantic/policy QA stop at `PRODUCT_REVIEW_REQUIRED`. A human must Approve Product or Reject / Revise.
3. **Gate 3 — Listing/Publish Approval**: intentionally outside U3. U3 never creates a live Etsy listing or authorizes publication.

A candidate that remains `PENDING` cannot enter U3 because `Orchestrator` reuses the existing `ExecutionEngine::develop()` approval check.

## U3 flow

`RESEARCH_PENDING -> RESEARCH_APPROVED -> SPECIFICATION_READY -> ASSET_PRODUCTION -> MARKETING_ASSET_PRODUCTION -> QA_RUNNING -> QA_FAILED | PRODUCT_REVIEW_REQUIRED -> PRODUCT_APPROVED`

Listing states remain defined in the operator-facing Workflow projection but are not executed by U3.

## Automatic continuation after Gate 1

`ApprovalAutomation` listens to the existing audited `research_candidate_reviewed` event. Only `APPROVED` decisions are eligible. It resolves `digital` vs `goods` from the originating research source configuration and schedules the internal `digiforge_u3_build_product` action.

The action invokes only the U3 Orchestrator. It does not invoke Etsy, Printify, Gelato, orders, fulfillment or tax functions. Existing AI + Product Development switch checks remain authoritative inside the reused development engine.

If Action Scheduler exists, DigiForge uses an asynchronous Action Scheduler action. Otherwise it falls back to a one-time WordPress cron event. The scheduler return value is checked before DigiForge records the job as scheduled. The product-build idempotency key is stable per candidate/shop.

The legacy second manual “Develop approved candidate” form is suppressed in the Research and Approval views so Gate 1 approval naturally continues to the automated Product Factory instead of inviting duplicate development.

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

HTML is sanitized. SVG rejects script/iframe/object/embed/foreignObject content, inline event handlers, external/javascript/data references, DOCTYPE/ENTITY declarations and other active markup indicators.

Generated asset keys and filenames must be unique. For digital products, customer files are ZIP-packaged separately from marketing graphics.

For POD opportunities U3 is limited to artwork/preproduction. No Printify/Gelato catalog mutation, upload, product creation or fulfillment call is present.

## Product assets vs marketing assets

The separation is explicit in Production asset specifications:

- `product_asset` — customer-delivery/production content
- `product_package` — customer ZIP package
- `marketing_asset` — Etsy-facing sales graphic

Marketing assets are not included in the customer ZIP. U3 currently requires marketing visuals to be self-contained SVG assets so they can be generated/stored locally without introducing an additional external image-provider permission surface.

## AI output budget

Existing research, product-specification and semantic-QA calls retain the normal 4,000-token output ceiling. Only briefs beginning with the exact U3 Production contract receive a 12,000-token ceiling, allowing complete customer files and multiple marketing SVGs without broadly increasing routine AI cost.

## QA — deterministic file checks

`AutomatedQa` records deterministic checks against each asset revision:

- file exists
- non-empty file
- SHA-256 checksum match
- extension/format match
- format integrity
- credential-leak indicator scan
- ZIP consistency for the delivery package

Each check is persisted to `production_qa`. Asset revisions move from `PENDING_QA` only to `QA_PASSED` or `QA_FAILED`.

## QA — independent semantic/policy checks

`SemanticQa` performs a second AI call after actual files have been generated. It is separate from the generation call and is fail-closed. Seven checks are mandatory:

1. `specification_match`
2. `spelling_text_quality`
3. `ip_trademark_risk`
4. `prohibited_content`
5. `link_qr_integrity`
6. `marketing_product_consistency`
7. `mockup_production_separation`

Missing checks are converted to failures. HTML/SVG markup is sampled after local sanitization so visible text and link claims can be inspected. Text is extracted from the locally generated PDF stream for semantic review. ZIP binary integrity remains a deterministic check.

These plan-level semantic QA results are persisted in `production_qa` and Gate 2 independently requires all seven to be PASS/WAIVED. Any deterministic or semantic failure leaves the plan at a non-review state and does not create the Product Approval release bundle.

## Gate 2 enforcement

`ProductReview` requires:

- production plan in `REVIEW_REQUIRED`
- release bundle in `REVIEW_REQUIRED`
- all seven semantic checks completed with no blocker
- every required asset's latest revision in `QA_PASSED`
- at least one revision QA record per required asset
- no revision QA state outside PASS/WAIVED
- authenticated human reviewer

Only then can required revisions, plan, bundle and product/version states be approved and the existing deterministic `validateBundle()` readiness check set the bundle to `RELEASE_READY`.

Reject / Revise does not publish or list anything. It sends the product back toward asset revision work.

## Product Approval Inbox

`Portal\U3ApprovalInbox` extends the existing single DigiForge Approval Inbox. It shows:

- product/version
- channel
- required asset count
- deterministic + semantic QA blockers
- semantic QA PASS/BLOCKED status
- bundle state
- workflow state
- optional reviewer notes
- **Approve Product**
- **Reject / Revise**

Approve is disabled in the UI when QA blockers are present, and the backend independently re-checks deterministic and semantic QA so UI manipulation cannot bypass the gate.

## API

Existing endpoints remain supported. U3 adds:

- `POST /digiforge/v1/launch/candidates/{id}/build-product`
- `POST /digiforge/v1/launch/product-versions/{id}/review`

Both are capability protected. Mutations require DigiForge idempotency handling.

`GET /digiforge/v1/launch/status` explicitly reports that Etsy publish, Printify, Gelato, orders and GST execution are false for U3.

## Safety invariants

- PENDING research candidates cannot be developed.
- U3 does not auto-approve products.
- Failed deterministic QA cannot enter Product Approval.
- Failed or incomplete semantic/policy QA cannot enter Product Approval.
- Product Approval does not build or publish an Etsy listing.
- Product and marketing assets are distinct.
- Marketing assets are excluded from the customer ZIP.
- Generated HTML/SVG active content is sanitized/rejected before storage.
- Credentials are scanned for obvious leakage in local assets.
- No schema version bump is required; U3 reuses schema v13 Production tables.
- Current OFF external controls are not changed by this branch.

## Files introduced/changed

- `includes/ProductFactory/Workflow.php`
- `includes/ProductFactory/LocalAssetProducer.php`
- `includes/ProductFactory/AutomatedQa.php`
- `includes/ProductFactory/SemanticQa.php`
- `includes/ProductFactory/Orchestrator.php`
- `includes/ProductFactory/ProductReview.php`
- `includes/ProductFactory/ApprovalAutomation.php`
- `includes/Portal/U3ApprovalInbox.php`
- `includes/REST/LaunchController.php`
- `includes/Launch/OpenAIClient.php`
- `includes/Core/Plugin.php`
- `tests/unit/bootstrap.php`
- `tests/unit/U3WorkflowTest.php`
- `tests/unit/U3ProductFactoryStructureTest.php`

## CI note

At the time of this branch, the repository Engineering & Safety Audit is again failing before runner assignment: the GitHub job contains zero executed steps. This matches the earlier repository Actions infrastructure incident. It must not be represented as a successful CI test run, nor as evidence of a DigiForge runtime failure.

## Candidate #1

No code in this stage changes Candidate #1's review status. Because the U3 trigger requires an actual future `APPROVED` audit event, Candidate #1 remains PENDING throughout build/test/review of this branch.
