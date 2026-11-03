# Shop 2 WordPress Printify research adapter

Workstream authority: owner-supplied Dedicated Printify WordPress API Integration Blueprint v1.0, authenticated Drive file `1zLxoFaW6-99C6_pYExjpgbHitBvHJJXb`, read 2026-11-03. Continue draft #1253 and its existing branch. Starting head `5fffed9213f430d587521c4b28ab3d94ed7bc5c8`; main `0e15e4ccae430498c92a30ab1f8951171fa0ad13` reverified.

## Critical integration dependency

The existing `PrintifyCatalogReadRepository`, policy, contract, scoped client, reviewed ownership and ShopAccess controls are in cumulative draft #1242, verified head `fac3634615c72ffdb0fdbce8ef1fbd3ae3c9e4c0`. They are absent from #1253/main. Their catalog repository/policy/client bytes match the inspected local cumulative checkout. This lane does **not** copy or weaken those shared authorization/ownership files, migrate their tables, or claim they are deployed. The new gateway reports `GOVERNED_PIPELINE_UNAVAILABLE` and denies collection when they are absent. Integrating that dependency requires coordinated file ownership; this candidate is an adapter/evidence implementation, **not a standalone live-ready catalog pipeline**.

No global REST registration, shared navigation, database migration, scheduler, provider client, grant issuer, exception issuer or ownership class was changed. The already-owned Product Intelligence panel registers its narrow adapter routes and forms. No other factory lane was integrated.

## Implemented interfaces

| Capability | Behavior |
| --- | --- |
| Governed gateway | Reuses `targetShopForGrant`, `research` and `evidence`; requires existing ShopAccess, account binding and exact Shop 2 grant. Never reads credentials or calls HTTP directly |
| Blueprint/provider/variant evidence | Maps immutable verified receipts into dated research rows; original concept lineage is retained |
| Print areas | Retains pixel geometry and explicitly supplied DPI/bleed/safe-zone evidence; never certifies physical geometry |
| Size guides | Maps ordered sizes/types/ranges with nullable bounds. Exact documented descriptor is `/v1/catalog/blueprints/{id}/size_guide.json`; older shared grant contract does not allow it, so current live collection is unavailable rather than bypassed |
| Shipping | Service indexes remain distinct from destination quotes. Raw amount/currency retained without invented units, conversion, costs or tax |
| Resumable provider discovery | 1–100 blueprint IDs already present in the selected snapshot; one explicit step per invocation, existing grants at most ten requests each. No grant issuance, automatic retries, jobs or schedules |
| Receipt ingestion | Server fetches an existing audited receipt by grant/request hashes; caller cannot submit an arbitrary provider response as a verified receipt |
| Portal | Existing Shop 2 panel shows dependency readiness, bounded preparation, explicit collect/resume, batch status and provider/source/missing-evidence cards. Collect button disabled without the governed pipeline |
| Export | CSV provenance is per row, including source hash/time and correct API/workbook origin. Full JSON export can be converted locally into literal-cell XLSX with source/binding sheets |

New routes under `/wp-json/digiforge/v1/shops/personalized_pod/printify-research`:

- `GET /readiness`
- `GET /snapshots/{snapshot_sha}?section=providers&query=...&page=1`
- `POST /receipts` with exactly `snapshot_id`, `grant_sha256`, `request_sha256`
- `POST /batches` with exactly `snapshot_id`, `blueprint_ids`
- `GET /batches/{batch_sha}`
- `POST /batches/{batch_sha}/step` with exactly `grant_sha256`, `confirm_catalog_get: true`

All require logged-in existing portal/POD/research/connections capabilities and `X-WP-Nonce` for `wp_rest`; installed ShopAccess assignments are additionally enforced. No dynamic shop route, caller URL, credential or grant body is accepted. Private/no-store responses, 8-KiB mutation bodies, bounded searches/pages and exact fields. Existing grant/exception routes remain owned by the shared pipeline. Main-site deployment, configured reviewed connection, owner grant, safety exception where required, reservations, audit and rate budget must all pass **there** before any provider GET.

Portal forms require their own WordPress nonce and exact `personalized_pod` scope. Preparation only writes a local plan/audit. Collect/resume requires explicit confirmation and a pre-existing grant; it cannot change safety posture. Do not submit a live collection during this engineering task.

## Interruption, idempotency and evidence

Plans and start/completion markers are immutable non-autoloaded options with content hashes and required audit anchors. Batch IDs bind the actor, source snapshot and blueprint list. Completion binds grant, receipt, request, parent snapshot and sequence. A batch pins the integration across its grants. A database read failure, deleted anchored marker, missing/duplicate audit, corruption, conflicting grant or unknown dispatch outcome stops processing.

A durable start marker is written before calling the existing pipeline. The deterministic attempt key survives interruption. Resume calls only `evidence()` for that key; it never repeats a consumed request. Successful receipts feed append-only Product Intelligence versions. If no receipt exists, reconciliation is required, including after HTTP429. Completed steps are skipped. Each explicit invocation can collect at most one endpoint. The existing repository's account rate reservations and ten-request grant ceiling are unchanged.

Batch collection uses `research(..., maxAge=0)` so an unrelated cached receipt cannot substitute an earlier attempt hash and break crash recovery. Existing pipeline caching is untouched for other callers. Coordinator completion/replay avoids duplicate calls within the batch.

Mixed-source research rows carry receipt/grant/request/raw-response hashes, source ID, endpoint, integration, environment, observation timestamp and origin. Source identity is checked per row. Sources are bounded at 200 to retain a bounded provider batch alongside original research; the research row/byte limits remain unchanged. **This does not raise the ten-request grant ceiling.** Every resulting snapshot remains UNREVIEWED with all production/Finance/external authority false.

## Offline XLSX of API evidence

Export **research JSON** from the existing panel, then run:

```sh
python3 bin/product-intelligence.py /private/shop2-research.json --packet --output /private/verified-export.json --xlsx /private/shop2-research.xlsx
```

Packet row hashes and source bindings are checked before export. Spreadsheet cells remain literal strings; source and section-binding sheets retain provenance. Existing authenticated Drive download → offline converter → local portal JSON import remains supported. No Drive file is overwritten or uploaded; write-back still needs separate explicit authorization.

## Validation and remaining gates

Focused tests cover endpoint confinement and unsupported operation/URL denial; provider/variant/pixel/size-guide/shipping unknowns; protected keys; REST capabilities/nonce/fixed shop; interrupted recovery; rate-limit stop; missing audit/marker denial; source-backed plans; per-row export provenance; and absent-pipeline fail-closed behavior.

Supplementary disposable WordPress tests exercised this gateway against #1242's unchanged real repository/policy/client with `pre_http_request` returning synthetic responses: exact GET URL, redirects disabled/TLS verified, durable receipt validation, one-request quota, HTTP302 and HTTP429 rejection without automatic retry. Two tests / 22 assertions passed. No provider request, credential or real grant was used; all approvals/reservations in those tests are synthetic local fixtures. This supplementary harness remains outside application code because it depends on the separate cumulative checkout. The normal candidate CI does not silently import that checkout.

Mobile tests expand the new preparation controls at 390/768/1280px and verify disabled collection without the pipeline. Exact final test counts and hosted audit are recorded in PR #1253.

Remaining production blockers: coordinate/integrate the shared governed pipeline and reviewed ownership dependencies; extend its size-guide grant scope under that lane's ownership; verify installed-site configuration and main-site binding; separately approve any actual grant/exception/internal runtime writes and live provider calls. Historical supplier gaps (including 84 provider-discovery gaps) have not been refreshed: no new provider facts, costs, tax, destination rates or certifications were fabricated.

STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD. No artwork/editor/mockup/listing/SEO/order/fulfillment/Finance work, production migration, merge, deployment or activation.

## Shared-dependency handoff

The exact pinned dependency matrix, two composition probes, conditional global-admission mappings and smallest owner approval are in [PRINTIFY-SHARED-DEPENDENCY-MATRIX.md](engineering/PRINTIFY-SHARED-DEPENDENCY-MATRIX.md). API readiness now checks transitive methods and distinguishes narrow-core compatibility from unmapped cumulative guards. No shared implementation is copied or approved by that diagnostic.
