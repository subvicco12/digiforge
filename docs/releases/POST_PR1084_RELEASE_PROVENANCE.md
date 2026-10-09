# Post-PR #1084 release provenance and certification boundary

Read-only evidence snapshot, 2026-10-09. This document does **not** authorize staging installation, production deployment, provider execution, or promotion.

| Field | Verified evidence |
| --- | --- |
| Merged main SHA after PR #1084 | `5a007c8b3f5e7e8caf5942cedacbf704be9dfc13` |
| Exact audited PR head | `ebc68766151ff5766169030e1fd1123a4c319484` |
| Engineering & Safety Audit | #4391 SUCCESS; run `37878438506` |
| Unit tests | 1150 tests, 7321 assertions |
| WordPress integration tests | 199 tests, 1840 assertions |
| GitHub Actions artifact ID | `11593920543` |
| Artifact name | `digiforge-plugin-ebc68766151ff5766169030e1fd1123a4c319484` |
| Outer artifact archive digest | `sha256:47c2888418f879632775c4f929a8bd0bb03a548d30b64034693b77920ec50954` |
| Artifact expiry | 2026-10-23T03:17:21Z |
| Exact-main audit | NOT VERIFIED; PR audit is not a merge-commit audit |
| Inner installable ZIP SHA-256 | NOT VERIFIED; outer archive digest cannot substitute |
| Newly installed staging artifact | NONE; accepted staging baseline remains PR #992 / Audit #4190 |
| Production/external execution | NOT AUTHORIZED |

## Remaining release actions

1. Obtain an exact-main audit for the selected release commit; record its run and checks without claiming the PR audit is equivalent.
2. Verify the artifact's manifest commit binding and independently hash the **inner** installable `digiforge.zip` before any installation.
3. Any new isolated staging installation and acceptance requires its own authorization; do not alter the currently accepted staging package by implication.
4. Keep STOP ALL and external safety lock active, automation off, and provider mutation blocked.
5. Deferred backup/recovery certification, Etsy webhook provider-side signing/delivery, Master 500 promotion, and production deployment remain separately gated and cannot be marked PASS by this record.

Missing evidence remains REVIEW_REQUIRED.
