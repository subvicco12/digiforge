# Exact-main audit and installable artifact verification — 2026-10-09

Read-only evidence for release selection. **No deployment or external execution authorization is granted.**

| Evidence | Verified value |
| --- | --- |
| Exact main commit | `4eb2d7e992bce22021b17e79e99a7b1576918e64` |
| GitHub workflow | DigiForge Engineering and Safety Audit #4394 |
| Run ID and trigger | `37879311496`, `push` to `main` |
| Run conclusion | SUCCESS |
| GitHub Actions artifact ID | `11593702790` |
| Artifact name | `digiforge-plugin-4eb2d7e992bce22021b17e79e99a7b1576918e64` |
| Outer artifact archive SHA-256 | `614f434d2b7766f381bc6544133f600470fa35d0d206c88389c024b9f8488a33` |
| Inner installable `digiforge.zip` SHA-256 | `3f76ba3254f8aa703596ee6dc475ec2596c024fb592dd13284098c62cf64dcf1` |
| Artifact checksum sidecar | MATCHES inner ZIP SHA-256 |
| Manifest `commit_sha` | MATCHES exact main commit |
| Manifest `sha256` | MATCHES inner ZIP SHA-256 |
| Manifest `file_count` | 434; matches inner ZIP entries |
| Inner ZIP integrity check | PASS; no corrupt member |
| Plugin version | `1.0.103` |
| Manifest database schema metadata | `23` (not the runtime additive schema count) |
| Manifest external actions performed | `false` |
| Manifest production activation authorized | `false` |
| Artifact expiration | 2026-10-23T03:28:53Z |

## Evidence boundary

This verifies the GitHub exact-main workflow result, outer artifact archive checksum, package checksum sidecar, manifest binding, and ZIP integrity. It **does not** certify an installed site, isolated staging acceptance, provider-side Etsy webhook signing/delivery, backup/restore recovery, Master 500 promotion, production readiness, or live external execution.

The previously accepted staging baseline remains PR #992 / Audit #4190 until a separately authorized isolated staging installation and validation. Preserve STOP ALL, external safety lock and disabled automation; no production or provider mutation. Deferred backup/recovery, webhook provider certification, Master 500 promotion and production activation remain REVIEW_REQUIRED or unauthorized.

A new commit after this evidence snapshot requires its own exact-main audit and artifact verification; do not transfer this certification to a later SHA.
