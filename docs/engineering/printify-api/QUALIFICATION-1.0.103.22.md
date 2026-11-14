# Printify API .22 upload and support qualification

Baseline: PR1260, `f02db3ab61eab24fb9d6e2e1d76645142a423a91`, qualified .21. Continue branch `feat/printify-api-completion-20261108`. No changes to PR1258/1259, production, credentials, schema, historical reservation22 or external execution settings.

## Governing evidence and scope

The authenticated Master Blueprint v6.0 and Development Operating Manual copies were retrieved again on 2026-11-09. The manual is a proposed draft; explicit current owner restrictions prevail. Official `https://developers.printify.com/` documentation was retrieved the same day. Examples are provider-documented contract fixtures, not an official OpenAPI validator or live evidence. The preserved .21 qualification explains the four restored geometry fixtures; this candidate keeps every assertion and test enabled.

The [47-operation ledger](operations-1.0.103.22.json) replaces the seven previously unimplemented upload/support admissions with tested implementations. SOFTWARE_COMPLETE_OFFLINE refers to this governed blueprint boundary, not every current/future public Printify endpoint, live acceptance, inactive-shop activation or commercial certification. Final test and artifact receipts must accompany this source document. No operation has LIVE_READ_VERIFIED status in this mission; consequential operations remain LIVE_WRITE_UNTESTED.

## Upload boundary

`PrintifyUploadContract` implements GET `/v1/uploads.json?page=N&limit=L` and POST `/v1/uploads/{image_id}/archive.json`. The list retains documented pagination; provider URLs are never followed. Archive requires the documented empty 200 acknowledgement. Both operations require an explicit bounded set of successful local image-upload/read receipt references. `PrintifyUploadOwnership` resolves the current immutable approved package, plan, exact integration/account/provider shop and authorization binding. Raw account-wide assets are never disclosed: list projection exposes only IDs established by those references. Unknown/foreign images and private preview URLs are omitted. Empty pages do not prove archive success, and callers cannot substitute an image ID for ownership evidence. Existing upload bytes and integrity validation are unchanged.

## Support boundary

`PrintifySupportContract` implements the five documented `/v1/shops/{shop}/orders/{order}/support-requests` operations: list, detail, reprint, refund and address-change. These paths use hyphens and no `.json` suffix. The body and acknowledgement follow the documented reason, description, image URL, line-item and address contracts. Address-change accepts the documented immediate-update 200 or support-request-created 201 discriminator; immediate-update fields must match the reviewed address. Projection omits raw addresses, attachment URLs and provider status detail. It retains bounded IDs, type, source status, normalized status/outcome and timestamp. No financial refund, time window, cancellation or delivery guarantee is invented.

Support writes require a successful exact ORDER_READ receipt no older than five minutes, current account/shop/integration binding, and exact observed line IDs and quantities. The provider remains authoritative for eligibility not expressed by documented contracts. A local admission never proves provider eligibility. Image attachment URLs must be explicit reviewed HTTPS public destinations; DigiForge does not fetch them. Private/local destinations, userinfo, fragments and nonstandard ports are denied.

## Security, uncertainty and storage

New operations use the existing LifecycleWorkflow, independent reviewed plan policy, maximum one physical request, approval nonce, native capabilities, exact DigiForge shop/module authorization, connection vault, STOP ALL/external lock and shared account quota. `PrintifyLifecycleSources` re-resolves source dependencies before authorization, before dispatch and after response. Revocation or dependency changes block dispatch or yield UNKNOWN after dispatch. No alternative transport is introduced.

Permanent operation claims for archive/reprint/refund/address-change survive timeout, new plan approval and connection revision. There is no automatic mutation retry. A timeout consumes the attempt. A later list containing a plausible matching support request cannot establish causation for an unknown create; exact provider correlation is still required. Known response IDs may be reconciled through later exact audited list/detail evidence. Absence on one upload page never finalizes archive. Original receipts and historical reservation22 remain untouched.

Existing encrypted local plan/receipt, option/advisory-lock, audit and operation-claim storage is reused. No migration or database marker change. Existing all-InnoDB compatibility assumptions still apply; no production database was inspected or changed in this mission.

## Stable integration handoff

PR1259 continues to consume the existing PrintifyApiReadWorkflow, ProductPipeline, LifecycleWorkflow and controlled order transport. No personalization/artwork source is imported. New LifecycleWorkflow operations are `UPLOADS_READ`, `IMAGE_ARCHIVE`, `SUPPORT_LIST`, `SUPPORT_DETAIL`, `SUPPORT_REPRINT`, `SUPPORT_REFUND`, `SUPPORT_ADDRESS_CHANGE`.

Upload inputs include `image_receipts:[<sha256>]` plus page/limit or image_id. References are local provenance and never enter the provider body. Support inputs include exact `order_id`, optional `request_id` for detail, and the documented reviewed request fields. Consequential support preparation additionally supplies the fresh audited ORDER_READ receipt through the existing read-receipt argument. No caller-owned provider identity, arbitrary endpoint or unsanitized response can become authority. Existing scope/review/execution signatures are unchanged.

Shop2 and Shop3 mappings stay distinct. Existing DEFERRED_SHOP policy continues to block Shop3 operational execution. Upload ownership currently uses the existing Shop2 approved-package workflow and does not activate shared-account asset access for Shop3. Native provider scope, live token entitlement and account ownership remain externally unverified.

## Package preservation and release gates

The builder consumes the exact qualified .21 ZIP (SHA256 `7c50fd5a810b6b0a8fcbb790c90deafb9f91cf999c2bca6933f0149b2c798e7c`, 3,243,561 bytes). It checks each modified installed preimage against baseline source, then makes four reviewed modifications and four additions: 752 files retained byte-identically, zero deletions, 760 members. Stronger installed shared components remain preserved. Hashing and ZIP parsing consume the same bounded immutable input bytes. Final manifest records every original and resulting member hash and source correspondence; this is a preserved installed-lineage composition, not an exact repository checkout.

Required external acceptance: explicitly authorized governed GETs for actual credential/account/shop/scopes, provider pagination/response evidence, current exact product/variant geometry and order readback. Writes require separate human approval after safety gates permit them; none ran here. Provider support eligibility, refund outcome, delivery, webhook registration and supplier certification cannot be established offline. Shop3 activation is separate. Source CI, local extracted-package qualification and hosted exact-ZIP certification are distinct; hosted exact-ZIP audit is NOT_RUN unless an actual digest-bound run is recorded.

Installation remains unauthorized under RELEASE HOLD. Require current installed-source/schema compatibility and separate owner installation approval; recovery qualification is excluded from this assignment. STOP ALL ON, external safety lock ON, automation OFF, activation unauthorized remain unchanged.
