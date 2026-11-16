# Shop 2 image execution and research consent — engineering checkpoint

Starting source: `61d2b6bf84a6bd7f5daac6488df5189b10223f85`, existing draft PR1267 and branch `codex/shop2-product-factory-1266`. No artwork campaign, product research, catalog expansion, production setting, migration or provider operation is part of this batch. Existing date policies and original FL03/FL04 fixtures remain unchanged.

Implemented executable paths:

- Current signed Shop2 POD+AI access, immutable reviewed task/model/connector/credential-metadata pins, disabled-by-default image policies, bounded task/engine selection and the versioned `openai-images-gpt-image-1-v1` request/response contract.
- Fixed OpenAI generation/edit endpoint transport with exact immutable body/URL/content-type/model/source binding, timeout/size/encoding restrictions, no redirects, server-side key custody boundary and single-use durable dispatch claim. No secret is accepted from an operator request or returned in evidence.
- `ImageGenerationExecution::prepareDesign(shop,specHash,policyHash,run,parentJob?)` and `::prepareTransformation(shop,key,policyHash,run,parentJob?)` build source-linked original-artwork or five-engine edit requests. `::run(shop,id)` reserves an inclusive exact-request maximum through existing monetary governance before dispatch. Task, run, day, month and render-stage ceilings are enforced; no competing cost ledger was added.
- Sealed immutable journal with CAS event history, checked InnoDB writes/audits/commit/release, terminal uncertain outcomes, explicit cancellation and lock-protected interrupted recovery. No automatic retry or refund is inferred; replacement attempts retain previous exposure.
- Observed image `req_` IDs, request-bound provider model, registered routing identity, input/image/text/output usage and raw-response digest are retained independently of text `resp_` records. Simulator usage cannot be settled as actual spend. Real charges still require original reviewed provider evidence.
- Actual validated retained PNG candidates remain unapproved raster, not editable SVG or print-certified art. `linkDerivative(shop,id,sourceSha,payload,confirmed)` imports a separately versioned human-adapted SVG with exact generated-source lineage through the existing factory importer. Existing nine-criterion human review and immutable artwork approvals remain separate.
- Existing research/AI consent now pins the selected connector and public credential generation metadata. Rotation/replacement before confirmation invalidates prior consent. No ciphertext is read by this check. This is not dispatch-time fencing.

Normal portal: use Shop2 Research → saved selected concept → source-linked concept approval → specification. Existing registered `digiforge_commercial_research` action/nonce now offers typed native-region rows, SVG/PNG uploads, typed disabled-policy review, immutable image-request preparation, quote-document upload, held status, candidate preview, cancellation/recovery and manual SVG derivative intake. No raw JSON is required for normal design fields. Provider-issued quote JSON is imported as its original evidence file, not pasted configuration. No additional POST handler, plugin registration or duplicate artifact repository was introduced.

## Deliberate blocks and remaining software

NETWORK is **unsupported by default**, not live-qualified. The default service has no `ImageGenerationCredentialCustody`. It denies before reservation/key access/HTTP; the default portal states `NETWORK_CUSTODY_UNAVAILABLE` and keeps dispatch disabled. A future server-only custodian must coordinate every credential rotation/delete, connector-disable, authorization/STOP-revocation and source-mutation writer, retain the exclusive generation lease through dispatch and check release. Optimistic repeated reads or a lock ignored by writers do not satisfy that interface. No shared-writer or other-lane source was modified to pretend this dependency is complete.

The server constant `DIGIFORGE_IMAGE_NETWORK_EXECUTION_AUTHORIZED` is absent/false by default and is necessary but insufficient. Policy enablement and released effective safety settings are also insufficient without the reviewed custodian. No task in this batch enables them.

Only 1024x1024 PNG output fits the existing 1,048,576-pixel local verifier. Larger API sizes are rejected before dispatch rather than expanding existing limits. Current source engine pixel/byte/time limits cannot be exceeded by an image policy. Provider PNG encoding compatibility and genuine account/model permissions remain unqualified.

Five engines can produce governed unapproved raster candidates via the executable edit abstraction. They do not yet automatically adopt those unpredictable outputs into the old predeclared-result-hash transformation acceptance workflow, and no synthetic output is treated as licensed commercial work. Style-specific independent visual qualification and native-field/print-geometry adaptation remain separate. The original-artwork SVG derivative route is functional but human-declared, not an automatic vectorizer or visual-fidelity guarantee.

Durable synchronous manual dispatch/recovery is implemented; a recoverable asynchronous worker and safely governed automatic retry are not claimed complete. Existing research acquisition/scoring/three-concept services are reused; this bounded consent fix is not a new campaign or blanket acceptance of their entire orchestration. Full research dispatch custody and asynchronous recovery require their own source-owner integration.

Photo owner-current-read repair is documented separately in `customer-photo-current-read-contract.md`: autocommit reads and participating producer writers are fenced, while caller-owned transactions deny without being reset. This is not Lane B transaction compatibility or final held-order consumer acceptance.

## Evidence and acceptance

Focused tests use real disposable WordPress/MariaDB persistence and server-injected final offline transports. Public endpoint/model/request paths are exercised without any paid or live provider operation. HTTP harnesses use actual PHP uploaded files and the existing processor, synthetic signed ACL, and measured zero outgoing WP HTTP. They do not claim authenticated browser-session/upstream nonce round-trip acceptance. Mobile/keyboard DOM checks use real WP markup loaded with setContent, explicitly not a live authenticated journey.

The final evidence archive contains exact commands, initial RED failures, final GREEN logs, source hashes, patch and independent bounded reviews. Tests cover request substitution, missing/expired route evidence, source/credential revocation, malformed images and metering, independent budgets, cancellation, no replay, one-use claim-root loss, unconfirmed claim COMMIT, interrupted dispatch and recovery contention. The independent review found and reproduced a request-substitution P1 before its exact-wire fix; that failure remains in the receipt.

No capability in the 151-row ledger is promoted. Last coordinated count is 32/151 accepted; this is not an independent recount. S2-ART-14 and S2-ORD-06 remain unpromoted. Implemented transport, offline execution, human artwork approval, live model qualification and provider/production authority are distinct.

All STOP ALL/external-lock/automation-OFF/manual-approval/RELEASE HOLD controls remain. No merge, deployment, live AI/Printify, Etsy publication, order, credential exposure or production write is authorized.
