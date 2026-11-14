# Digital product portal: bounded approval and registry corrections

Baseline: draft #1235, `5001ed03f844055b659a0a48d4cc6c0f10f940ce`, existing audit #4771. Draft-only follow-on; no source PR changes. STOP ALL ON, external lock ON, automation OFF, RELEASE HOLD, NO-GO.

## Reproduced defects and changes

- Product-only Digital operators following **Open Product Approval** were denied because Approval Inbox required research permission. Two genuine shortcode regressions failed before the fix. Admission and navigation now accept separately assigned product/research authority. Research cards retain independent research capability and selected-shop assignment checks; Product Approval retains its existing Gate 2 checks.
- Mixed product/research assignments must not manufacture global authority. A regression reproduced global readiness/All Shops visibility from their union; global admission now requires one capability independently assigned across all four shops. Foreign and revoked assignments remain denied.
- Digital registry counts used APPROVED/RELEASE_READY/ACTIVE, none of which are DigitalFactory lifecycle states. The bounded count now reports the four actual post-review registry states explicitly as **registry** progress. Registry flags cannot grant Product Approval or release authority. Empty, partial, unknown and non-boolean flags stay UNVERIFIED; even a complete registry displays REGISTRY COMPLETE — UNVERIFIED instead of PASS.

No migration, permission grant, ProductReview/release-bundle change, provider operation, Finance edit or live activation is included. DigitalFactory registry states remain advisory; independent ProductReview, Production and ListingFactory gates remain authoritative. The Digital CRUD REST API still requires its existing global assignment guard; no broader REST authority is introduced.

## Verification

- Focused unit: **24 tests / 196 assertions**, including existing Digital, Etsy delivery and zero-activity Finance boundaries.
- Focused WordPress: **49 tests / 1,452 assertions**. Covers new shortcode/projection regressions plus existing four-shop access, two-shop portal, Digital design/release-to-listing, customer-versus-marketing ZIP separation, stale approval, idempotency, delivery evidence and protected workflow behavior.
- Existing authenticated local browser suite: **194 checks PASS**, Chromium 154.0.8037.57, widths 390/1280, 414 local requests; zero external requests and zero business-row mutations. Synthetic local review/audit records only. This is not installed-site acceptance. The suite ran after the admission/registry fixes; the later malformed-registry change is additionally covered by the focused WordPress regressions.
- Initial broader focused run used a PHP image without ZipArchive: two related test failures. Reusing the existing WordPress PHP image with ZipArchive resolved the prerequisite, without test/configuration changes or skips.
- Final exact-head hosted audit and package/security results belong in the PR/issue receipt; no unobserved result is asserted here.

## Acceptance and catalog boundary

Non-Finance remains **246/268 implementation candidates, 22 UNVERIFIED**; all **23 Finance-dependent criteria remain UNVERIFIED**. No authentic provider, commercial or installed-site acceptance is promoted. Existing candidate criteria BP-portal-2-approvals and BP-portal-12-human-approval-inbox gain targeted local regression evidence only.

Master 500 retains all 500 identities: its Physical Product column is PP-001 through PP-020 or V2-PRODUCT-RESEARCH, not a separate Digital launch catalog. The existing 360 bounded recipes / 140 artistic-execution-blocked classification is unchanged. Do not manufacture a Digital subset or claim demand from that catalog. The existing deterministic text/PDF/structured/SVG generators and customer ZIP separation are capabilities, not commercially reviewed first-launch products.

## Smallest authentic inputs still required

1. Select a Digital opportunity with attributed research and exact original customer deliverables; provide asset/font rights and independent content/visual/commercial/IP/policy reviews against immutable revisions. No fictional launch-demand or rights claim is supplied by this batch.
2. Review the exact customer ZIP and marketing assets through existing Product Approval and Listing Approval. Preserve source bytes/hashes and stale-version invalidation. Local listing preparation remains REVIEW_REQUIRED, not a live Etsy draft.
3. Supply current verified Etsy ownership/authorization and separately authorize any future external draft/file-attachment verification. Confirmed CREATE_DRAFT and UPLOAD_FILE evidence is still required; no buyer-download evidence is inferred.
4. Separately authorize installed-site desktop/mobile operator acceptance; the disposable local browser result does not certify the deployed website.
5. Owner-confirmed zero paid orders does not establish zero expenses or profit. Existing zero-activity Finance regression keeps fees/costs NOT_VERIFIED and approvals unauthorized; authentic Finance evidence and reviewed decisions remain outstanding.

Supplier geometry, native Printify behavior, commercial rights, linguistic/artistic review and production authorization remain independent blockers. No new Printify research or FC01 asset change was necessary.
