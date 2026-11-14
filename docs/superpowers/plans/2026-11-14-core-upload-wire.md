# Core-owned upload wire preparation implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline. Implement test-first and keep native authority in core.

**Goal:** Prepare a new inert upload intent whose exact wire body matches current core source without rewriting any previous intent.

**Architecture:** Preserve legacy UploadContract::prepare unchanged. Add a strict core descriptor validator and additive prepareFromCoreDescriptor path. MutationIntent reconstructs either supported preparation. No descriptor/self-hash confers authority; runtime current-source/association/approval verification remains core-owned.

**Tech Stack:** PHP8.3, existing companion autoloading, PHPUnit9, existing ArtworkContract and immutable MutationIntent.

**Spec:** Owner 48-hour sprint directive; agreed core DF_NATIVE_IMAGE_UPLOAD_WIRE_1 descriptor in CORE_WIRE_UPLOAD.md.

## Global constraints

No sender, credential vault, mapping, approval store, scheduler, production change or policy exception. STOP/lock/RELEASE HOLD remain. Existing default filename behavior remains.

## Review focus

- Rehashed wrong-shop/actor/asset descriptors must fail before intent creation.
- Changed filename/body with old approval must not be normalized into equivalence.
- Self-hashed descriptor must never be labeled authenticated or executable.
- Malformed/oversized/executable metadata must be rejected without invoking callbacks.
- Existing upload preparation/receipt behavior and stable duplicate identity must remain.

### Task1 — Additive exact-wire construction and receipt observation

Files: new Preparation/CoreUploadWireContract.php; modify Preparation/UploadContract.php, Preparation/MutationIntent.php, Preparation/PreparationService.php; test tests/unit/PrintifyCompanionCoreWireUploadTest.php.

Interfaces: CoreUploadWireContract::validate(array $descriptor,string $scopeFingerprint,int $actor,string $assetSha,string $contents):array; UploadContract::prepareFromCoreDescriptor(ShopBinding $scope,array $asset,string $bytes,array $descriptor,int $actor):array; PreparationService::uploadFromCoreDescriptor(ShopBinding $scope,string $bytes,array $evidence,array $descriptor,array $context):MutationIntent.

- [ ] Write literal synthetic descriptor/PNG tests proving legacy mismatch, exact core wire hash equality on new intent, no alteration of previous intent and no authority grant.
- [ ] Run focused PHPUnit; observe expected missing additive method assertion failure.
- [ ] Implement strict descriptor validation, derived canonical filename, fixed POST upload path and immutable wire hash match. Validate rights/geometry through existing ArtworkContract; preserve duplicate identity across naming paths.
- [ ] Extend immutable intent reconstruction and metadata-only receipt observation for this explicitly versioned preparation; reject modified bytes/descriptor/name/context.
- [ ] Run positives and data-driven negatives: wrong scope/actor/asset/version/path/hash, malformed fields/objects, rehashed manipulated descriptor, wrong provider response filename, UNKNOWN timeout/no retry, unchanged legacy path.
- [ ] Integrate against actual approved new core descriptor once core source is available; do not count synthetic descriptor fixtures as native approval.
- [ ] Run complete unit/WordPress/offline/static/security and exact-package/actual-core profiles; update existing PR only after verified results.
