# Minimal Stage 2 GET-only amendment — DRAFT / NOT ADOPTED / NON-EXECUTING

This proposal changes NO running policy. The current PR1262 wording requires denial whenever STOP ALL, external lock or native provider disablement applies. All those denials remain implemented. Ordinary external execution, automation and release remain held. Owner approval of this text would be a policy-design decision, not installation, binding, credential use or live request approval.

## Proposed narrow addition to PR1262 compatibility/security clauses

"Ordinary connector/business operations continue to fail closed under STOP ALL, external safety lock, integration disablement, automation OFF and RELEASE HOLD. A separately reviewed Stage2 diagnostic GET permit MAY be specified as an explicit parent-policy branch solely for one manually initiated, bounded acceptance session. This branch is prohibited until the owner separately adopts its exact policy, its independent review passes, its emergency termination is qualified and its exact software installation and one-session execution are separately approved. Active lock Boolean values MUST NOT be switched off by the permit. This is an explicit amendment to their scope semantics, not an interpretation that GET is inherently safe.

The permit can authorize only the fixed reviewed Printify GET allowlist for existing Global Owner, DigiForgeShop2/personalized_pod, integration3, reviewed provider account and credential reference, PrintifyShop28930593 and production environment. It cannot enable the integration, normal automation, Shop3 or any write. It must not substitute Pop-Up28925875 or infer account/credential identity from dashboard visibility.

Maximum lifetime300seconds,10one-use requests,3requests per rolling minute,3pages,10records/page,1MiB response and20second timeout; no automatic retries. Existing authoritative vault and transport remain exclusive. No caller URL, credential, auth header or body. Record minimized durable receipts; local audit persistence is explicitly the only accepted evidence write and cannot mutate product/order/grant records.

Every request revalidates owner/session, exact binding, account/credential association, expiry, quota, audit persistence and emergency termination before credential use and immediately before dispatch, and again before returning a sanitized response. A new emergency STOP event must invalidate the session even if STOP ALL is already true; persistence failure, uncertainty, revocation or changed binding denies. No automatic renewal/rearm and no replay after unknown outcomes. Emergency revocation/recovery guarantees must be explicitly qualified for the actual installation; no source test alone establishes them.

Missing, ambiguous or unqualified policy/authority/identity/emergency evidence yields DENIED. At expiry, exhaustion, revocation, emergency or completion, return automatically to fully denied state. No provider mutation, product Save/upload/publish/delete, order/support/webhook mutation, marketplace operation or historical replay is authorized."

## Scope and unresolved prerequisite

This is the smallest distinct capability decision, not an implementation instruction. A permissive wording change alone cannot qualify the existing native credential/binding gates, which require normal integration enablement and activation. A future implementation would need an independently reviewed acceptance-only branch through EVERY parent safety/vault/dispatch gate; simply removing those checks is prohibited. No such branch is added here. If the owner requires both locks to remain absolute denials without changing their scope semantics, Stage2 live GET remains impossible and must stay BLOCKED_POLICY. No Cloudflare deployment is authorized or presumed necessary by this proposal.

## Non-secret evidence / approval checklist

- [ ] Exact provider account identifier and evidence source/time; dashboard session is not proof of token identity.
- [ ] Existing credential reference (never value), read scopes, expiry and reviewed association with that account/shop/integration. API scope evidence must come from authorized metadata; no token extraction.
- [ ] Integration3 provider=printify, exact environment/status/enabled state and source of metadata; no enablement implied.
- [ ] Shop2 id2/keypersonalized_pod, provider shop28930593, distinct Pop-Up excluded, Shop3 deferred; reviewed current registry ancestry and binding revision/hash/expiry, independent reviewer and owner decision.
- [ ] Exact source SHA, two archive hashes, member/source manifest, complete offline acceptance results and refused-source limitations resolved or explicitly adjudicated without pretending verification.
- [ ] Independent review and separate adoption/rejection of this policy text; actual emergency-stop and fail-closed semantics qualified before operational use.
- [ ] Separate installation approval for exact artifacts and non-executing rollback plan; no merge/deployment implied.
- [ ] Separate one-session GET approval naming owner, exact endpoint subset, account/shop/integration, evidence fields/redaction, expiry, request budgets and termination conditions. Upload reads excluded unless owned-receipt support is independently implemented/qualified.

Until all relevant gates pass: STOP ALL ON, external lock ON, automation OFF, activation unauthorized, RELEASE HOLD active, LIVE_GET_NOT_AUTHORIZED.
