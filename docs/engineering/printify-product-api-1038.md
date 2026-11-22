# Printify product API and Etsy handoff — 1.0.103.8

References inspected 2026-11-06: [Printify API](https://developers.printify.com/), [official OpenAPI](https://developers.printify.com/openapi.json), [Etsy physical draft listing tutorial](https://developers.etsy.com/documentation/tutorials/listings), [Etsy processing profile migration](https://developers.etsy.com/documentation/tutorials/migration).

The current Printify documentation DOES expose native personalization/configuration and asynchronous preview APIs. Earlier manual-only assumptions are superseded. Creating/editing native field layouts in the provider editor is still manual where no documented endpoint exists. No endpoint or provider eligibility is invented.

| Capability | Implemented route / behavior | Required external evidence |
|---|---|---|
| Approved artwork upload | POST `/v1/uploads/images.json`, bounded validated base64 PNG from the approved design's protected raster storage; no arbitrary URL upload or automatic SVG conversion | Current account-level media permission and independently reviewed Shop 2 product connection |
| Image readback | GET `/v1/uploads/{image_id}.json`, ID from same-package sealed upload/read receipt | Actual successful upload/read receipt |
| Product creation | POST `/v1/shops/{shop_id}/products.json`, exact selected blueprint/provider/variant, operator price in minor units and explicit placement of receipt-bound image | Real uploaded image ID, actual configured shop, explicit reviewed request |
| Variants and print areas | Creation binds selected catalog geometry; PUT `/v1/shops/{shop_id}/products/{product_id}.json` preserves the complete readback variant set and changes the selected price | Current matching product receipt; other variants retain actual known values including disabled zero prices |
| Product/mockup readback | GET product endpoint projects variants, print areas and provider image URLs | URLs are observations, never automatic image-byte download or visual certification |
| Native options | GET product `/personalization_options.json` binds real field IDs, text/photo types and character limits | Product has native layers configured; unknown text limits fail closed |
| Native text/photo configuration | POST product `/personalization.json`, exact field set, `require_all_personalization_fields=true`; text bounds and receipt-owned HTTPS image URLs | Actual field options and photo upload/read receipts; buyer-approval and order fields are excluded |
| Personalized mockups | POST product `/personalization_previews.json`; explicit GET `/personalization_previews/tasks/{task_id}.json` polling | Account/blueprint feature support; pending/processing/failed/corrupt remain distinct; no scheduler or retry |
| Etsy draft handoff | Existing `EtsyDraftListingOperations::create()` receives approved-package listing data and explicit physical-listing metadata, including shipping and `readiness_state_id` | Verified assigned Etsy shop; profile ownership remains unverified until accepted; no draft submission or publication performed |

## Source authority and execution

Preparing a local plan performs no network operation and grants no authority. The plan may be an explicitly UNVERIFIED local preview if the separately reviewed product connection is absent. Such a plan cannot be authorized later by merely adding a binding: prepare a fresh exact plan after binding acceptance.

Catalog grants and order permits do not authorize product mutations. The explicit `printify.product.prepare` relationship is an additive capability in the existing scoped-connection model, not a grant. Live execution requires all of:

- Current Shop 2 native/module/session checks, including Products, POD, Research, Listings and Connections; current signed/enrolled Primary Owner for authorization/execution.
- Existing reviewed ownership context for the precise installation, integration, account, external shop and new capability. Upload endpoints are account-level media operations; their context remains bound to this approved account/Shop 2 workflow.
- Existing `ReviewedLocalEvidence` policy approval at key `printify-product:{plan_sha256}` with the exact server-generated approval payload. Its existing separate author/reviewer rule is preserved. Policy evidence alone is not execution authority.
- Explicit server-sealed owner authorization for that plan, policy hash, actor and fingerprint; expiry is five minutes. Plans, authorizations and receipts are private, content addressed and HMAC-bound to the site's existing authentication salt. Salt rotation invalidates pending records; no new identity/key is created.
- Existing runtime interlocks: STOP ALL, external safety/activation lock, automation and Printify activation remain unchanged. With current controls the authorize/execute buttons are disabled and execution denies before credentials, reservations or supplier calls.
- Autocommit with no outer transaction and existing InnoDB options/audit/nonce tables. Claims and nonce consumption are durable before dispatch. One explicit request per authorization, ten attempts/minute/integration, no automatic retry; ambiguous outcomes remain UNKNOWN and require reconciliation.

The existing Printify HTTP sender and credential vault are reused. The installed stronger order/dispatch transport is retained, with only an additive product entry method. Existing credential material/connection checks remain in force; immediately before sending, the pipeline revalidates ownership, runtime controls and durable session state. TLS is required, redirects disabled, response bytes bounded. Only the exact fixed Printify host and finite request contracts are accepted. No publish, order, production, delete, archive or buyer-approval operation is admitted.

Every dependent receipt must match the current package or an explicitly approved ancestor with identical artwork and provider material, as well as workflow, integration, external shop and connection binding. Sibling/foreign versions cannot borrow receipts. Credential/account/shop rebinding cannot recycle old IDs. Successful create/update projections confirm actual requested variant prices/enabled states and artwork placement, with strict ID and numeric types. Provider error text is not persisted. API response integrity and TLS observation do not certify physical production or image-byte parity.

The portal offers immutable plan inspection, exact review-payload readback, disabled-by-default explicit authorization/execution, and scoped receipt inspection using its existing nonce-protected Shop 2 handler. No new REST route or scheduler is added. The existing Etsy controlled draft pipeline remains the execution boundary; a handoff plan is not a created Etsy listing or acceptance of shipping/profile ownership.

## Owner setup and release boundary

The existing owner-enrollment flow and login/logout/user management are preserved. The trusted custodian must independently verify the intended WordPress account ID for `subvicco12@gmail.com`, restore the Ed25519 public trust key and signed unexpired Shop 2 OWNER record with the required native capabilities, then separately authorize explicit audited enrollment. Email or administrator status supplies no authority. [Detailed enrollment requirements](shop2-product-workflow-1036.md#minimum-owner-setup) remain applicable; API preparation also needs Connections. No production authority is provisioned by this release.

The consolidated builder retains683 installed files unchanged, makes nine documented integration edits, adds43 and deletes none (735 members). No schema migration. Original shared-core pins and reversible consumer overlays document the scope addition; no PR1242, original Drive source or production file is changed. Current production source/schema, tested full-site recovery, package release acceptance and separate installation approval remain external gates. No hosted exact-ZIP PASS or live provider acceptance is implied by offline tests or source CI.

STOP ALL ON. External safety lock ON. Automation OFF. RELEASE HOLD.
