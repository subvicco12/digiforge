# Cloudflare read-only inventory — proposal disabled

Starting source: `8f4b375d5b52e461e88f25cfc33400cad5371de4`.
Existing PR1260, feat/printify-api-completion-20261108.

**SECURITY_BLOCKED / NOT EXECUTED.** This is workflow development only. No token
was retrieved, copied, printed, decoded, transferred or used. Public documentation
reads and authenticated GitHub configuration-metadata reads are not Cloudflare
account API requests. No live Cloudflare API request was executed.

## Files

- `.github/workflows/cloudflare-account-inventory.yml`
- `bin/cloudflare-inventory/client.py`
- `tests/offline/test_cloudflare_inventory.py`

The workflow is `workflow_dispatch` only, has no inputs and currently uses a
literal `if: ${{ false }}` job gate. It cannot execute its secret-bearing job.
There is no activation switch in workflow inputs or repository variables.
Removing the literal requires a NEW, separately reviewed and approved revision.
Current owner authorization does not permit doing that or executing the workflow.

## Observed GitHub configuration — 2026-11-09

| Inspection | Actual observation | Decision |
|---|---|---|
| PR1260 | Draft, head matched starting source | Continue existing lane |
| Environments | Only `copilot`; no protection rules, branch policy absent, admin bypass allowed | Not a qualified secret execution boundary |
| Repository Actions permissions | HTTP403, integration lacks access | UNVERIFIED |
| Default workflow token permissions | HTTP403 | UNVERIFIED; proposed workflow explicitly requests contents:read only |
| Main branch protection | Basic branch metadata `protected=false`; detailed protection API HTTP403 | Main is not an approved protected execution path; rule details unavailable |
| Rulesets | Read returned empty list | No ruleset protection evidenced |
| Personal Actions billing | HTTP403 | Billing limits/spending restrictions UNVERIFIED |
| Actions execution | Prior exact-head source audit succeeded | Proves source CI can run, not unlimited billing or environment support |
| Cloudflare repository secret | Owner reports successful addition | Value and effective scope not inspected; presence not independently verified |
| Existing workflow conventions | Source audit and artifact workflows exist; some use mutable action tags | Existing workflows untouched; new profile pins its only action |

Repository PUBLIC/default main were confirmed. Main SHA observed:
`3758d1385f7d4a62947248822c1fe1c89ec9818e`. Existing PR1260 workflow differences
against main are the foundation audit modification and two Printify artifact-audit
workflows; those three files are unchanged by this task.

No environment, rule, branch, secret, repository variable or billing setting was
created or changed. No safe execution branch/revision is approved or established.
The proposed future execution ref is
`refs/heads/security/cloudflare-inventory-reviewed`; it was NOT created. Its full
approved SHA and client hash must be recorded after a later activation revision
is reviewed. A branch name alone is not authorization.

## Secret isolation finding

A repository-level `CLOUDFLARE_API_TOKEN` is potentially available to other eligible
same-repository workflows. Adding an environment to THIS workflow does not protect
that repository-level copy. An environment secret with the same name shadows it
for this job but does not remove the repository-wide exposure. A changed workflow
that omits the environment could still reference the repository secret.

Before ANY live execution, the owner/administrator must establish environment-only
custody through GitHub's secure UI or an approved secret-provisioning channel,
confirm the repository/organization-wide copy is absent from eligible workflows,
and review token scope upstream. Do not supply a token in chat, source, command
arguments or artifacts. This mission does not authorize moving/removing/rotating
any credential. Prefer a separate account-restricted read-only token; the current
read/write token has not been accepted for execution. Whether its residual scope
can be accepted requires separate explicit security review.

Detailed repository settings and protection rules cannot be verified with this
session's current GitHub integration permissions; basic main metadata does explicitly
report `protected=false`. No broad Secrets API access is requested:
GitHub secret values are not readable via that API and are not needed here.

## Required protected execution path (future, not configured)

1. Dedicated environment `digiforge-cloudflare-readonly-inventory`.
2. Required reviewer `subvicco12`, no administrative protection bypass, no wait-only
   replacement for approval. Restrict deployment branches to exactly the approved
   execution branch; no tags or wildcard paths.
3. Protect that branch against direct/unreviewed changes, force pushes and
   deletion. Require exact-revision source CI and CODEOWNERS/workflow review where
   available. Settings are currently UNVERIFIED.
4. Reviewer must compare the event SHA, workflow SHA, fixed path, client SHA-256,
   changes since approval and report schema before releasing the environment.
   This is owner review, not independent human review if the owner initiates and
   approves the run. `prevent_self_review=true` is preferable only when a distinct
   approved initiator/reviewer exists; otherwise do not claim two-person review.
5. Environment-only secret `CLOUDFLARE_API_TOKEN`; remove broad eligible copies via
   separately authorized secure administration. Review all environment consumers.
6. Approved non-secret variables:
   - `DIGIFORGE_CF_EXECUTION_APPROVAL=OWNER_APPROVED_METADATA_ONLY_V1`
   - `DIGIFORGE_CF_INVENTORY_APPROVED_SHA=<full approved execution commit>`
   - `DIGIFORGE_CF_CLIENT_SHA256=<reviewed client file hash>`
   - `DIGIFORGE_CF_ACCOUNT_ID=<approved 32-hex account ID>` or empty for bounded
     token/account discovery only.
7. GitHub requires a workflow_dispatch definition available on the default branch
   for manual dispatch. This new workflow is only proposed in PR1260. Registering
   a reviewed definition/default-branch change needs separate maintainer approval;
   no merge or competing PR is authorized/performed by this mission.
8. Consent to the exact minimized report's visibility and one-day retention:
   artifacts in this public repository are NOT a private artifact store. No raw
   identity/name/response is published. Fingerprints are not anonymization: predictable
   resource names can be guessed and compared to unsalted hashes. If even counts/fingerprints are private,
   keep execution blocked until an approved private report sink exists; do not
   upload a private report to this public repository.

Code-level gates supplement environment/branch/reviewer controls; they cannot
protect a repository-wide credential from an administrator or a modified workflow
which removes the gates. An unavailable or unverifiable protected path remains
BLOCKED. No approval can be fabricated by changing variables.

## Fixed GET allowlist

Base: `https://api.cloudflare.com`, port443, TLS verification required. No caller
URL, headers, method choice, request body or deployment interface. Official API
version prefix `/client/v4` is fixed.

| Operation | Exact path | Scope / permission evidence |
|---|---|---|
| TOKEN_VERIFY | `/user/tokens/verify` | Current user-token verification contract; status/expiry only, not policy scopes |
| ACCOUNTS | `/accounts?page=1&per_page=5` | Accessible account identities; first page only |
| SETTINGS | `/accounts/{account}/workers/account-settings` | Workers Scripts Read or applicable documented account-read permission |
| WORKERS | `/accounts/{account}/workers/scripts` | Workers Scripts Read / applicable Workers metadata-read permission |
| NAMESPACES | `/accounts/{account}/workers/durable_objects/namespaces?page=1&per_page=50` | Workers Scripts Read / applicable Workers metadata-read permission |
| SUBSCRIPTIONS | `/accounts/{account}/subscriptions` | Billing Read, optional; 403 is reported, not a reason to request Billing Write |

Exact official references:
- https://developers.cloudflare.com/api/resources/user/subresources/tokens/methods/verify/
- https://developers.cloudflare.com/api/resources/accounts/methods/list/
- https://developers.cloudflare.com/api/resources/workers/subresources/account_settings/methods/get/
- https://developers.cloudflare.com/api/resources/workers/subresources/scripts/methods/list/
- https://developers.cloudflare.com/api/resources/durable_objects/subresources/namespaces/methods/list/
- https://developers.cloudflare.com/api/resources/accounts/subresources/subscriptions/methods/get/
- https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments
- https://docs.github.com/en/rest/actions/secrets

No account-owned-token verification fallback is invented. If the user-token verify
endpoint is unavailable for the actual token type, report TOKEN_NOT_QUALIFIED and
review its exact documented verification contract separately. A 403 is not proof
that a token is invalid. Scope/expiry/account access remain live-unverified now.

Quota/usage telemetry and effective token policies are NOT inferred from resource
listing, account settings or subscription counts. No undocumented endpoint or
GraphQL POST is used. Worker/DO entitlements, actual free capacity, costs and
billing plan remain UNVERIFIED unless independent documented evidence establishes
them. Successful list access establishes only that read's availability.

## Limits and report projection

- Maximum six requests total, ten-second socket timeout, 90-second admission
  budget, five-minute job timeout, no retries or automatic pagination.
- Account list page1/per_page5; namespaces page1/per_page50. Worker scripts has no
  invented pagination parameters. Missing totals remain PARTIAL/unknown.
- At most 1MiB response bytes; duplicate JSON keys, invalid JSON/container/type,
  non-200 responses and oversized bodies are not accepted as observations.
- 401 stops further admission. 429 records bounded Retry-After and stops further
  admission; no sleeping/retry loop. 403,404,redirects,5xx,timeout,transport errors
  have distinct bounded categories. Raw exception/provider error text is discarded.
- An optional preapproved account must match a valid account actually observed on
  the first page. No selected account means no resource probes. An unseen account
  means ACCOUNT_UNAVAILABLE (possibly outside bounded inventory), not no ownership.
- Account IDs and resource names are hashed before reporting. No raw names,
  emails, account IDs, roles/policies, secret bindings, invoice amounts, headers,
  cookies, tokens or provider response bodies are retained. Names are deliberately
  represented as fingerprints to avoid exposing unrelated projects publicly.

`report.json` has schema `digiforge-cloudflare-inventory-v1`, connection state,
operation-level state/HTTP status, token status/time-range observations, bounded
counts, partial flags and identity fingerprints. Unknown scopes, entitlements,
quota/usage and billing remain explicit UNVERIFIED. See `REPORT-EXAMPLE.json` for a
synthetic example, not actual account evidence.

## Execution and code custody

No third-party action is used. The only action is official upload-artifact v4.6.2
pinned to verified commit `ea165f8d65b6e75b540449e92b4886f43607fa02`.
`permissions: contents: read`; no write scopes, checkout, dependency install,
Wrangler, deployment command or arbitrary inputs. No shell tracing.

After a FUTURE approved activation revision, admission checks repository, event,
exact branch, workflow ref, event SHA, workflow SHA and approved client hash. A
contents-read GitHub token retrieves only that client file at the exact SHA from
a fixed GitHub API path. This pre-secret step receives no Cloudflare token.
The secret-bearing step reads a bounded regular private file with O_NOFOLLOW,
hashes it and compiles/executes the SAME bytes, without reopening. Python `-I`
excludes repository/PYTHONPATH injection. Only this step receives the CF token;
main removes it from the environment, never writes it, and prints only a constant
completion/failure category. There is no live token during development/tests.

The sanitized report is created exclusively, mode600 under a mode700 runner temp
directory, then only that fixed file is retained for one day. No client/source,
logs, raw response or private configuration is uploaded. The artifact action does
not receive the Cloudflare secret environment variable. Fingerprints are not
anonymization: predictable resource names can be guessed and compared to hashes.
Artifact visibility therefore remains a separate approval gate.

## First authorized run — instructions, NOT permission

1. Resolve all SECURITY_BLOCKED items above and inspect the activated replacement
   revision; the current literal-disabled proposal cannot run the inventory.
2. Record the exact approved execution branch/SHA, workflow bytes and client hash;
   separately approve credential scope, account selection, environment controls
   and minimized artifact visibility. Do not approve PR code simply by its title.
3. Verify workflow registration and GitHub environment/branch protections through
   authorized metadata. If unavailable, STOP. Do not dispatch this proposal.
4. Only after explicit separate execution approval, an authorized initiator may
   manually dispatch the reviewed workflow on the exact approved execution ref:
   `gh workflow run cloudflare-account-inventory.yml --repo subvicco12/digiforge --ref security/cloudflare-inventory-reviewed`
5. Owner reviews the exact pending environment job/revision and approves only the
   agreed metadata reads. No input or variable substitutes for this approval.
6. Read the minimized artifact; distinguish each HTTP outcome, partial inventory,
   unknown scopes, quotas and entitlements. Verify its workflow run/head SHA.
7. Expire/remove the execution-approval variable after the run through separately
   authorized administration. Never treat a successful inventory as permission
   to deploy a Worker, create a namespace, transfer credentials or call Printify.

This task executes NONE of these future steps. STOP ALL/external lock ON,
automation OFF, RELEASE HOLD; all DigiForge runtime/source features preserved.
