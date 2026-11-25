# Proposed Blueprint v1.0 clarification — Access ingress

**For explicit owner review. No amendment adopted.**

Allow an Internet-addressable hostname protected by Cloudflare Access Service Auth to serve as authenticated gateway ingress only after the owner approves the exact hostname and service identity and a review demonstrates that no public Worker, origin or preview route can bypass Access. Hostinger initiates TLS to the pinned endpoint. The ingress validates the Access assertion with the approved issuer, application audience, expiry and service identity, and checks a separately authenticated operator/session bound to the exact typed read request. Use no redirects. Separate transport identity from grant authority. Access alone conveys neither Printify credentials nor read admission. If an Internet-addressable entry is outside the approved interpretation, require a network-private alternative. This proposal does not change Blueprint text, lock semantics, mutation rules, independent authority or recovery requirements.

## Single-owner operational model and qualification

One owner can administer Hostinger, Cloudflare and service policy. A dedicated Access service identity and distinct gateway/custody bindings can limit credential exposure and routine privilege. Shared administration and restore power mean this cannot establish independent custody, rollback resistance or witnessed STOP/drain. Keep production reads held until the independently governed boundary required by the Blueprint is evidenced or an explicit separately reviewed amendment changes that requirement. A zero-incremental-spend target is plausible only if existing entitlements cover Access, Workers, SQLite Durable Objects and the independent policy/custody/recovery services. Account entitlements, budget and increments are unverified.

## Remaining concrete integrations

| Source boundary | Missing acceptance or decision |
| --- | --- |
| ProtectedGetHttp and HostingerReadIngress | Release contains an intercepted WordPress HTTPS fixture and private synthetic ingress. Owner must approve the real topology, exact endpoint, service identity, JWT validation settings and tenant/session attestation issuer before a live client or route is enabled. A real token must come from an approved service credential holder, never from fixture configuration. |
| ProtectedReadAdmission and POLICY | Choose an independently controlled monotonic recovery floor and ordered policy service. Demonstrate STOP and revocation versus dispatch/reply delivery, separately governed restores, and manual reconciliation of divergent or uncertain admissions. No blind reset or replay. |
| FIXTURE_CUSTODY | Name the custodian and qualify fixed Printify HTTPS GET egress, redirect denial, time/body bounds and least-privilege account/shop identity. Keep provider credentials outside WordPress. |
| CONTRACTS and REPLY_SIGNER | Pin the deployed strict validator/projection and canonical signature implementation. Appoint grant and reply key custodians and establish rotation, revocation, audit and fail-closed health. Synthetic fixture signatures are not operational credentials. |

[Standalone Master Blueprint v1.0](https://docs.google.com/document/d/1-CdZRF2ISZIej1-WDdgwdlEpv4h_3SJQuBxQjZz7ipc/edit)
