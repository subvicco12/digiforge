# PR1258 remediation: hosted exact-ZIP audit handoff

Status: **BLOCKED — hosted exact-combined-ZIP audit NOT RUN**.

Candidate: DigiForge `1.0.103.4`, composed from the verified installed-lineage package plus the reviewed PR1258 remediation. The completed archive receipts below identify the candidate; approved intake and a fresh reviewed controls profile are still required. No source-checkout CI run or source-built ZIP establishes audit of these combined bytes.

| Binding | Current value |
| --- | --- |
| Candidate SHA-256 | `336bad544c3f36efe9ec91302885c0063996fc6b3c3c1bd0e2907f09637531b2` |
| Candidate filename and byte length | `digiforge-1.0.103.4-portal-access-update.zip`, 3,089,990 bytes |
| Preservation manifest and counts | `preservation-manifest.json` accompanying the ZIP: 706 files, 689 retained / 3 modified / 14 added / 0 deleted; final source identity and per-file hashes in that receipt |
| Reviewed audit implementation commit | PENDING separate controls review |
| Successful same-repository producer run ID | MISSING |
| Immutable intake artifact ID | MISSING |
| Genuine owner approval comment ID | MISSING |
| Hosted exact-ZIP result/run ID | NOT RUN |

## Existing profile cannot admit this package

Inspection of the existing controls at source head `10b0b7a25019fa0b40bd86658e08b319161c637b` found a profile for `1.0.103.1`, not this remediation:

- `bin/artifact-audit/control.py` unwraps exactly one `digiforge-1.0.103.1-printify-update.zip` member of `3060845` bytes. Intake requires a non-expired same-repository Actions artifact named `digiforge-printify-update-intake`, at most `16000000` bytes, belonging to the supplied successful producer run.
- `bin/artifact-audit/verify.py` pins SHA-256 `aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1`, 691 exact members, and preservation counts 671 retained / 7 modified / 13 added. Both audit workflows also bind their receipts to that old digest.
- The owner route requires a fresh `workflow_dispatch` by `subvicco12`, run attempt 1, and a genuine owner-authored JSON comment on PR1253. Its exact JSON binds artifact digest, workflow implementation SHA, producer run ID, artifact ID, explicit controls approval, explicit acceptance of reduced independence, and the pinned reviewed Drive client hash `b4d7ce286d8ada27278b169213b48e1bd526a754d2bceaaa5eb1db5b4b6c7458`.
- The registered workflow file `digiforge-foundation-audit.yml`, as implemented on this branch, has an optional `owner_artifact_audit` manual entry point calling `printify-owner-attested-artifact-audit.yml`. GitHub acceptance of branch-specific inputs and permissions remains unverified. Ordinary source audit execution is separate. The independent route requires named independent intake and acceptance reviewers, prevents self-review, disables admin bypass and requires recorded approvals.

These existing controls and pins are preserved. Changing an input or posting a new comment cannot make the old profile accept `1.0.103.4`. No old approval is carried forward to the new digest.

## Private intake blocker

The current GitHub connector exposes no release-asset binary upload or Actions-artifact upload operation. Its generic fetch is GET-only; repository file and Git-object writes do not provide an approved private ZIP intake. The previously blocked `uploads.github.com` transfer was not retried. Draft release `435449024` was observed unpublished with no assets and obsolete `1.0.103.1` metadata; it is not a producer or intake receipt for this candidate. The existing private Drive package is an earlier candidate, and connected Drive OAuth is not transferable to the hosted runner.

Repository collaborator permission for `subvicco12` was reported as `admin`; this does not establish the connected app's Actions, administration or secret-management permissions. No credentials, secret provisioning, public ZIP, source-commit ZIP, new storage provider or self-hosted runner is authorized by this handoff.

## Smallest concrete next action

After the final archive receipts exist, identify an already authorized private cloud intake producer/connection that can receive those exact bytes and produce the same-repository Actions artifact. Record its genuine successful run ID, immutable artifact ID, artifact name and a wrapper containing only the final ZIP. No such producer identity currently exists in this handoff.

Separately prepare and review a narrowly scoped new candidate profile binding the final filename, byte length, SHA-256, all manifest members and preservation counts, test coverage and evidence receipts to the exact reviewed audit implementation commit. Preserve checksum-before-extraction, strict member validation, inherited security gates and isolated execution. Review must explicitly decide the attestation thread for PR1258; the existing implementation accepts PR1253 only. Do not silently repurpose the old profile or relax its pins.

For owner-attested mode, the owner must then genuinely approve that exact reviewed profile and new digest/intake tuple. The approval must bind `artifact_sha256`, `audit_source_sha`, `intake_run_id`, `intake_artifact_id`, `approve_controls=true`, `accept_reduced_independence=true`, and the reviewed `drive_client_sha256`, using the final reviewed schema and thread. A preparatory instruction, earlier digest approval or agent-authored owner comment is insufficient. Only then can an authorized fresh owner manual dispatch target the reviewed commit. Record the resulting hosted run and digest-bound evidence; label any result `OWNER_ATTESTED_AUTOMATED` with `independent_human_review=false`. Independent human acceptance requires the separate protected-review route and actual independent reviewers.

This document creates no producer, approval, workflow change or audit run. STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD. Production installation, owner enrollment, migrations, deployment, live supplier actions and release acceptance retain their separate gates.
