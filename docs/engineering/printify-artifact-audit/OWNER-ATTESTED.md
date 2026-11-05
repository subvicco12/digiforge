# Owner-attested automated artifact audit — proposed alternative

Authority: https://github.com/subvicco12/digiforge/pull/1253#issuecomment-6661899683 . No separate independent human is available. This process MUST label its result `OWNER_ATTESTED_AUTOMATED`, with `independent_human_review=false`; it is not independent human acceptance.

## Governance finding and scope

`SECURITY.md` requires credential isolation, untrusted-input handling, capability/validation/audit/idempotency controls and a human publication gate. It does not mandate an independent human for non-production automated artifact checks. Reviewed release/engineering material contains production acceptance and independently reviewed ownership prerequisites; those are not waived or replaced by an artifact test. The two-review-environment requirements were introduced by the earlier proposed artifact-audit design, not discovered as a repository-wide mandate. That separate protected-review workflow and its checks remain unchanged.

The owner expressly authorizes preparation of this alternative subject to appropriately reviewed controls and explicit acceptance of reduced independence. GitHub branch-protection metadata is inaccessible to the current integration (403); no conclusion about merge/branch protection is made and no merge or governance setting is attempted. If another applicable governance rule is supplied requiring independent human artifact review, this proposal must remain blocked.

## Minimal alternative

The existing registered `digiforge-foundation-audit.yml` gains optional manual-dispatch inputs. All normal PR/push source-audit behavior and global HTTP/secret policy remain intact. A manual `owner_artifact_audit=true` dispatch calls the new separate reusable workflow at the selected branch commit. It requires contents/actions/pull-request **read** permissions only, and does not need either protected environment or any protected-environment self-approval. This is a distinct owner-attested mode, not a bypass route to a claimed independent PASS.

Before any candidate download/build/execution, it requires a genuine owner-authored comment on PR1253. The entire comment body must be this strict JSON object (replace placeholders only after controls and intake are approved):

```json
{
  "artifact_sha256": "aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1",
  "audit_source_sha": "<exact 40-character workflow implementation commit>",
  "intake_run_id": "<approved successful same-repository producer run ID>",
  "intake_artifact_id": "<approved digiforge-printify-update-intake artifact ID>",
  "accept_reduced_independence": true,
  "approve_controls": true,
  "drive_client_sha256": "b4d7ce286d8ada27278b169213b48e1bd526a754d2bceaaa5eb1db5b4b6c7458"
}
```

This is a proposed reviewable attestation template, NOT a posted approval. Codex will not fabricate it on the owner's behalf. The preparatory owner decision is not silently treated as this exact controls/intake acceptance. Its JSON and hash, comment URL/update time, source commit, artifact identity and test receipts are retained in immutable-per-upload Actions artifacts. Only an explicit owner manual dispatch on run_attempt1 is admitted; different actor/event, reruns, wrong comment author/type/thread, missing approvals or changed source/digest/intake fail closed. GitHub is the attestation provenance authority; these are recorded owner assertions, not cryptographic signatures or independent human review.

## Unchanged technical gates

Only the fixed3060845-byte ZIP is accepted; authenticated same-repository producer metadata and wrapper checks, checksum-before-extraction, all691 manifest member checks and671/7/13/0 preservation counts remain mandatory. No arbitrary URL or candidate rebuild is allowed. The exact inherited Drive client hash and sender line are narrowly reviewed, and all remaining inherited HTTP assertions plus secret scans still run. Pure negative boundary checks and isolated SQL/WordPress tests use the artifact's files. Docker executes with no network, read-only artifact and disposable Unix-socket database. Source CI, isolated test processes and independent automated source review are distinct evidence sources, never labeled independent human review.

A successful complete job can record automated technical PASS for that exact digest; any missing/failing check denies. Old Audit4869/source-checkout CI cannot substitute. Docker/runtime and hosted integration have not yet been exercised for this new workflow; local tests are implementation validation only.

## Current operational blockers and exact next actions

1. **Secure intake still missing:** no `digiforge-printify-update-intake` artifact or approved producer/run/artifact IDs exists. No arbitrary/public download substitute is approved. GET Actions-secrets public-key and Actions-settings APIs return403 for this integration; its advertised repository admin role does not prove those app permissions. No repository secret or credential was retrieved, created or exposed. The authority must enable an approved cloud intake producer/connection with necessary permissions, or identify an existing approved producer. No owner manual filesystem/SQL/desktop inspection is requested.
2. **Concrete acceptance pending:** after reviewing this implementation and fixing intake identity, owner posts the exact JSON above, explicitly accepting reduced independence and approving controls. No duplicate blanket permission request is needed. No audit dispatch occurs before this record exists.
3. **Hosted execution pending:** the existing registered source workflow is the manual entry point, selecting the PR branch ref and owner-mode inputs. This is intended to avoid registering/merging the new reusable file on main solely to dispatch it; actual GitHub ref/input permission acceptance is still unverified. Any registration/permission failure must stop and be reported, never fixed by a merge or bypass without authority.

No environment creation, independent reviewer fabrication, secret provisioning, workflow dispatch, plugin ZIP change or production action is performed by preparing this proposal. STOP ALL ON, external lock ON, automation OFF, RELEASE HOLD. Installation still requires its separate schema, binding, current-source, recovery/restore and installation acceptance gates.
