# DigiForge Release Candidate Evidence Gate

This gate prepares a release candidate without deploying it or activating external capability.

## Required repository evidence
- Exact candidate commit has a successful Engineering & Safety Audit.
- Plugin ZIP is built from that exact commit.
- Release manifest commit SHA equals the candidate commit.
- ZIP SHA256 verifies.
- Manifest records `external_actions_performed=false` and `production_activation_authorized=false`.
- Database schema version in the manifest equals the plugin schema constant.

## Required operator evidence before any production smoke execution
- STOP ALL remains ON.
- Activation authorization remains OFF.
- Automation armed remains FALSE.
- Effective consequential external switches remain OFF.
- Queue query is verified and expired leases are zero.
- Queue FAILED/BLOCKED/HUMAN_REVIEW/DEAD_LETTER states are visible for operator review.
- A genuine retrievable production database backup exists.
- The intended audited restore package exists and its checksum verifies.
- Restore instructions match the selected audited checkpoint.
- Recovery drill reports PASS.

## Smoke boundary
Repository/CI smoke tests may create local fixtures only. Production smoke execution is a separate explicit operation and must not call Etsy, Printify, Gelato, tax, banking, fulfillment, or other external mutation endpoints.

## Fail closed
Missing evidence is REVIEW_REQUIRED. UNKNOWN is not success. Evidence flags do not create the underlying backup/package/checksum. No release-readiness result grants Etsy publish or POD production authority.
