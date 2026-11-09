# Final inactive safety-amendment and binding review — 1.0.103.25

Starting source: `0c4335ffa7743398989da2e0360f40fe38ceedb0`. Existing draft PR1260, branch `feat/printify-api-completion-20261108`. No schema migration or new transport. Production is not inspected or changed in this review. Historical reservation22 remains untouched.

Read the [exact governing clauses](GOVERNING-CLAUSE-RECONCILIATION.md), [standalone DRAFT / NOT ADOPTED amendment](DIGIFORGE_PRINTIFY_GET_ONLY_SAFETY_AMENDMENT_PROPOSAL.md), [binding decision/evidence matrix](SHOP2-BINDING-DECISION-PACKAGE.md), [machine-readable inactive draft](SHOP2-GET-ONLY-BINDING-PROPOSAL.json), and [47-operation ledger](operations-1.0.103.25.json). No supported API operation or provider mutation is added.

## Corrections and independent source review

The normal binding validator's enabled check is deliberate and unchanged. The new draft wrapper retains its complete result and never returns runtime authorization. Only two explicit Boolean false observations classify disabled integration as expected for an inactive proposal; missing/null/string/integer evidence remains unknown and blocked. Readiness now preserves a missing integration as null rather than false. This distinction was found by a separate read-only review agent, corrected and covered by behavioral tests. This is automated source review, not independent human acceptance or policy adoption.

## Safety decision matrix

|Condition|Current decision|Review status|
|---|---|---|
|STOP ALL ON|Deny all bridge GETs and ordinary provider operations|OFFLINE_VERIFIED|
|External lock ON|Deny bridge and normal transport|OFFLINE_VERIFIED|
|Integration3 disabled|Normal binding and credential runtime denied; draft may classify only identity evidence|OFFLINE_VERIFIED|
|Complete synthetic draft identity|Still NOT_ADOPTED, POLICY_AMENDMENT_REQUIRED, execution false|OFFLINE_VERIFIED|
|Missing/expired/revoked/wrong owner, shop, account, credential or review|Deny; never inferred from config or dashboard|OFFLINE_VERIFIED|
|Non-GET, unsupported endpoint, arbitrary host or caller credentials|Deny existing bridge contract|OFFLINE_VERIFIED|
|Exhausted/contended slots or quotas|Deny existing atomic bounded execution contract|OFFLINE_VERIFIED|
|Proposed exception under absolute parent ceiling|No executable exception implemented|POLICY_AMENDMENT_REQUIRED|
|Production installation, binding, enablement or execution|No authorization granted|INSTALLATION_NOT_AUTHORIZED / LIVE_GET_NOT_AUTHORIZED|

Five-minute lifetime, ten one-use slots, three requests per rolling minute, three-page maximum, 1MiB response limit,20s timeout and no automatic retry are unchanged. Emergency authority invalidation even while STOP ALL is already ON is a future implementation requirement, not a present operational claim.

## WordPress and ChatGPT Work interface layers

|Layer|Evidence and conclusion|
|---|---|
|PHP registration|Existing `PrintifyAcceptanceAbilities`: execute,receipt,revoke,readiness with fixed input contracts and owner/native/fresh-session checks. INTERFACE_SOURCE_VERIFIED.|
|WordPress runtime|Isolated real WordPress integration tests register/discover/invoke readiness without provider requests. OFFLINE_VERIFIED, not production proof.|
|Connector metadata|This session advertises WPVibe discover_abilities/get_ability_info/run_ability and WordPress Agent GET-only REST access. This proves a supported discovery/dispatch surface exists; it does not identify the connected production site or its abilities.|
|Authenticated owner invocation|UNVERIFIED. Connector authentication must satisfy existing native owner, exact scope and fresh-email/session requirements. Application-password or connector identity is not automatically the browser email session. Do not export cookies/tokens to bridge this gap.|
|Installed production availability|Last owner-reported version .17 predates bridge. Not reinspected. INSTALLATION_NOT_AUTHORIZED.|
|Actual authenticated Printify GET|None attempted. LIVE_GET_NOT_AUTHORIZED.|

CONNECTOR_UNVERIFIED remains until a separately authorized installation and authenticated connector discovery on the exact site establish exposure, annotation-derived HTTP method and owner/session compatibility. Never substitute a public anonymous proxy or forced fallback safety annotation. Source registration and mocked invocation cannot certify connector acceptance.

## Threat boundaries preserved

Credential custody stays in the existing vault; no live secret is read. Existing fixed endpoint/TLS/host, redirect/header/body denial, PII projection, one-use claims, atomic quotas, audit anchoring, revocation and unknown-outcome rules are retained. The draft class cannot obtain credentials, perform HTTP, persist bindings, activate integration3 or grant authority. Arbitrary caller evidence is explicitly labelled caller-supplied, never provider-verified. Only the ordinary normal validator result describes current runtime admission.

## Separate next decisions and gates

1. OWNER_APPROVAL_REQUIRED: approve or reject amendment wording only. This does not adopt an executable exception or approve installation/configuration/live calls.
2. POLICY_AMENDMENT_REQUIRED: if wording approved, separately review any future parent/vault/emergency-revocation implementation. Until then all locks deny.
3. PROVIDER_IDENTITY_UNVERIFIED: independently establish account identity, encrypted credential association, actual read scopes and approval evidence. Dashboard access is insufficient. If an API probe is required to establish linkage, it needs a separately reviewed bootstrap decision; this draft grants none.
4. INSTALLATION_NOT_AUTHORIZED: reconcile installed files/database/recovery and separately approve installation. No recovery work is performed here.
5. OWNER_APPROVAL_REQUIRED: independently authorize the exact disabled Shop2 binding; never assign Shop3 or substitute Pop-Up28925875.
6. CONNECTOR_UNVERIFIED: establish exact-site runtime discovery and authenticated owner-session compatibility through supported tools, without secret extraction.
7. LIVE_GET_NOT_AUTHORIZED: separately authorize a short-lived bounded endpoint session only after all preceding gates. Then inspect minimized receipts; no provider writes, automation, or release activation follows.

Hosted source Engineering & Safety Audit and local exact-extracted-package qualification must be recorded separately in release receipts. A hosted exact-ZIP audit is NOT RUN unless an actual digest-bound hosted run is supplied. Installation readiness is not asserted by this review.
