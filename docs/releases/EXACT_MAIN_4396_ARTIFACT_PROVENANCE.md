# Exact-main Audit #4396 — certified artifact provenance

Read-only release evidence dated 2026-10-09. This record **does not** authorize installation, staging changes, production deployment, recovery certification, provider mutation, catalog promotion, or external execution.

| Evidence | Value |
| --- | --- |
| Exact audited main SHA | `a4a6e3de74fbdfe82bd1f06a8247bb13cc3f6839` |
| Engineering & Safety Audit | #4396 — SUCCESS |
| GitHub Actions run | `37884164900` (push to main) |
| GitHub Actions artifact ID | `11595701629` |
| Artifact name | `digiforge-plugin-a4a6e3de74fbdfe82bd1f06a8247bb13cc3f6839` |
| Outer artifact digest reported by GitHub | `sha256:a8c656d975b11480199b5f9f020ecffab6463abdfd886ffd39bac20acaf5d6e2` |
| Artifact size reported by GitHub | 549004 bytes |
| Unit tests | 1150 tests, 7321 assertions — PASS |
| WordPress integration | 199 tests, 1840 assertions — PASS |
| Syntax, scans, package boundaries | PASS in exact-main workflow |
| Plugin version | 1.0.103 |
| Core schema metadata | 23 |
| Runtime additive schema expectation | 24/24 (requires separate installed-site verification) |
| Artifact expiration | 2026-10-23T04:31:43Z |

## Certification boundary

The GitHub artifact metadata binds the archive to the exact audited main SHA. Workflow success covers the reproducible package build and boundary checks. Independent inner-ZIP hashing, checksum-sidecar and manifest readback should be retained alongside this record before any later installation decision; GitHub's outer digest alone is not a substitute for that evidence.

The only accepted isolated staging baseline remains PR #992 / Audit #4190. Later main certification does not imply a newer package was installed or accepted.

Database backup/restore certification remains owner-deferred and REVIEW_REQUIRED. Etsy webhook provider signing/delivery is not externally certified. Master 500 v2 remains MIGRATION_CANDIDATE, not promoted. STOP ALL and external safety lock remain required, automation stays disabled, and production/external execution is unauthorized.

Any commit following `a4a6e3de74fbdfe82bd1f06a8247bb13cc3f6839`, including a merge of this documentation change, requires its own exact-main audit and artifact verification.
