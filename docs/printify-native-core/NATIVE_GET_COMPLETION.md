# Canonical native GET completion

Baseline core:9e9094097992453a8cb6ecddd7f936c3584eccef. Unchanged actual companion:e31c20bd38dec08846ad99f5b474c4f78b6e8b18. Canonical Batch6 spec digest remains6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434. This is offline native software qualification, not operational acceptance or installation authorization.

## Source composition and identity

The thirteen operations are SHOPS_READ, BLUEPRINTS_READ, BLUEPRINT_READ, PROVIDERS_READ, PROVIDER_READ, VARIANTS_READ, SHIPPING_READ, PRODUCTS_READ, PRODUCT_READ, ORDERS_READ, ORDER_READ, UPLOADS_READ and UPLOAD_READ. Existing seven plan preparations are unchanged. The six additional operations use server-side PrintifyApiReadWorkflow::prepareNative(intent, catalogGrantHash, requestIndex), followed by the existing independent approval, authorize() and bindNative() steps. There is no new browser/REST route, generic sender, approval system, vault, provider mapping or schema migration.

Canonical GET identity/version evidence is the sealed immutable plan/request hash, exact source snapshot, current independent policy revision/hash, connection review revision/binding and actual WordPress actor/session. Product details additionally retain the independently approved blueprint/provider/variant selection. Uploads bind actual product plan/package/workflow/receipt hashes. Catalogs bind the actual grant, exact request index/kind, integration/account/site/ownership snapshot. These are read observation identities, not fabricated numeric product/order versions or trusted mutation receipt producer/fence/checkpoint evidence. Those separate ports remain denied.

Catalog grants remain mandatory and are not replaced by a generic connection grant. Native admission reuses PrintifyCatalogReadRepository's active grant, ordinary safety posture, scope, immutable reservations, audit anchors, per-grant budget, shared account rate slots and credential fingerprint checks. Main-site/production restriction remains. The new native reserve method adds only an admission into that existing ledger. Catalog sends have one attempt; no catalog safety exception is consumed by this path. Legacy single-variant and whole-list grants share a URL but differ in authority: preparation now rejects mismatched kinds before reservation.

Owned-upload source references are real local IMAGE_UPLOAD/IMAGE_READ receipts resolved through PrintifyUploadOwnership, never caller-asserted image IDs. Current ownership is checked during preparation, before credential use, before every send and after response. Detail IDs, dimensions, bounded pagination and metadata are validated; account-wide results disclose only owned images. Existing restriction of product upload ownership to personalized_pod remains; standard_pod cannot borrow those receipts.

All native responses now use canonical ReadProjection. The class is reused byte-identically from the reviewed companion branch's existing shared source (SHA2569f932c6e6b4b098f9ae04182fbce572d803c5847efb6ccc9a00e057bc3d4d6f3). Original seven normal-core projections/receipts remain unchanged; canonical projection is applied only at native output and its hash is recorded in the native receipt. Source authority is rechecked after projection. No customer addresses/email, raw upload URLs or credential material is disclosed.

## Safety and one-use behavior

Existing live session, fresh owner, signed delegated scopes, native capabilities, exact reviewed provider/credential tuple, strict SQL one-use claims, audit-before-ack, nonce ledger and normal STOP/activation locks remain mandatory. Expiry maximum300seconds and policy revisions are rechecked per attempt and after response. No GET_READ capability alone authorizes execution. Emergency/revocation continues to deny normal reads. This is not the proposed acceptance-only exception and cannot run under STOP ALL/external lock.

Existing normal safe-read retries remain bounded; 429/Retry-After stops. Catalogs send once. Malformed/uncertain data, lost source or audit/persistence failure after attempted send produce core UNKNOWN with retry=false and do not restore claims. The unchanged companion converts core WP_Error into its bounded denial; detailed UNKNOWN evidence remains in core receipts/audit rather than a fabricated successful ProviderResult.

## Qualification and capability decision

New tests exercise all13 actual native operations. Source-only runs test direct core admission and deny unbuilt/pinned companion use. Extracted, source-stamped runs invoke the unchanged actual companion NativePortAdapter with the explicit reviewed candidate SHA and require GET_READ in the exact manifest. The companion's default runtime pin is not modified or overridden by a request. A future separate companion review must decide that pin.

Existing one-use/expiry/revocation/security tests, all seven prior positive cases, PANEL and mutation/recovery denials are preserved. Supplemental tests cover missing source grants, wrong grant kinds, shared quota, in-flight revocation, malformed catalog responses, Retry-After, owned-upload filtering, wrong image identity, malformed dimensions and lost source receipts. The catalog tests run in the mandatory production profile; local/production configurations together execute every WordPress test file exactly once. No failing group is excluded.

The preservation guard retains historical source provenance and verifies that removing exactly the new native admission/reservation methods reconstructs the original catalog repository SHA. It also pins the extension bytes. This records an authorized code extension rather than falsely claiming the new file came unchanged from the historical package.

GET_READ advertisement is gated by the complete offline conformance run; PANEL remains present. Exact source/CI, full source/catalog and extracted-package counts are recorded in the consolidated PR qualification receipt. Synthetic credentials/responses are used only in a no-network disposable test environment. Passing local tests are not live connectivity, hosted durability or complete Printify business certification.

## Remaining gates

No installation or production provider request is authorized. Companion default pin review, production source equivalence, exact reviewed provider/account/credential/scopes and shop binding, operational policy and installation approvals remain external gates. Canonical mutation/journal/receipt-producer/publication/order snapshot/allocation/recovery and independent anti-rollback/custody guarantees remain separate unimplemented or unqualified work. Historical reservation22 is untouched. STOP ALL ON, external lock ON, automation OFF, RELEASE HOLD.
