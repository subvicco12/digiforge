# Non-Finance MVP recovery engineering

Baseline: cloud/nonfinance-migration-20261028 at273debba206ac113aeefc434dc0ed4c45a5f65c5. Earlier engineeringcabe5ad/audit4818 is historical evidence, not certification of this runtime change. Existing main/PR1242 and all source branches are preserved. One coherent change reuses existing workflow, grants, journals, CAS records, queue dependencies and transport; no new table/API/privilege/provider connection is added.

## Reproduced failures and fixes

1. After a crashed original/native create leaves PENDING, an explicit GET reconciliation can recover its result. If waking the next job then fails, submit remained UNKNOWN forever because the original crash claim was still PENDING. Both role regressions failed before the fix. Submit now recognizes a recovered create only when a persisted result and OBSERVED explicit reconciliation claim both exist; arbitrary/unproven result-only records still block. Original crash history is unchanged.
2. Independent review identified the distinct create-next-job outage: when job creation itself fails after successful reconciliation, resubmission used the unsuccessful original create as dependency, leaving the next job permanently BLOCKED. Both additional cases reproduced. The successful reconciliation job is now the predecessor; missing reconciliation job identity denies. No original create is repeated.
3. Direct reconciliation could queue during a RUNNING create even though the portal disables this action. Reproduced inside the mocked HTTP callback: it added a job and interfered with the current workflow CAS. The server now requires the exact original job/transfer/stage and denies RUNNING or unavailable/mismatched jobs before scheduling reconciliation.

Production changes are limited to `includes/DigitalFactory/DriveGoogleWorkflow.php`. Regression changes are limited to `tests/unit/DriveGoogleQueueTest.php`. The new full journeys cover both create roles x scheduler create/wake outage, explicit GET reconciliation, original claim preservation, stopped gate, expired grant, explicit renewal without dispatch, and completion through source readback/native conversion/readback/PDF export/final technical QA. A negative record-only test preserves fail-closed behavior; a concurrent callback test preserves successful creation without scheduling competing reconciliation.

## Executed verification before publication

- Focused Drive authorization/protocol/journal/queue suites:68tests/545assertions PASS.
- Final full unit suite:1842tests/9984assertions/1existing skip PASS.
- Cross-workflow WordPress selection:90tests/1911assertions PASS (Digital external admission/approval, Drive/portal/queue, FC01 production/multi-product journey). Executed before the final queue refinements; final exact-head hosted CI is mandatory.
- Fresh isolated WordPress control:33tests/290assertions PASS, including previously failing Printify preflight/Schema19 lifecycle plus callback/queue tests.
- Offline regressions:194 PASS after correcting this shell's missing PHP PATH; no test or gate was weakened.
- Configured PHPStan8 baseline and supplementary changed-workflow PHPStan5 PASS; configured coding standards and syntax PASS.
- Three legacy suites and all three actual Printify smoke scripts PASS. Initial two mistyped smoke filenames were command errors, not test evidence; correct repository commands were executed.
- Independent final read-only reviewer: no remaining important findings; separately ran DriveGoogleQueueTest17tests/226assertions PASS.
- Changed-file secret scan found no leaks. No credentials or production/provider evidence were used.

Full WordPress rerun and catalog-profile verification are running against a fresh isolated disposable MariaDB fixture; final result belongs in the SHA-bound handoff and hosted exact-head audit. Initial full run had7fixture failures from persistent custom-table seed identities left by earlier fixture runs. Fresh isolated33-test control passed without source changes, confirming the environment isolation issue. The original failure log is retained privately; it is not represented as a passing run. Each subsequent profile uses a fresh disposable database. No production/staging database was involved. Local WordPress fixture is7.2-alpha, not installed7.1.3 certification; hosted release-matched checks remain required.

Final exact-head Engineering & Safety Audit must run after normal push. Its run/result and final immutable SHA will be reported with the final handoff; no previous audit is assigned to the new commit. Packaging/security gates remain mandatory. No new PR is needed merely to dispatch the existing audit.

## Scope completion and acceptance boundary

Source review and executable selection reuse current Digital identity/admission/QA/approval, source-bound Google OAuth/queues/custody, POD template/native-evidence/artifact/listing, Printify scoped transport and portal protections. No additional independently specified runtime defect was demonstrated in the other reviewed paths. This is bounded engineering evidence, not a claim all possible defects or future product requirements are exhausted.

This batch improves the local recovery portion of BP-live-portal-normal-work without promoting that or any other criterion. Retain246/268 implementation candidates and22UNVERIFIED non-Finance criteria;23Finance-dependent criteria excluded. Preserve500catalog identities/20review waves,360bounded recipes/140artistic-execution-blocked concepts. No Finance files/contracts or statutory/financial authority change.

Remaining commercial/installed acceptance: private production preimages/restore compatibility and missing recovery/staging OWNER routes; genuine mailbox proof and separately authorized installation/configuration/enrollment; actual Weddingv0.5 bytes and independent buyer/content/rights review; authentic provider/account/shop/variant/geometry/native/font/mockup/shipping evidence; installed desktop/mobile sessions; original postlaunch metrics. These remain external/owner/installed-site boundaries, not reasons to manufacture more code or approvals. See preserved release-candidate closure dossier and owner-action queue.

Engineering candidate is reviewable only after its new exact-head audit succeeds. Production installation/operation and commercial release remain NO-GO. STOP ALL ON; external lock ON; automation OFF; Finance excluded; RELEASE HOLD. No provider request, real credential, order, publishing, production/staging write, merge, deployment, activation or live approval was performed.
