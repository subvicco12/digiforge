# Standalone Master Blueprint v1.1 — proposed §17 insertion

Status: pending explicit owner approval. Governing document remains v1.0.

Insert immediately after the first sentence of §17 RELEASE GATES:

> For a separately authorized read-only pilot, an Internet-addressable HTTPS endpoint protected by Cloudflare Access Service Authentication may satisfy the authenticated ingress requirement. Approve the exact dedicated DigiForge hostname, HTTPS origin, Access application audience, issuer and service identity before use. Deny public, alternate-origin, workers.dev and preview bypasses. Validate the Access assertion at ingress and separately verify current site, operator and session attestation bound to the exact typed request, account, shop, operation and short-lived grant. Keep Printify credentials in external custody. Require independently governed STOP, revocation, drain and an independently restorable recovery floor. Access authentication alone grants neither operator authorization nor provider access.

No other clause changes. In particular §9's independent recovery, custody and signature requirements remain intact. This proposal authorizes no hostname, Access app, credentials, deployment, provisioning, paid spend or live reads. The owner-reported Free allowances are capacity inputs. They do not establish independently administered services or verify zero incremental cost for the complete topology. Preserve aitoolstore-sub and the Dubnexa isolated continuity witness. No use of those resources is proposed.

Security implications: network reachability increases exposure to unauthenticated traffic. Access assertion validation, origin pinning and bypass probes are required before enabling ingress. Shared account or restore authority cannot demonstrate independent administration or resistance to simultaneous rollback. A synthetic witness running on the same workstation proves neither independence nor hosted ordering.

[Standalone Master Blueprint v1.0](https://docs.google.com/document/d/1-CdZRF2ISZIej1-WDdgwdlEpv4h_3SJQuBxQjZz7ipc/edit)
