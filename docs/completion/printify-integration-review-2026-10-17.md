# Printify integration review — Codex C

Observed 2026-10-17 03:10:51 UTC (08:40:51 IST). Authority: Blueprint v6.0, Master 500 v2 and current safety governance. Review scope: #1171, #1172, #1189 only; no full-product acceptance claim. The proposed operating manual in #1190 is not on verified main. No AGENTS.md or operating manual was found in the reviewed main tree. Existing execution ledger is unchanged.

**Decision: INTEGRATION BLOCKED.** Three source PR audits pass independently, but two merge conflicts, three reproduced acceptance failures and missing composed registration coverage prevent combined acceptance. No source PR, implementation, Finance, shared CI/installer, migration, website, staging or production configuration was edited by this review.

## Exact-head evidence

Verified main: `844107ab9baf7e1303cd47708e9eab3bc700b348`. All three PRs target this same main, remain OPEN/unmerged and are individually mergeable against it. That does not mean they merge cleanly with each other.

| PR | Exact head | State / changed files vs main | Reused exact-head audit | Unit; WordPress; offline |
| --- | --- | --- | --- | --- |
| [#1171](https://github.com/subvicco12/digiforge/pull/1171) | `9ac1c81c3bd08b4458db1278dc80e8c3be7016ca` | Open; 9 | [#4607 PASS](https://github.com/subvicco12/digiforge/actions/runs/38228911309), 22/22 steps | 1,155/7,353 assertions; 207/1,972; 61 |
| [#1172](https://github.com/subvicco12/digiforge/pull/1172) | `bb6edf3f75b5b0dcedd7d4a1338b1564fcdd0234` | Open; 12, includes #1171 | [#4609 PASS](https://github.com/subvicco12/digiforge/actions/runs/38240532273), 22/22 | 1,157/7,364; 215/2,082; 61 |
| [#1189](https://github.com/subvicco12/digiforge/pull/1189) | `8ea1f07dbe91b65272fe9f20a5e1388838c2afdd` | Draft; 12, copies owned foundation files without #1172 ancestry | [#4713 PASS](https://github.com/subvicco12/digiforge/actions/runs/38994396321), 22/22 | 1,152/7,340; 207/1,966; 61 |

Workflow listings, job steps and actual checkout/test log lines were independently read. #1171/#1172 include the installed Composer advisory step; #1189's standalone workflow lacks it, although its PR reports a separate local advisory check. No audit was rerun for this read-only assessment; no combined-head audit exists. No independent artifact-download/equality claim is made.

## Integration and acceptance matrix

References below use #1189 paths/line numbers unless another head is named.

| Boundary | Implemented evidence | Integration requirement / limitation |
| --- | --- | --- |
| Grant scope and expiry | `PrintifyCatalogReadContract::normalize/endpoint`: exact capability, account/integration, business/store/program, 1–10 requests, <=1h absolute expiry, bounded IDs | Contract blob is identical across all three PRs; reuse once. No shipping/order/product-write scope. |
| Shop/credential binding | `PrintifyCatalogReadRepository::grant/binding` L128–137; `authorizeReservedAttempt` L76–97 | ACTIVE ownership registry, CONFIGURED/enabled Printify connector, exact configured scope and integration snapshot; credential fingerprint additionally checked by #1189. Local configuration is not API-certified provider-shop ownership. |
| Safety exception | `readPermission/protectedPosture` L52–72; #1172 exception contract | Separate owner approval, grant/site/binding/expiry, exception revocation/audit anchors and protected STOP ALL ON / activation OFF / automation OFF posture. General Settings semantics unchanged. No real approval exists by implication of this review. |
| Quota/replay/dispatch | `read/attempts` L99–123, L139–144; `authorizeReservedAttempt` L76–97 | Reservation/audit survives normal failure; single-use dispatch and one HTTP attempt. **F1/F2 below remain blockers under selective option loss.** |
| Transport and response | `PrintifyCatalogClient::scopedRead` L64–87 | Concrete repository admission before transport and after HTTP 200; GET, 10s, zero redirects, 1 MiB, depth 32, protected-material rejection. **F3:** ordinary draft read lacks endpoint-specific envelope validation. |
| Non-persisting preview | `PrintifyCatalogPreview::inspectScoped` L27–68 | Validates bounded unique variant IDs/labels; geometry/shipping/rights UNKNOWN; NOT_CERTIFIED. Security reservation/dispatch records persist; preview/artwork/catalog response does not. This PHP helper has no registered preview route. |
| Shared REST registration | #1171/#1172 `includes/Core/Plugin.php` + `REST/PrintifyCatalogReadController.php` | Owner/reader capabilities, REST nonce, body/query bounds and request key; GET read persists draft evidence. #1189 alone has neither the registration change nor controller. Composed REST coverage must retain #1172 tests and use #1189's paired repository/client/policy. |
| Legacy callback policy | #1189 `PrintifyCatalogReadPolicy::authorize/authorizeReserved` | Callback-only preview deliberately always denies. Reserved path delegates to existing repository; it is not a second grant authority. Legacy unscoped client/sync entry points remain outside the new grant-bound path; never label them automatically migrated. |
| CI / fixtures | Workflow union and #1189 `bin/install-wp-tests.sh` | Retain BOTH installed dependency advisories and all three catalog smoke scripts; retain matching released WordPress fixture and required checks. Do not overwrite the workflow wholesale with #1189's version. |

## Reproduced blockers

Three review-only probes ran against exact #1189 with real disposable local WordPress/MariaDB, synthetic identities/approvals, a stubbed catalog transport and a global deny-actual-HTTP hook. STOP ALL/external lock stayed ON throughout the probes. **3 tests, 44 assertions, 3 failed expected-denial assertions.** These are newly exposed failures, not a revision of the historical audit result. Source and reproduction steps: [probe appendix](printify-integration-review-probes-2026-10-17.md).

| ID / priority | Reproduction and observed result | Required fix and acceptance |
| --- | --- | --- |
| F1 / HIGH | Approve synthetic grant + separate exception; revoke grant; verify denial; delete ONLY `df_catalog_read_revoked_<grant>` while retaining its audit; a fresh preview returns an array and invokes the stub once. `grant()` L130 checks the option but not a retained `catalog_read_revoked` anchor when the option is absent. Inherited from #1171/#1172. | Implementation owner must fail closed on missing/corrupt/mismatched grant-revocation evidence versus retained anchors. Regression must stay denied, with zero new dispatch, even under a still-valid exception. No real owner action can substitute for this fix. |
| F2 / HIGH | Complete one synthetic preview; retain reservation and dispatch audit, delete ONLY `df_catalog_read_dispatch_<grant>_<request>`; call `scopedRead` with the SAME reserved authority and synthetic configured token; returns an array, total stub calls = 2. `authorizeReservedAttempt` L93–96 only checks mutable option absence before new dispatch. | Implementation owner must reconcile durable dispatch anchors before a new dispatch, treating option loss as uncertain/consumed. Regression must deny replay with total calls still 1. Direct reserved-client boundary is affected; normal `read()` reuse of the same request key is separately blocked by its reservation. |
| F3 / MEDIUM | Under a valid synthetic grant/exception, return HTTP 200 with `{"unrelated":"synthetic unexpected field"}` for `kind=variants`. Ordinary `read()` returns an array and `evidence()` confirms persisted DRAFT_UNVERIFIED data. `PrintifyCatalogReadContract::select` returns data unchanged for non-single-variant operations. | Validate response envelopes/IDs per supported endpoint before returning/persisting data; preserve missing supplier facts as UNKNOWN rather than inventing them. Test malformed blueprint/providers/variants and no evidence persistence. `inspectScoped()` already rejects the malformed variants envelope; the registered ordinary read path does not. |

F1/F2 require selective local option loss/restore/tampering with audit retained; they are not demonstrated anonymous REST exploits or live supplier writes. SQL audit anchors are not external WORM storage and cannot prove resistance to coordinated whole-database privileged rollback. Existing passing tests cover many normal revocations, deleted exception revocation and deleted reservations, but not these exact missing grant/dispatch cases.

## Dependency / conflict evidence

`git merge-base --is-ancestor 9ac1c81 bb6edf3` succeeds; `bb6edf3` is NOT an ancestor of `8ea1f07`. Main itself is not ahead of these branches. #1189 is a divergent sibling, not an already-integrated #1172 descendant.

Read-only `git merge-tree --write-tree bb6edf3 8ea1f07` exits 1 with:

- content conflict: `includes/POD/PrintifyCatalogClient.php`;
- add/add conflict: `includes/POD/PrintifyCatalogReadRepository.php`.

`PrintifyCatalogReadContract.php` is identical (blob `2f8b99b87c9493031077571c26af5dbda4a1dd32`); exception contract is identical between #1172/#1189 (blob `299ff284ffdd67e05eaedbe6dd7e02560eac5792`). These are reused definitions, not independent duplicate approvals. The workflow auto-merges in this simulation and retains the advisory step plus smoke scripts, but must still be reviewed. No working-tree merge, conflict resolution, implementation edit or merge commit was performed.

Resolve client/repository/policy as one interface: retaining #1172's two-argument repository call with #1189's client fails closed for missing reserved authority; retaining the old client with the new repository loses the new independent admission/dispatch checks. Preserve #1189's coherent three-argument dispatch and #1172's registered grant/exception routes and tests. Do not select either entire PR wholesale.

## Executable next steps — not authorization to execute merges

1. Assign F1–F3 to the Printify implementation owner (Codex B). Add the three durable regressions and fix root causes in owned files. No owner evidence or live provider call is needed for these local fixes.
2. Do not merge the current heads piecemeal while F1–F3 remain. Dependency order is review/composition order, not approval to ship an intermediate tree. Coordinator creates a separate integration candidate/PR after the fixes. Review sequence: #1171 foundation -> #1172 exception -> fixed #1189. #1172 contains #1171; do not replay its foundation as another approval system. Current Codex C branch contains documentation only and must not become that code candidate.
3. Reconcile the two conflict files as a pair, retain the policy wrapper, shared Plugin/controller registration, both source PR test sets, the #1189 smoke tests and the CI union. Decide whether preview remains a PHP-only offline helper or gets a separately reviewed nonce/account-bound non-persisting endpoint; do not silently replace the existing persisted evidence GET.
4. On the actual integrated candidate, run (only against disposable local WordPress fixtures, actual HTTP denied):

```sh
composer test
php tests/printify-catalog-attempt-authorization-smoke.php
php tests/printify-catalog-preview-smoke.php
php tests/printify-catalog-read-policy-smoke.php
vendor/bin/phpunit --configuration phpunit.wordpress.xml.dist --filter 'PrintifyCatalog(ReadTest|ScopedSmokeTest)'
composer audit --no-interaction
```

Retain the new F1–F3 tests in that candidate. Add composed REST tests for grant creation/nonce/account, ordinary denial under locks, valid separate exception, one dispatch, quota/replay, expiry, revoked owner/account/connector, cross-shop/config drift, protected/invalid responses, non-persisting preview and unchanged mutation denial. Require full exact-head Engineering & Safety Audit after all code/wiring/CI resolutions; the three existing PASS results do not certify that new head. Check current heads and branch protections immediately before any separately authorized merge.

## Owner/external queue

Smallest immediate action: assign the three fixes and coordinator-owned integration/registration review; **no live activation approval or credentials are needed now**. This documentation PR grants neither merge nor provider-call authority.

After code integration and separately authorized release, future live-read setup still needs canonical main-site pin, verified WordPress account/business/shop/integration/credential metadata, authentic provider/shop evidence, exact blueprint/provider/variant requests, explicit expiring grant, separately expiring catalog-GET exception and separate network/provider-call authorization. Do not infer API ownership from dashboard store ID 28930593. Geometry, regional shipping, mockup rights and supplier certification remain external/UNKNOWN. Owner approval cannot repair F1–F3 or replace composed testing.

STOP ALL ON; external lock ON; automation OFF. No real credentials inspected/provisioned, live Printify/TinyFish, Finance change, production/staging write, order, publishing, merge, deployment or activation. No acceptance ledger or source requirement classification is promoted.
