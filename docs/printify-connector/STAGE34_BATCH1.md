# Companion Stage 3/4 Batch 1 — offline preparation only

Companion 0.2.0 adds preparation interface 1.0.0 alongside the unchanged GET ConnectorInterface 1.0.0. No provider sender, native mutation host, routes, queue, credential store, mapping store or approval store is added. STOP ALL, external lock, automation OFF and RELEASE HOLD remain unchanged. PR1264 is outside this change.

## Implemented

- PNG bytes, immutable SHA-256, exact shop/integration scope, 300 DPI, pixel dimensions, decoded-size limits, CRC, bleed, safe zone and content bounds validation. Rights, ownership and geometry receipt hashes are required claims; only DigiForge can resolve their authenticity.
- Official upload contents request and minimized metadata reconciliation. Provider dimensions and filename must match. Provider content and commercial rights remain uncertified.
- Product create/update request preparation using DigiForge's existing PrintifyProductRequest, exact blueprint/provider/variant evidence, positive integer prices, owned artwork, placement bounds, title/description and current-version checks. PUT excludes immutable blueprint/provider fields under the official schema.
- Immutable mutation identities and payload fingerprints. Physical-scope duplicate keys survive authorization revision changes. Product logical references must come from the core product registry, never browser-selected aliases.
- Optional CoreMutationHost port for current authority, audit, evidence resolution, durable registration, atomic one-shot fencing and trusted response receipts. UnavailableMutationHost is the default. A fence never permits network execution. UNKNOWN never restores a slot or authorizes retry.
- Synthetic file-journal tests cover reopen, conflicting products, concurrent claims, failed writes, lost acknowledgement, locks, revocation, timeouts and malformed responses. This test journal is not shipped as a production adapter and proves no hosted durability.

## Core integration requirements

The core owns actor/session capability checks, credential/account association, exact shop mapping, independent approval, current product version, evidence lookup and a durable audited operation registry. Its future adapter must validate authority atomically with claim, enforce physical duplicate uniqueness, retain immutable history, reject replay and require a separately reviewed final sender. Missing audit persistence denies admission. No current adapter or dispatch permission is supplied here.

The existing .17 product request class is reused, not copied into companion runtime. A test-only snapshot at tests/fixtures/printify-connector/stage34/CoreProductRequest.php has SHA-256 211cbd8d27dace59661786be31b0b17f9d2344dc242a11b4ddf90c62afee7084. Main's missing class causes explicit fail-closed preparation. The existing core raster validator is reused. No schema migration is required.

## Contract evidence and limitations

OFFICIAL_STAGE34_CONTRACTS_20261112.json records the current official OpenAPI excerpt for the three request paths, obtained from https://developers.printify.com/openapi.json. No authenticated provider request was made. Real product responses have shop_id and visible/is_locked fields; no invented draft status is required. Metadata readback is not proof of unpublished status or provider content integrity.

FL-03/FL-04 known commercial labels and dimensions remain blocked examples. The R4 qualified package, authentic layer/rights/geometry receipts, numeric blueprint/provider/variant identities and reviewed upload ownership are unavailable. Synthetic IDs are confined to tests and cannot certify those products. JPEG/SVG, rotated placement, native editable-text rendering and real provider dispatch are not implemented in this batch.

## Verification and next batch

Run focused PrintifyCompanion tests, complete unit and WordPress suites, offline tests, configured static/standards checks and exact-head hosted Engineering & Safety CI. The package builder now recursively includes preparation classes in the test-only companion ZIP; prior test packages remain unchanged. No installation-ready package is asserted.

Batch 2 should implement the core-owned mutation-port adapter with transactional uniqueness, audit/outbox and final-sender admission tests in coordination with the independent core lane. Then add trusted receipt/import and asset lifecycle UI, preserving Stage 2 authorization as a separate gate. Do not activate live writes or policy exceptions.
