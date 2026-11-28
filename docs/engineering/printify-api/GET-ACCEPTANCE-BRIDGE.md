> .24 update: [final policy, binding and connector qualification](FINAL-ACCEPTANCE-1.0.103.24.md). The earlier tool-capability observation below is historical; current inventory advertises WPVibe execution and WP Agent REST writes, without proving installed owner-session compatibility.

# Governed Printify GET acceptance bridge — .23

Baseline d6566caa84089aa31117f2f960ae85da723beb7c (.22), PR1260. This is offline infrastructure qualification. No production, credentials, bindings, keys, owner permissions, grants, exceptions, provider settings or historical reservation22 are modified. No live API request ran. No schema migration.

## Evidence and architecture

Read the three Work reports supplied November9: acceptance `1q02iJtQ2Ivf2cqb7Dt0pqn-Oc4jsNzms`, sanitized JSON `1Th9UuGTehvYJS6AbN5CUiK1nUAMTpVQJ`, 23-GET matrix `1MPz0WDybGfDNCCPVtUhYUyp6IfYi1r7v`. Dashboard authentication is not API authentication. Those reports establish production .17, integration3 disabled, encrypted-material presence only, absent reviewed binding/provider-connection rows, provider shop28930593 visible, Pop-Up28925875 separate, and zero authenticated API GETs. No source equivalence or credential linkage is inferred.

PrintifyAcceptanceBridge overlays existing ApiReadWorkflow, ProductPipeline, LifecycleWorkflow and CatalogReadRepository. It never constructs a second HTTP client, decrypts credentials, approves upstream execution or changes a lock. ControlledTransport passes its existing server-side sender through a fixed GET confinement wrapper. Vault retrieval remains solely inside existing pipelines. Catalog acceptancePlan is a non-executing metadata projection; grants, ten-request limit, separate safety exceptions and immutable reservations remain unchanged. Its exact additive source hunk is recorded in printify-acceptance-overlay.json; previous provenance overlays and pins remain preserved.

## Authority contract and threat model

prepare(integration, targets) accepts at most ten distinct existing immutable plan/grant references for personalized_pod. Each target is {pipeline:read|lifecycle|product|catalog,plan_hash:<sha256>}, plus catalog index only. It resolves existing reviewed provider ownership; all targets must match exact integration, account, provider shop and DigiForge shop. No caller URL, headers, credentials, raw request body, provider identity override, callback or arbitrary operation is accepted by the connected interface.

The immutable proposal binds existing Global Primary Owner actor, fresh email proof, exact native shop/capability authorization, current profile revision/fingerprint, WordPress browser session HMAC, installation/site, reviewed connection tuple, endpoint descriptors, five-minute expiry and ten one-use slots. An independent qualified reviewer must approve exactly approvalPayload(sessionHash) through the existing ReviewedLocalEvidence policy key printify-acceptance:<sessionHash>. Self-review remains rejected. authorize returns acceptance authority only; it does not approve any underlying plan or bypass execution gates. Existing upstream approval/authorization is also required, supplied as a non-secret local authorization hash.

Every execution and physical attempt rechecks authority. Revocation, expiry, browser change, owner/profile change, current binding change, missing upstream approval, missing/revoked credential, missing module permission or global lock denies dispatch. Post-response authority change prevents schema acceptance and suppresses observations. Signed delegation checks and database-backed Global Primary Owner semantics remain unchanged. WordPress administrator status alone cannot invoke the bridge.

Threats covered: anonymous/ordinary administrator or delegated actor; stolen/transferred session handle; expired/revoked/replayed authority; malicious provider response; SSRF/path/header injection; concurrent/quota/replay attempts; changed source/credential binding; audit/storage failure; uncertain/interrupted requests. HMAC seals plus immutable hashes and audit anchors detect record modification under existing DigiForge conventions. A privileged host/database attacker with WordPress salt custody is outside this local seal trust boundary; this is not externally notarized evidence.

## GET allowlist and bounds

Fixed api.printify.com HTTPS only, TLS verified, zero redirects, no bodies; exact approved path and method are checked again immediately before the existing sender. Grammar admits only existing documented contracts:

- V1 shops; catalog blueprints/detail/providers/detail/variants/size guide; global print providers/detail.
- V2 catalog shipping methods and standard/priority/express/economy regional shipping.
- V1 shop-bound products list/detail/GPSR, personalization options and existing preview-task readback.
- V1 owned upload list/metadata, existing orders list/detail, webhook subscription list, support list/detail.

All provider POST/PUT/PATCH/DELETE operations, shipping POST quotes, archive, support writes and guessed account endpoints are rejected. Existing product/read plans retain their exact blueprint/provider/variant provenance. Uploads retain approved-package source-receipt ownership projection; account-wide raw asset enumeration is not enabled.

Maximum ten one-use slots/session, five-minute session lifetime, three reservations/account per rolling sixty seconds, page1–3, limit1–10. No batches or pagination following; every slot requires explicit invocation. Account-level existing quota/cooldown also applies. Each slot can send at most one physical GET; automatic upstream retries are intercepted before the sender. An interrupted/reserved slot remains consumed. Quota is conservative: even a denied downstream admission can consume a reservation. Zero retry grants arise from errors or receipt reconciliation. Response cap1MiB, JSON depth32, timeout at most20 seconds (catalog10), provider Retry-After and account cooldown retained. 401/403 fail closed; 429/5xx/malformed/partial/oversized responses are unverified evidence, not success. Redirects are neither followed nor exposed.

## Minimized evidence

Receipts contain fixed operation/template/version, local reviewed account SHA/provider shop/integration scope, actual observed HTTP status or null when no response exists, timestamp, correlation, schema-shape fingerprint, fixed field-name/type observations, bounded pagination/rate-limit numbers, retry-delay classification, upstream receipt SHA and compatibility. No field values, raw responses, customer names/addresses/order lines, attachment/preview URLs, credentials, nonce/cookie/session token, raw exception or provider error text leaves the server. Public schema observations describe container/types, not provider certification. Upstream governed repositories retain their existing internal evidence, including catalog response bodies; the bridge never returns those records. Existing retention is not broadened or exported.

Receipt history remains readable by the same currently authorized owner with fresh email proof even after acceptance session expiry/revocation. Historical reads cannot execute or restore authority. Revoked owner loses access. Receipt hashes/HMAC seals and audit anchors are verified on each read. No receipt is marked LIVE_READ_VERIFIED by offline fixtures; final review must establish actual execution environment and observed result.

## Work interface and discoverability

Conditionally registers WordPress core Abilities API category digiforge-acceptance and abilities:

- digiforge/printify-acceptance-execute: {session_hash,slot,authorization_hash}
- digiforge/printify-acceptance-receipt: {receipt_hash}
- digiforge/printify-acceptance-revoke: {session_hash}

All exact input schemas reject additional properties; each callback independently checks current owner/fresh-email/native scope. Core REST authentication and cookie nonce validation remain mandatory for cookie-authenticated requests. Execute/revoke are explicitly NOT readonly: they write local audit/quota state and must use the authenticated core POST ability execution mechanism. Only receipt reads are annotated readonly. No custom unauthenticated endpoint or blanket REST ACL exemption is added. Registration is absent safely when the core API is unavailable.

Official reference: https://developer.wordpress.org/apis/abilities-api/, retrieved November9; local WordPress reference implementation also inspected. Offline tests use real wp_get_ability and permission/schema validation. This establishes OFFLINE_VERIFIED discoverability, not production INTERFACE_AVAILABLE. Current WP Agent tools expose general read-only REST and dedicated content operations, not proven arbitrary custom ability execution. No production diagnostic or discovery call was made. Work cannot assume it can POST an ability or transfer an owner's browser session. Connector registration/secure session-aware owner authentication and installed ability discovery require separate controlled acceptance. No credential export or application-password bypass is proposed.

## Shop2 proposal — never applied

See SHOP2-ACCEPTANCE-BINDING-PROPOSAL.json. Integration3 → personalized_pod → provider shop28930593, channel Etsy. Account dashboard reference and exact credential-account linkage require reviewed provenance; label/encrypted-material presence alone is insufficient. Provider Pop-Up28925875 is explicitly excluded; no Shop3 assignment. Review current main-site/private credential metadata without retrieving values, verify owner account/shop evidence and capability bindings, then separately approve local binding changes. No activation follows from that review.

Initial acceptance sequence, only after all gates are separately approved: a reviewed SHOPS_READ plan, then bounded PRODUCTS_READ page1, then exact selections for FL03 6ae98d61eb5d7e24b80ca0a6 and FL04 6ae9913d9541969415088814 using actually observed blueprint/provider/variant IDs. Unknown IDs must not be filled with defaults. Subsequent catalog/geometry/shipping reads need the existing exact grants and exception contracts. Never create test products/orders/subscriptions to populate inventories.

## Explicit safety-policy blocker and installation gates

Current Settings::safety_locked() requires activation_authorized and automation_armed true and stop_all false. Existing ordinary API/product/lifecycle reads additionally require enabled integration and Printify activation. Production has the opposite preserved posture. This bridge intentionally refuses under STOP ALL/external lock, including catalog exceptions; no lock meaning is reinterpreted.

A separate owner-reviewed acceptance-only policy design is required to permit any GET while STOP ALL/automation OFF remains intact. This candidate does not implement or authorize that policy exception. Do NOT turn on ordinary automation or integration3 as a workaround. Future policy review must specify exact owner, binding, operations, expiry, budget and independent approvals, scoped only to these GETs and incapable of product/order/webhook writes. Until approved and implemented, BLOCKED_AUTHORITY is the correct result even if bridge authority is reviewed.

Release gates: independently review this source/package, current installed compatibility, separate installation approval, reviewed exact binding and credential association, qualified independent runtime-policy review, approved acceptance-only policy implementation if needed, and supported authenticated Work connector invocation. Installation/live calls are not authorized here. Shop3 remains deferred. Provider account/scopes and all live compatibility remain BLOCKED_PROVIDER/UNVERIFIED. Source CI is distinct from local exact-package qualification and hosted exact-ZIP certification.

SOFTWARE_IMPLEMENTED / OFFLINE_VERIFIED refer only to tested software. INTERFACE_AVAILABLE and LIVE_READ_VERIFIED require separate installed observations; neither is claimed. Maintain STOP ALL ON, external safety lock ON, automation OFF, activation unauthorized and RELEASE HOLD.

## Installed-lineage packaging observation

The pinned .22 ZIP catalog repository differs from the branch baseline in its existing fresh-only research wrapper and older internal checks. The builder correctly rejected whole-file source substitution. Packaging therefore inserts only the reviewed non-executing acceptancePlan method at a unique verified anchor in that pinned installed file. Source-head minus that exact method must equal baseline source; all original installed bytes remain preserved around the insertion. No source cache implementation or unrelated shared-file changes are imported. Manifest explicitly marks this composed file as not source-byte-identical. The complete package is a preserved composition, not an exact checkout; extracted-package tests are required.
