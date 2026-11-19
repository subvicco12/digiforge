# Final offline policy, binding and Work interface qualification — .24

Baseline: cf06d750a12da2bd7e6c5fe6d10a2ddc0a2879d4, .23, PR1260. Governing Blueprint v6.0 and Operating Manual retrieved again through authenticated Drive. Manual remains proposed/draft. Work report 1q02iJtQ2Ivf2cqb7Dt0pqn-Oc4jsNzms, sanitized JSON 1Th9UuGTehvYJS6AbN5CUiK1nUAMTpVQJ and 23-GET matrix 1MPz0WDybGfDNCCPVtUhYUyp6IfYi1r7v reconciled. No production inspection or provider call occurred. Reports remain dashboard-only evidence, not credential authentication.

## Safety decision and exact gates

Blueprint section3 says a narrower scope can never override a disabled parent; sections23/24 state STOP ALL overrides every shop/workflow and outranks everything. Existing catalog-only owner exceptions predate this bridge and require their own exact grant, explicit separate exception, protected posture, enabled CONFIGURED integration, reviewed ownership and audited reservation. CatalogRepository::credential uses exact connection authorize, not ordinary runtime admission. Thus that historical exception is a real specialized path, not authorization for general API reads, disabled integration3, or any write.

Current source gates:

|Boundary|Condition|Acceptance consequence|
|---|---|---|
|AcceptanceBridge execute and sender guard|Settings::safety_locked or stop_all|Reject before quota/send; recheck before physical HTTP|
|Settings::safety_locked|activation_authorized not true, automation_armed not true, or stop_all true|Global parent ceiling remains locked|
|ApiReadWorkflow::current / ProductPipeline::locked / LifecycleWorkflow::current|Global safety plus Printify activation/effective switch|Ordinary reads and writes remain denied|
|ConnectionBoundary::runtime / ScopedCredentialRetriever|Effective Printify capability, enabled CONFIGURED integration and exact reviewed binding|Disabled/unbound integration3 cannot use normal vault execution|
|CatalogReadRepository grant/exception/read|Exact grant and separate catalog-only exception with protected posture|No extension to general reads or mutations|
|Local evidence storage|Options/audit InnoDB, nonce/reservation audit and current authority|Receipt persistence is local evidence, not provider activation|

Signed delegate verification is preserved. Global Primary Owner continues to use the existing host-approved database/native/email model; no Ed25519 owner key dependency is reintroduced. Where a reviewer relies on delegated signed authority, existing independent signature/expiry/revocation checks still apply. No normal capability accepts a proposed acceptance capability as a permit.

### Decision matrix under preserved posture

|Case|New proposal/validator|Existing execution|
|---|---|---|
|GET, no authority/review/binding|BLOCKED_AUTHORITY / BLOCKED_PROVIDER|Denied|
|GET, expired/revoked/wrong owner/account/shop|Validation blockers; never a grant|Denied|
|GET, valid offline candidate, STOP ALL ON / external lock ON|OWNER_APPROVAL_REQUIRED, proposal disabled|Denied|
|GET, draft described as approved but amendment not adopted|No executable authority path|Denied under existing gates|
|Create/update/publish/delete product; submit/produce/cancel order; webhook/support mutations|PROVIDER_MUTATION_DENIED by GET bridge|Normal safety and independent write approvals unchanged; denied in this posture|
|Local receipt retrieval by current original owner|Existing hash/HMAC/audit verification|No provider request; does not restore expired execution authority|
|Readiness observation by current owner with fresh proof|Local metadata only|No provider request or explicit diagnostic audit write; existing permission denials may log security audits|

## Explicit inactive amendment proposal

PrintifyAcceptancePolicy::proposal is a machine-readable inactive draft: enabled=false, runtime_execution_authorized=false, exact actor1, Shop2/integration3/provider28930593, GET only, current allowlist source SHA, five minutes, ten slots, three requests/rolling minute, page3. Account/credential/expiry stay unknown until reviewed. Its decision always returns BLOCKED_AUTHORITY, even when caller describes a valid proposal or parent flags as unlocked. authorityBlockers validates candidate expiry, scope, owner, account association, independent review, signed delegation where applicable and revocation; it does not verify caller assertions cryptographically or issue a permit. A positive pure validation is never live authority.

Proposed amendment for owner review: explicitly define an acceptance-only GET exception to the blueprint safety ceiling and to disabled-integration credential admission, for exactly one independently reviewed owner/account/credential/shop/session/allowlist/digest, automatically expiring and immediately revocable, with local audit writes only. Ordinary provider operations, STOP ALL state, external lock, automation OFF and release hold must remain unchanged. The amendment must say exactly which read interlocks may admit that distinct capability and preserve every write denial. The proposed capability is reserved and is not added to ScopedProviderConnection runtime capabilities. No runtime exception implementation is adopted in this release. Adopting wording alone must not enable execution; a later separately reviewed implementation/test is required. Do not turn on normal automation or enable integration3 as a workaround.

## Shop2 record and evidence matrix

SHOP2-ACCEPTANCE-BINDING-PROPOSAL.json is the exact canonical PrintifyAcceptanceBinding::proposal format. Only known values are filled. Internal shop2 is not business/store/program ID2. Registry identities must be observed separately.

|Field|Evidence|Disposition|
|---|---|---|
|DigiForge internal shop2 / personalized_pod|Owner-approved canonical identity|Verified intended identity|
|Integration3 / CONFIGURED / production / disabled|Supplied Work production metadata|Observed; unchanged|
|Printify28930593 / Etsy|Authenticated dashboard visibility|Dashboard-only, not API identity|
|Printify28925875 / Pop-Up|Separate dashboard shop|Explicitly excluded|
|Account reference / legal owner / token association|Not established by encrypted presence or label|UNKNOWN / BLOCKED_PROVIDER|
|Credential reference and metadata SHA|Encrypted personal_access_token presence reported; current exact reference identity not inspected here|Unknown in proposal; no invented fingerprint|
|Business/store/program scope and registry ancestry|Required by BusinessScope and ScopedProviderConnection|Unknown; never default from shop number|
|Independent approval hash/revision/times|No approved provider evidence rows|Unknown / unreviewed|
|Credential scopes/expiry/live schema|No authenticated API GET exists|Unknown|
|Shop3 mapping|Deferred|No assignment or activation|

Binding::validate requires exact schema/keys/identities, the excluded Pop-Up ID, matching account/credential metadata and association evidence, registry scope, no duplicate/cross-shop conflict, matching observed enablement, provider scope, current independent approval revision/hash, no revocation, observation<=300 seconds old and expiry<=one hour. Existing runtime requires enabled integration; validator reports disabled as a blocker, never enables it. Caller-supplied booleans/hash evidence are only offline claims; result explicitly says evidence_is_caller_supplied and runtime/binding/enablement authorization=false. The proposed metadata_sha256 refers to the existing reviewed ownership payload connection_material_sha256 (canonical private vault metadata digest), not a newly generated token fingerprint; it remains null until actual metadata evidence exists. Actual admission still requires existing independently reviewed provider rows, signatures/delegation and current vault binding. No provider table/schema changes are introduced.

## Current connector assessment — correction to .23 observation

Current Codex tool inventory advertises WPVibe discover_abilities, get_ability_info and run_ability, plus WP Agent rest_api_read and rest_api_write. This is CONNECTOR_DISCOVERED at advertised-tool level. It is not proof those tools are enabled in a separate ChatGPT Work session, bound to DigiForge, authenticated as the required actor, or able to execute this candidate on production .17.

WPVibe run_ability fetches annotations and derives GET for readonly, POST otherwise (DELETE only destructive+idempotent). Execute/revoke remain non-readonly; receipt/readiness are readonly. WP Agent REST write supports POST with preview/confirm controls, but no such operation was invoked. No production ability discovery, readiness or execution request was made because this is an offline mission. Future invocations must first discover/get ability metadata and stop if it cannot be verified; do not force fallback annotation hints to obtain an unsupported method.

Core WordPress Abilities API>=6.9 is required; absence is a safe no-op. The source registers category digiforge-acceptance and execute/receipt/revoke/readiness abilities. Input schemas are closed, callbacks independently authorize, and core permission callbacks return boolean denial for ordinary authorization failure (structured trusted-server errors remain available from invoke/permission). This avoids treating normal denial as WP_Ability incorrect developer usage. Native cookie-authenticated REST requests still require WordPress nonce validation. Ability metadata or REST POST support does not bypass that authentication.

Readiness accepts an empty object only, reads bounded local metadata for integration3, shows exact expected mapping booleans, reviewed capability statuses and source/runtime ability registration. It does not consume vault material, contact Printify, create grants, write mapping rows or emit its own diagnostic event. Existing owner/shop authorization denials can write their normal security audits. Snapshot never echoes account identifiers, credential fingerprints, configuration blobs, PII or raw exceptions. Permission stages distinguish AUTHENTICATION, OWNER_AUTHORITY, FRESH_EMAIL and SHOP_MODULE for trusted invocation. Core external denials use its standard forbidden response.

Connector auth remains BLOCKED_TOOL: an application-password actor is not automatically an email-verified browser session. Sessions are non-transferable and prepare requires a nonempty WordPress session token. No application-password exception, copied cookie, stored OTP, bearer authority or WordPress administrator bypass is added. Work must independently verify its tool inventory/site link and secure owner/session-compatible authentication. It may obtain receipts only through the existing authorized callback; no public proxy or anonymous export is introduced.

## Engineering corrections and threat model

Contract::transport now rejects zero/negative/noninteger timeout/response limits, unknown options, null bodies, injected or unexpected headers, missing User-Agent/Accept/Authorization structure, redirects, disabled TLS and nonexact provider URL. Existing fixed host/path/method/plan checks and one-physical-request guard remain. Receipts now carry endpoint SHA and upstream plan SHA so a reviewer can correlate exact reviewed reads without exposing IDs/addresses/raw bodies. Fixed field/type projection and schema fingerprint remain.

Threat coverage: SSRF/arbitrary paths, mutation substitution, header injection, credential/PII disclosure, stolen session handles, stale/revoked authority, wrong account/shop, quota concurrency, slot replay, altered evidence and partial/oversized/provider-error results. Existing tests and new fault matrices exercise those boundaries. Pure proposal inputs cannot grant execution; readiness metadata cannot supply credentials. A host/database attacker with WordPress salt custody remains outside the local HMAC trust boundary; no externally notarized certification is claimed.

## Separate future checklists — non-executing

Controlled installation:
1. Independently review exact source, ZIP digest, preservation manifest and qualification receipts; hosted source CI is not hosted exact-ZIP certification.
2. Confirm current installed-source/schema compatibility using separately authorized evidence; this mission introduces no migration and performs no recovery qualification.
3. Obtain separate installation approval; preserve release hold/locks/automation settings. Do not enable integration3, enroll owners or change provider mappings as part of installation.
4. Through separately authorized metadata discovery, confirm core API version and installed ability inventory. Do not execute provider actions.

Future GET acceptance authorization:
1. Owner decides explicit blueprint/disabled-integration safety amendment; then separately review its implementation. This release adopts none.
2. Establish non-secret account/credential/shop association and exact registry identities. Review canonical proposal and duplicate mappings; obtain separate configuration approval. No credential reveal/rotation or Shop3 assignment.
3. Use existing audited ownership proposal/independent-review contracts only after those prerequisites; review required per-operation scopes and expiry. No automatic approval backfill.
4. Verify connector/site/actor and owner browser/session compatibility without extracting authentication material. Obtain separate permission for any production diagnostic/security-audit writes.
5. Prepare exact upstream GET plan and independent acceptance session review, bounded expiry/slots/rate/allowlist, plus every required upstream grant/exception. Verify immediate revocation and no write capability.
6. Obtain explicit live GET-only authorization, then start with shops and bounded existing-product inventory before exact FL03/FL04 selections. Preserve minimized receipts, status/schema evidence and unknown fields. No products, uploads, orders, cancellations, support requests or webhook mutations.

Current results: implementation and offline checks are distinct from INTERFACE_SOURCE_VERIFIED, advertised CONNECTOR_DISCOVERED, installed/runtime availability, LIVE_READ_VERIFIED, BLOCKED_AUTHORITY, BLOCKED_TOOL and BLOCKED_PROVIDER. Installation, policy adoption, binding, credentials and provider execution remain OWNER_APPROVAL_REQUIRED. STOP ALL ON, external safety lock ON, automation OFF, activation unauthorized and RELEASE HOLD are preserved.
