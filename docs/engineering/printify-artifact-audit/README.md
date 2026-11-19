# Proposed independent-review artifact audit — approval required before dispatch

A separate owner-attested alternative is described in [OWNER-ATTESTED.md](OWNER-ATTESTED.md). Its reduced independence must be explicitly accepted; it does not remove the controls of this independent-review mode.

Owner authority: subvicco12, designated in the Codex Cloud session; audit-admission permission https://github.com/subvicco12/digiforge/pull/1253#issuecomment-6660934684 . Implementation stays on PR1253. No artifact workflow has been dispatched, no independent PASS issued, and the existing source audit/allowlist is unchanged.

## Fixed artifact and intake

Only DigiForge1.0.103.1 ZIP SHA256 `aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1`, exactly3060845 bytes. The committed metadata manifest describes691 files:671 unchanged,7 reviewed edits,13 additions,0 deletions. Raw installed source/ZIP is not committed. The proposed ZIP is not substituted by source checkout and is never rebuilt.

A maintainer-approved successful **same-repository Actions run** must supply an authenticated artifact named `digiforge-printify-update-intake`, containing only `digiforge-1.0.103.1-printify-update.zip` at its root. The two numeric run/artifact IDs are manual workflow inputs. No arbitrary URL, external-repository intake, user shell input, production credential or public raw-baseline upload is supported. Approval of this implementation does not invent an intake producer or upload the package. The maintainer must designate/provision the approved producer and artifact IDs. Only audit evidence is uploaded by this workflow; candidate/private baseline sources are not re-published.

Authenticated metadata validation, wrapper size bounds and strict one-member unwrap precede fixed-size/SHA256 validation. The plugin digest is checked before any plugin extraction. All members, permissions, paths and hashes are checked before writing; target must be new. No symlinks, traversal, duplicate or unexpected member or executable permission is allowed.

The inherited Drive classification is resolved for this proposed policy by [DRIVE-CLIENT-POLICY-RESOLUTION.md](DRIVE-CLIENT-POLICY-RESOLUTION.md); its exact transport/gate/composition admission is shared by source and artifact scans. Historical statements below describe the original proposal and do not replace that resolution.

## Required protection and independent acceptance

Configure environments `printify-artifact-intake` and `printify-artifact-acceptance` with **named User reviewers**, prevent_self_review=true, can_admins_bypass=false; Team-only/mixed policies and unknown/absent API evidence fail closed. Reviewers must exclude both dispatch actor and PR author. Current repository has only subvicco12 listed; a genuinely independent authorized reviewer/service user must be provisioned and designated, not impersonated by Codex. No GitHub self-approval is used.

The first intake step verifies actual recorded approval from a configured independent user before downloading the ZIP, building tools or running package code. The separate post-result environment requires a second approval after audit succeeds; final receipt revalidates both named approvals. Only run_attempt1 is supported: reruns require a fresh dispatch, preventing reuse of earlier-attempt approval receipts. API permission failures stop admission; do not remove checks to make a run green.

The workflow file is new; GitHub normally requires registration on the default branch before manual workflow_dispatch is available. No merge/default-branch change is authorized here. The workflow-authority owner must review/provide an approved registration path, protected-environment configuration, access-controlled intake artifact IDs and approved GitHub API permissions before dispatch. The new workflow and source-audit evidence are proposed changes, not execution approval. Existing ordinary PR source CI is not exact-package certification.

## Narrow Drive sender disposition for review

Prior independent review found no concrete unsafe origin/TLS/redirect/scope/gate/secret-custody defect in the trusted composition. Exact query/form/body and request-binding restrictions depend on canonical composers; WordPress runtime hooks/production settings remain outside static evidence. Existing source-policy mismatch is not silently waived.

The proposed artifact-only gate admits exactly SHA256 `b4d7ce286d8ada27278b169213b48e1bd526a754d2bceaaa5eb1db5b4b6c7458` at `includes/DigitalFactory/DriveWordPressGoogleHttp.php` and only its single exact sender line. Any byte change stops for new review; another sender/path/line cannot inherit admission. All remaining existing HTTP client assertions execute unchanged, as does per-line credential scanning; known private-key/token patterns additionally deny. Runtime pure boundary tests exercise denied gate, foreign origins, TLS/redirect/timeout/size/method/path/header restrictions with zero network calls. This proposed disposition requires independent controls review, not blanket filename exclusion.

## Isolation and evidence

Tool dependencies and WordPress fixtures are prepared in a Docker image only after intake approval. Artifact classes run with `--network none`, read-only artifact mount, resource limits and no production secrets; disposable MariaDB uses a Unix socket. A WordPress deny-HTTP filter provides a second layer. No shell command in artifact contents is invoked. The root plugin is bootstrapped only inside the disposable test fixture, where normal fixture migrations may run; no production database is accessible.

PHP lint,42 source-interface checks,21 Drive boundary assertions, the exact-package WordPress suites, size-guide contracts and isolated rate-ledger suite run on the extracted ZIP. Rate tests explicitly substitute their connection context; they do not establish production binding/dispatch acceptance. JUnit, dependency versions, intake/approval metadata, security output, exact ZIP digest and audit code commit are emitted. Candidate ZIP is never uploaded as evidence. Independent post-result acceptance is required; failures/skips must remain visible. Do not relabel old Audit4869 as this artifact's certification.

Local implementation checks: admission negative tests and known-ZIP policy scan, YAML parsing, PHP harness lint and pure interfaces/boundary checks. Hosted job/Docker integration must still be validated after the required approvals/configuration; no hosted run is claimed.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No installation, deployment, merge, production migration, live provider request, grant/exception or Finance activation. Production schema/binding/recovery/current-source and separate installation gates remain independent of this audit.
