# Cloudflare inventory execution setup — 2026-11-10

**SECURITY_BLOCKED. The remote execution branch was NOT created.**
The workflow remains literally disabled. No Cloudflare request, workflow dispatch,
secret-value access, production operation or infrastructure deployment occurred.

## Independently verified GitHub observations

- Repository public; default branch main. Connected account metadata says admin,
  but integration API permissions are narrower than the account's role.
- Environment `cloudflare-inventory-readonly` exists.
- Custom deployment branch policy allows exactly `cloudflare-inventory-execution`
  (branch, not tag; no wildcard).
- `can_admins_bypass=false`. No required reviewer rule exists, matching sole-owner
  configuration. No review gate is implied by the disabled bypass setting.
- Execution branch GET returned404; no branch was present.
- Rulesets list empty. Detailed branch-protection reads return403.
- Attempt to create an active future-branch update/deletion ruleset returned403
  `Resource not accessible by integration`. NO ruleset was established.
- Environment secret-name metadata read returned403. The owner reports
  `CLOUDFLARE_READONLY_API_TOKEN` exists; presence/provenance and actual Cloudflare
  scopes remain unverified. No value was requested or accessed.
- Actions permission and billing/execution-limit reads return403. Existing source
  CI can execute, but this does not prove unbounded usage or a secret-safe path.

The exact missing control is enforceable protection against unreviewed execution
branch updates. A matching environment branch name does not supply that control.
The collaborator snapshot lists only subvicco12 as writer/admin. That is not a
substitute for branch creation protection against other eligible integration paths.

No paid plan upgrade is inferred from a403; this is an integration permission
failure. Branch protection/rulesets are supported in principle for public GitHub
repositories, subject to actual account/platform controls.

## Prepared source and isolated branch proposal

The disabled workflow and fixed client now use:

- Environment: `cloudflare-inventory-readonly`.
- Secret expression and process variable: `CLOUDFLARE_READONLY_API_TOKEN` only.
- Execution ref: `refs/heads/cloudflare-inventory-execution`.
- Client SHA-256 pinned literally in both verification steps:
  `7427adfcce90601c325ab7a75d6e7faede694a9863c89e3cd85e45fe12274de2`.

The old repository-wide `CLOUDFLARE_API_TOKEN` is not referenced in either executable
file, and there is no alternative-secret or expression fallback. GitHub's generic
same-name environment-to-repository secret resolution cannot be independently
excluded without secret-name metadata: verify that no repository/organization-wide
`CLOUDFLARE_READONLY_API_TOKEN` copy supplies an unintended fallback before any run.

[Exact isolated proposal](execution-branch-proposal/) preserves only the two
reviewed files at their root paths. No unrelated PR source/history is copied.
Its local root commit `e806534487e9d653d912ea5758a8e6b6987c7b42` is NOT published to
GitHub or an execution branch. The raw commit object and file hashes are preserved
for reproducible reconstruction; this is not an owner attestation or permission
to publish. See the proposal manifest and required-ruleset JSON.

Security properties retained: manual workflow_dispatch only, no inputs/automatic
triggers; literal `if: ${{ false }}`; contents:read; exact SHA/ref checks; no checkout
or dependency install; fixed six-operation HTTPS GET grammar; no redirects,
mutation paths, deployment commands or retries; bounded requests/responses/time;
checksum and execution use the same bounded bytes; only one secret-bearing step;
minimal constant logs and counts/fingerprints only in artifacts; first-party
artifact action pinned to ea165f8d65b6e75b540449e92b4886f43607fa02.

35 mock tests passed, including the approved-environment/ref/secret/pin alignment
and checksum replacement race. No token, network or production data is used in
these tests. Original endpoint/schema/report rationale is preserved in
[historical review](HISTORY-20261109.md); its old environment/secret/ref instructions
are superseded by this page.

## Required owner/platform action

Authorize an approved GitHub administrative channel with repository ruleset /
branch-protection write capability (Administration write for applicable REST
operations), or have the repository owner apply the exact reviewed ruleset through
GitHub's secure settings. This does NOT require Cloudflare credentials, credential
values, broad Secrets API access or billing write permissions.

Two independent rulesets are required before initial creation:

1. `required-ruleset.json`: immutable update/deletion freeze, NO bypass actors,
   fetch-and-merge false, exact execution branch only.
2. `required-creation-ruleset.json`: restrict creation of that exact branch; the
   only proposed bootstrap bypass is exact GitHub User subvicco12 (public ID
   320672644), not all administrators or arbitrary apps. Refresh the actor identity
   and verify effective rules before use. After exact creation, apply
   `post-bootstrap-creation-lock.json` to remove even that creation bypass.

These are proposals, not installed controls. The environment already admits a
nonexistent branch with reviewers OFF: update protection ALONE would let an
eligible writer create it first with a malicious push workflow. Therefore the
creation restriction is mandatory; do not publish until BOTH rulesets are active
and verified, or keep secret eligibility withheld through an explicitly reviewed
administrative quarantine. No environment changes were made here.

The separate update/deletion freeze intentionally blocks ALL later branch updates, including unreviewed owner pushes. Privileged
owners can still reconfigure repository security; any controlled unlock or later
activation revision needs separate explicit authorization and review.

Verify both active rulesets and effective branch rules BEFORE publishing the two-file
root commit, then verify branch SHA/tree and rules again. If enforcement cannot
be inspected or proved, do not create the secret-eligible branch. Do not substitute
an unprotected branch, permissive bypass actor or environment name restriction.

## Future approved run — not authorized now

1. Establish/verify BOTH creation restriction and update/deletion freeze through
   an authorized administrative path. Only the exact trusted bootstrap User may
   create the reviewed two-file root; then verify SHA/tree/rules and remove the
   initial-creation bypass. If any protection cannot be proved, STOP.
2. Verify environment restriction, disabled bypass, secret-name presence and
   environment-only provenance without reading values. Review Cloudflare read-only
   scopes upstream; actual token validity/permissions remain untested now.
3. Separately review/approve a future activation revision. Current literal false
   MUST remain: current source cannot execute the inventory. Any controlled branch
   update/unfreeze, new SHA and relock need explicit reviewed approval.
4. Register a reviewed workflow_dispatch definition on default main through a
   separately authorized process; no merge is authorized by this setup task.
5. Bind approval variables to the exact future execution SHA and approved account,
   confirm same-byte client pin, and approve minimized public-repository artifact
   visibility. Fingerprints are guessable, not anonymization; no raw names/bindings
   are exported. With reviewers OFF, the revision/branch controls and explicit
   owner execution decision are essential; do not claim independent human review.
6. Only after separate execution authorization and all controls pass, use:
   `gh workflow run cloudflare-account-inventory.yml --repo subvicco12/digiforge --ref cloudflare-inventory-execution`
   This command was NOT run. Current disabled proposal is not eligible for live use.

The old repository-wide read/write token remains a residual exposure to other
eligible repository workflows. It was not accessed, referenced by this profile,
changed or deleted. Review its custody separately; an environment does not secure
an existing repository-level copy.

No source/runtime/plugin version change outside this workflow/client and related
review/tests is introduced. No plugin ZIP issued. STOP ALL/external lock ON,
automation OFF, RELEASE HOLD preserved.

Official protection references:
- https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets
- https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets
- https://docs.github.com/en/rest/repos/rules#create-a-repository-ruleset
