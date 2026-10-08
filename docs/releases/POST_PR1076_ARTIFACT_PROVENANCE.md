# Post-PR #1076 release artifact provenance (read-only)

This evidence record does **not** certify exact main, staging, backup/recovery, provider delivery, production or external execution.

| Field | Verified evidence |
| --- | --- |
| Main merge SHA | `39f4bc89827e5d9425db60c9ec996e7c0cb2014e` |
| PR | #1076 |
| Exact audited PR head | `1687b1d6b76ef162b7f4425ec0ad3ae1e7388333` |
| Engineering & Safety Audit | #4371, SUCCESS, run `37818297999` |
| Unit tests | 1150, 7321 assertions |
| WordPress integration tests | 193, 1782 assertions |
| GitHub Actions artifact ID | `11568166892` |
| Artifact name | `digiforge-plugin-1687b1d6b76ef162b7f4425ec0ad3ae1e7388333` |
| GitHub artifact archive digest | `sha256:c3d7f59287b389c81599dafb7d1f4d84ce63cfa6e3aae47b58f984733982c68f` |
| Artifact expiration | 2026-10-22T17:41:51Z |
| Exact-main audit | NOT VERIFIED; PR-head audit is not equivalent |
| Inner installable plugin ZIP checksum | NOT VERIFIED; outer artifact digest is not the inner ZIP digest |
| Staging installation | NOT PERFORMED for this artifact |
| Production deployment | NOT AUTHORIZED |

## Required release gate

1. Obtain an exact-main certification and package manifest without weakening required CI.
2. Verify exact source SHA, artifact archive and inner installable ZIP checksums independently.
3. Preserve the previously accepted staging baseline until a separately authorized isolated staging installation and validation.
4. Keep STOP ALL, external safety lock and automation disabled; no live Etsy/POD/GST mutation.
5. Deferred backup/restore certification, Etsy webhook provider signing/delivery, and Master 500 v2 promotion remain separate restricted gates.

Missing or stale evidence means REVIEW_REQUIRED, not PASS.
