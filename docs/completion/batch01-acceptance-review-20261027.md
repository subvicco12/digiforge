# Batch 01: website acceptance review execution

Baseline verified: repository subvicco12/digiforge, branch
feat/google-drive-scoped-grant-contract-20261024, clean tracked and untracked tree,
HEAD 9c8d3a71c1c36fb6b501a2f39b42655a28208875.

## Implemented boundary

The portal previously exposed read-only acceptance status while typed proposals
and independent decisions were available only through internal PHP methods. The
existing reviewed-contracts panel now exposes an advanced, explicit local editor:

- Propose a typed version 1 or 2 acceptance contract for digital or personalized POD.
- Inspect the complete current proposal, author/reviewer, revision and hash.
- Submit an independent APPROVED, REJECTED or REVOKED decision against that exact
  revision/hash. Every mutation requires a fresh explicit confirmation.
- Read the existing status and evaluated evidence through the unchanged status view.

All persistence uses ReviewedAcceptanceContract and ReviewedLocalEvidence. No new
review store, schema, roles, grants, trust root or execution mechanism is added.
Existing immutable lifecycle, audit transaction, author/reviewer separation,
revocation, expiry, authorization drift, CAS and replay checks remain authoritative.
Transport adds authenticated shop/settings permission, REST nonce, strict method,
content type/query/envelope validation, a 32 KiB limit and private no-store responses.
Inactive shops cannot use these acceptance mutations.

The editor supports plain and pretty REST URLs, displays data through textContent,
rejects foreign shop/criterion/scope replies, discards responses after edited input
and never automatically retries uncertain writes. Proposal examples contain null
observations and explicit replacement identities: they are not supplier evidence.
The advanced JSON editor retains the existing typed contracts rather than inventing
a simplified schema that could silently discard attribution or review requirements.

Selected-shop landing navigation now includes Research, Orders and Fulfillment,
uses the destination Automation capability and exposes Approvals to either research
or product reviewers. Link labels are readable workflow names. Every link still
requires exact shop/capability authorization returning true; unknown scopes and
error objects fail closed. No global or Finance pages are added to this projection.

## Validation

Local official PHP 8.3 runtime was downloaded and verified against its release
manifest SHA-256; test tools and generated output are confined to ignored .runtime.
The runtime needs its local php.ini so PHPUnit subprocesses inherit extensions.

- Acceptance transport: 3 tests / 19 assertions pass.
- Focused OWNER/portal/acceptance/navigation selection: 22 tests / 231 assertions pass.
- Navigation behavior alone: 3 tests / 81 assertions pass, across all four shops.
- Broader supported unit run: 1,822 tests / 9,789 assertions pass. Only the three
  baseline-confirmed unsupported classes above are excluded; no production gate
  or test assertion is relaxed. Existing dynamic-property deprecations still print.
- Node DOM-boundary harness: 16 scenarios pass, covering proposal, decision,
  inspection, plain/pretty URLs, failed/foreign/stale responses and confirmation.
- Full unit execution: 1,831 tests / 9,733 assertions, two errors, three failures,
  one skip. Errors are optional ExperimentalMattingPipeline and OfflineCutoutPipeline
  runtime configuration; failures are the three Windows artifact-path cases in
  DeterministicPersonalizationSourceIntegrityTest. Full suite is not claimed green.
  An untouched baseline production-source archive with the unchanged three test
  classes and bootstrap reproduced exactly two errors, three failures and one skip
  (12 tests / 25 assertions). The archive's export-ignore rules omit tests, so the
  unchanged test sources were copied into that isolated baseline harness explicitly.
- Changed PHP files pass syntax checks; JavaScript parses; git diff --check passes.
- Independent read-only reviewer found no important defect. Its request for actual
  route dispatch and inspection coverage is addressed in the new native tests.

New WordPress regressions exercise existing ledger proposal/approval/revocation,
self-review denial, hash/revision/replay denial, nonce/authentication/foreign scope,
input bounds, plain routing metadata, complete inspection and real registered REST
dispatch. They have not run in this environment: no WordPress test installation is
configured. Their syntax is verified; hosted native execution remains required.

## Acceptance and operations

No acceptance status is promoted. The 22 unresolved non-Finance criteria remain
unresolved; the editor is a bounded software capability, not installed-site,
supplier, artistic, linguistic, performance or commercial acceptance. All 500
Master500 identities, wave plan and prior evidence remain unchanged. Existing
OWNER, Google, Printify, Etsy and personalization implementations are retained.

No production action, provider request, email, protected configuration write,
signing, Finance operation, merge, deployment or PR publication occurred. STOP ALL,
external lock, automation OFF and release hold remain the inherited boundary.
Work is local and reviewable; a hosted exact-candidate audit is still required
before integration or any acceptance claim.
