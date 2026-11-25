DIGIFORGE — STANDALONE PRINTIFY API PLUGIN
MASTER ARCHITECTURE & EXECUTION BLUEPRINT v1.2 — PROPOSED
25 November 2026 | Exact revision for owner approval | Not in force
Repository subvicco12/digiforge | Issue #1273 | Standalone development Draft PR #1277


1. IMMUTABLE OWNER DIRECTIVE
The Printify API plugin is a completely independent WordPress product. Develop, package, install, configure, test and operate it without requiring the DigiForge AI plugin. No DigiForge AI handshake, integration, dependency, shared database or credential access is permitted during standalone development. Only AFTER the Printify API plugin has been independently installed and qualified may a separately owner-authorized, versioned and authenticated communication interface with DigiForge AI be developed and activated. DigiForge AI must never bypass the API plugin to reach Printify credentials or protected operations.


2. PRODUCT SCOPE AND SOURCE PRECEDENCE
Scope: independently installable Printify API plugin with protected WordPress admin, shop/catalog/blueprint/provider/variant/geometry, shipping, product, image, order, fulfillment and tracking capabilities, diagnostics, and optional future consumer contract. Exclude DigiForge Shop 2, Etsy listing factory, AI artwork pipeline and future consumer adapter from present standalone acceptance.
Precedence: explicit owner directives; DigiForge Ultimate Master Blueprint v6.0; accepted pinned Core/Companion/Standalone contracts; Printify API Final Architecture dated 19 Nov 2026 (Sections 1–24); this blueprint for standalone separation and execution sequence. Conflicts require owner-reviewed change control. No security downgrades.


3. LOGICAL ARCHITECTURE
A. Standalone WordPress shell: isolated bootstrap, capability checks, settings schema, operator UI, health, diagnostics, versioned migrations, reproducible package.
B. Read domain service: versioned typed shop/catalog/variant/geometry/shipping/product/order/tracking projections with exact shop/account/integration scope, evidence provenance and freshness.
C. Initial manual read transport: Standalone calls approved Printify HTTPS GET routes directly through the WordPress HTTP API after the gates in §17. Development and product tests use synthetic intercepted HTTP until separately authorized. Cloudflare Access, Workers, a dedicated hostname, VPS, replacement Core and Companion are not prerequisites for this read profile. Preserve the existing gateway and inert T4 work as a separate capability and evidence tier. The mutation architecture in D–G remains governed by §§8–9.
D. Native Core authority: canonical AUTHORIZE, REGISTER, CLAIM, RECOVER, CANCEL, RECEIPT_READ, RECONCILE and OUTCOME. Core owns journal, approval, unique CLAIM, revocation, operation state and consumed floor. Do not create a second authority engine or use RESERVE as a synonym for CLAIM.
E. Companion: qualified GET/PANEL/native-observation compatibility, explicitly pinned.
F. Independently administered witness and recovery anchor: STOP/revocation/admission sequencing and rollback-resistant high-water floor.
G. Trusted final sender: exclusive usable Printify credentials, authenticated handoff, durable attempt/acknowledgment receipts, no WordPress bypass.
H. Optional DigiForge AI consumer: deferred until standalone installation, qualification and separate authorization.


4. INSTALLATION INDEPENDENCE
Standalone ZIP must install and activate in disposable WordPress/MariaDB with DigiForge AI absent. No DigiForge AI classes, hooks, tables, endpoints or activation prerequisites. Core/Companion technical dependencies may be declared for protected capabilities; missing or incompatible dependencies fail closed (DEGRADED_READ_ONLY or DENIED). No automatic account connection, credential creation, provider call, scheduling or mutation on install. Future communication requires a separate handshake, version negotiation, scoped service identities, least privilege and owner enablement. The initial manual read profile requires only the Standalone ZIP. Existing DigiForge and shipping plugins may coexist after independent qualification. No shared app state, credential store, dependency handshake or DigiForge AI integration.


5. PRINTIFY CAPABILITY REGISTRY
Read-only MVP P0: SHOP_GET, CATALOG_BLUEPRINT_GET, PRINT_PROVIDER_GET, VARIANT_GET, PRINT_AREA_GET, SHIPPING_CATALOG_GET, PRODUCT_LIST/DETAIL_GET, ORDER_LIST/DETAIL_GET, FULFILLMENT/TRACKING_GET, PANEL/DIAGNOSTICS.
Protected full API P0/P1: IMAGE_UPLOAD, PRODUCT_CREATE/UPDATE/PUBLISH, ORDER_QUOTE/CREATE/SEND_TO_PRODUCTION/CANCEL, RECEIPT_READ, RECONCILE and OUTCOME.
Future DigiForge AI consumer adapter: deferred, not MVP.
For each endpoint register capability ID, documented method/path, source/version, shop/account scope, credential scope, request/response schema, pagination, timeout, maximum size, rate-limit bucket, freshness, side-effect class, idempotency evidence, reconciliation GET, denial and test references. Provider availability and field behavior must be verified against developers.printify.com. Never invent print geometry, bleed, safe zones, price, shipping coverage, cancellation eligibility or webhook authenticity.


6. MANDATORY READ-ONLY MVP JOURNEY
Shop → Blueprint → Print Provider → Variant → documented Print Area → GET Catalog Shipping Rate, in protected standalone WordPress admin and through one internal typed v1 service. Reuse existing Runtime, PrintifyCatalogReadContract, PrintifyApiReadEvidence, selectors, validators and projections. Show exact scope, provider attribution, timestamp, freshness, missing/UNKNOWN values, pagination, typed errors, blocked transport, empty results and evidence tier. Distinguish synthetic from cached, hosted-verified and live-verified facts. GET catalog shipping rates are NOT address-specific POST quotations. Product/order list/detail and shipment summaries follow the mandatory vertical slice. Existing tracking hash and URL-present flag alone do not constitute a usable operator tracking reference. The operator can select only previously approved account/shop profiles from a scoped discovery result. Show connection status without probing on page load. Provide operator-initiated connection diagnostics and manual refresh, with source, checked-at time, stale or unavailable state, and redacted actionable errors. Use documented tracking data subject to scope and data minimization.


7. TRANSPORT AND SECURITY
For the initial manual read profile, admit only fixed HTTPS Printify routes, specifically GET on the per-operation allowlist hosted at api.printify.com. Request construction uses typed arguments. Arbitrary destinations, redirects, unsafe URLs, non-GET methods, unsupported operation selectors and automatic retries deny. Verify TLS. Bound individual calls to five seconds and provider bodies to 256 KiB. For paginated reads, cap each page at ten records and cap navigation at forty pages. Reconstruct pagination locally; never follow provider URLs. Enforce a durable per-account/shop quota before sending. The initial cap is ten requests in any rolling minute per account/shop, with no exemption for diagnostics. Surface 429 and bounded retry guidance without retrying.

Require an explicit administrator capability, operation-specific nonce, a current confirmed session and the current reviewed account/shop/profile revision. Independently restrict access to the approved shop, even when provider discovery returns other shops. Deny absent, malformed, stale, revoked or unverified configuration and authorization. STOP ALL and the external safety lock are absolute ceilings. Unknown or unavailable hold state denies. Check current locks before network admission and before persisting or showing a response. No background synchronization, activation probes, WP-Cron, unattended work, provider mutation or egress fallback.

Validate documented response shapes and exact scope, then persist only bounded, redacted projections with provenance, timestamp and digest. Recheck scope, revisions and relevant locks after I/O. Do not hold a database transaction over network I/O. Preserve prior snapshots as explicitly stale on transient failure. Do not store credential-bearing request structures, raw provider responses or unnecessary customer data. Preserve UNKNOWN and existing mutation fencing. Fixture and live provenance must remain distinct. Authenticated signed evidence, where used in the preserved gateway or later mutation path, retains existing audience, freshness and revocation checks.


8. PROTECTED MUTATION LIFECYCLE — TARGET ONLY
PROPOSED → APPROVED → REGISTERED → CLAIMED → PREPARED → ADMITTED → SENT/UNKNOWN → CONFIRMED_SUCCESS / CONFIRMED_FAILURE / UNKNOWN, with monotonic conditional revisions. Signature validation is not admission. Fresh online witness must order STOP and revocation against final-sender admission. Sender exclusively holds provider tokens. Record original operation, admission and attempt IDs before effect; reconcile lost acknowledgment by original IDs. UNKNOWN remains quarantined, manual-review only and never automatically retried. Personalized POD order production requires explicit manual approval. Publishing and cancellation are channel/provider-specific and need authoritative readback.


9. RECOVERY AND CREDENTIAL CUSTODY
Initial manual read-only profile only: the approved site custodian may supply a named, least-privilege Printify Personal Access Token through an approved server-side secret source readable by the WordPress runtime. No browser entry or disclosure, WordPress database or option storage, inclusion in a ZIP, logging, archive or evidence export. Provider tokens may appear only in the HTTPS Authorization header sent to the fixed Printify origin. Store only an opaque reference and redacted configuration status in plugin state. If a credential limited to the required documented read permissions cannot be independently demonstrated, keep live reads held. Approve account, allowed shops, operation scopes, expiry or scheduled rotation, revocation and incident handling. Test refusal for wrong scope, missing or rotated credential and unsafe secret handling using synthetic values. Backups and configuration handling must not distribute the token.

This narrow exception changes the credential trust boundary for GET. A compromised WordPress runtime or host operator with equivalent access may read or reuse the token outside the plugin. Plugin controls cannot establish independent custody or stop use of a copied credential. Minimize token lifetime and scope; promptly revoke after compromise or decommissioning and verify invalidation independently. A site administrator is not the independent mutation witness, anchor or exclusive final sender.

For protected mutations and recovery of mutation authority, retain the following requirements unchanged:

Separate witness and anchor administrators and a dedicated sender custodian. Recovery floor must reside outside WordPress/witness shared rollback domain. Three distinct current role signatures are required for approved recovery under accepted reference tests. A witness-ahead repair must prove exact committed successor; anchor-ahead restore remains fenced. Prove all-root rollback cannot revive consumed authorization. Provider-side historical credential invalidation must be evidenced before real mutation release.


10. DATA OWNERSHIP AND MIGRATIONS
Core owns canonical journal/claim/approval/revocation. Standalone owns immutable provider-attributed snapshots, validated read models, UI metadata, evidence references and diagnostics. Every record binds environment/shop/account/integration, immutable IDs, version, timestamps, revision and evidence digest. Composite tenant keys, unique constraints, CAS, retention and redacted audit are required. No second Core journal. Migrations are forward-versioned, dry-run qualified and separately authorized for production. Uninstall must not erase unresolved UNKNOWN or audit evidence.


11. VERSIONED INTERFACE CONTRACT
Standalone internal read v1 returns typed SUCCESS, DENIED, FAILED_READ, STALE/UNKNOWN, pagination, freshness, provider provenance, scope and schema version. No raw provider tokens or unbounded provider payloads. Future DigiForge AI client can only use a separately approved authenticated public contract after standalone installation and qualification; do not implement or activate communication now.


12. OPERATOR UI AND OBSERVABILITY
Show shop/catalog/variants/geometry/shipping; product/order read views; freshness and evidence tier; disabled capability matrix; source/Core/Companion pins; health; outstanding UNKNOWN and reconciliation; audit/export; custody/witness/anchor status without secrets. Never display production-ready green based only on fixture success. In the initial manual read profile, present connection state, last successful observation, freshness, provenance, scoped navigation and limits. Identify gateway, Core, Companion and mutation authority as out of scope for the initial profile, while preserving their existing diagnostics where applicable. Never expose token material, credential locations or transport headers.


13. PACKAGING AND COMPATIBILITY
Reproducible standalone ZIP, source manifest, package SHA-256, PHP/WordPress/MariaDB/dependency compatibility, clean installation, activation and versioned upgrade. Exact source and artifact verification. Missing/incompatible dependency denies affected capabilities. No DigiForge AI prerequisite. Upgrade, rollback, uninstall and restore require separate acceptance.


14. TEST TIERS
T1 static/unit/schema/negative; T2 extracted-package disposable WordPress+MariaDB operator journey; T3 offline witness/sender crash/restart/race/STOP/UNKNOWN; T4 owner-approved hosted inert STOP/drain; T5 separately authorized provider read and controlled mutation acceptance; T6 independent install/upgrade/restore/rotation/rollback. Every report pins exact source/dependency SHAs, package digest, environment, test counts, CI run IDs, skipped gates and actual evidence tier. Never sum overlapping tests or treat skipped jobs as PASS.

For the direct manual read profile, require exact-source offline T1/T2, an independently reviewed no-egress coexistence exercise and T6 package/install/rollback/credential rotation evidence before a separately authorized narrow T5 read pilot. No Hosted T4 Cloudflare exercise or new hosted infrastructure is a prerequisite for this read profile. Work's v1.1 T4 clarification is preserved for the separately approved optional inert gateway lane. T3/T4 witness/sender and hosted inert acceptance requirements continue to apply to protected mutation release. A local disposable test and an independently witnessed exact production-tuple result are different evidence tiers; record all version and LiteSpeed exceptions.


15. DIRECT READ ENGINEERING TIMEBOX
After explicit approval of the exact v1.2 bytes, target 24–48 elapsed hours for internally achievable direct read-only engineering, extracted-package synthetic regression and packaging. Keep the current gateway candidate as a fixed checkpoint and continue direct-read work as fast-forward successor commits on the existing Draft PR #1277. Preserve frozen PR #1260 and #1276. Report exact-head test evidence, package hash, functional ledger, secure configuration, installation, rollback and independent acceptance gates. At deadline report proven functionality and remaining blockers. This engineering target does not include independently controlled runtime or production authorization lead times.


16. CURRENT BASELINE AND ESTIMATES
Independent 24 Nov product audit: read-only MVP 67% implemented/52% test-supported; full scoped API 62% implemented/47% test-supported. Judgment-based weighted estimates, not operational readiness. Last reviewed Standalone c676f415b341e850202e73543c3b52b2a7f653dc; Companion fd5dd2b77bcb795efe92f4568bc5c4837a8640ad; Core 2c9fcca6e14fbf64f94414661d42d85715039070. Reverify live heads. Baseline realistic A read-only MVP 7–10 engineering days (accelerated 48-hour narrowed target); B protected full API additional 14–20 days; C operational qualification/future consumer pilot additional 7–12 days, plus external waits. Re-estimate with each exact-head feature ledger.

The initial direct-read successor starts at accepted gateway checkpoint 5a7b6e047f241c1c8d6877322b0e0cd272aef8ec, tree bcd2d1765e8a59f16e464f812583b83bcffc5e4e on Draft PR #1277. Retain prior gateway qualification as evidence for that profile only. No direct-read completion percentage is inferred from those results. T4 preparation is suspended.


17. RELEASE GATES
No live read without approved shop/token scope, custodian and transport. For the direct manual read profile, the owner may approve the narrow server-side read credential custody exception in §9 without qualifying an external sender, independent witness/anchor or hosted T4 gateway for those GETs. Independent package/coexistence qualification, custody risk acceptance and a separately scoped live read pilot remain mandatory. The exact pilot needs a named site custodian and operator, provider policy and scope verification, explicit account/shop/operation allowlist, expiry, budget and STOP/rollback criteria. Separate approval is required before any change to safety flags, provider access or production installation. Do not create a code bypass or treat a read-pilot approval as a mutation authorization. No mutation without independent witness/anchor, exclusive sender, provider-side old-token invalidation, witnessed restore, current STOP/revocation and hosted inert qualification. No production install, migration, provider call, hosted run, merge, deployment, paid provisioning or automation without separate owner approval. No DigiForge AI communication before standalone installation and independent qualification plus separate approval.


18. MANDATORY CODEX AND WORK GOVERNANCE
Before EVERY assignment, Codex Cloud and ChatGPT Work must open this canonical blueprint, cite exact version/Drive URL, read latest accepted Drive report and reconcile current source. If blueprint cannot be accessed, report BLOCKED, not guessed architecture. Every report must include blueprint version/link, exact PR/source SHAs, delivered capability statuses and evidence tiers, standalone independence test, tests/CI/skips, package and Drive SHA-256, safety controls, remaining P0/P1 gaps, owner decisions and next bounded action. Every report AND supporting evidence must be saved in the canonical DigiForge Drive folder and independently read back with hash verification. Work and Codex must reuse each other's latest accepted reports. Never silently overwrite, contradict or weaken this blueprint. Any change needs owner approval, new version and change log. Upload failure must be reported UNSAVED/UNVERIFIED.


19. SOURCE REGISTER
Printify API FINAL Architecture (19 Nov, Sections 1–24): https://drive.google.com/file/d/1DPjC-iFPZfRBRMrrWFH4A-QJraaLlJQd/view
Product audit: https://drive.google.com/file/d/1ZBNlPflrUza2DpTQy5acoZB9EHS4Z4kl/view
Endpoint matrix: https://drive.google.com/file/d/1jXM0TisNGcT7rLp1HN2E8bqT5qKkznlw/view
Weighted ledger: https://drive.google.com/file/d/1uaI-cycogq0fegdrRQ4m7vAmZdcdCLAm/view
Delivery schedule: https://drive.google.com/file/d/1a-fVbBpRTyxTfe8-UbnXkTtVLyGcAugY/view
Official provider documentation: https://developers.printify.com/
Canonical Drive folder: https://drive.google.com/drive/folders/1bbbVgrB8G4ekbk5itrH2asTu_yLnbbPg
Recorded v1.1 T4-only amendment: https://drive.google.com/file/d/18AsxqI0aMJQ1GKH5lOV4us_QkQU-GabD/view
Independent coexistence input receipts: https://drive.google.com/file/d/1KrdZwHUP4BJdOtjjqqo8dTJw43X7-t5g/view


20. FINAL DECISION
Proposed v1.2 governance stays inactive until the owner approves these exact revised bytes. If approved, resume only offline direct GET implementation under this revision. Prepared gateway artifacts and inert T4 evidence remain available for a separately authorized future lane. This is not a release certificate. STOP ALL ON · External safety lock ON · Automation OFF · RELEASE HOLD.

Change log

v1.0 — Canonical baseline, 24 November 2026.
v1.1 — Recorded inert T4 clarification; preserved for separately authorized gateway testing.
v1.2 proposed — §§3, 4, 6, 7, 9, 12, 14–17, 19 and 20 define the direct manual GET profile, narrow custody exception and associated acceptance gates. Protected mutation provisions in §§8–9 are preserved.
