# DigiForge P5 — Production Smoke Test Plan

## Purpose
Validate the complete internal DigiForge workflow end-to-end without performing any external side effects. This phase must remain fail-closed and must not activate Etsy, Printify, Gelato, AI providers, fulfillment execution, banking, tax filing, webhooks, or scheduled external workers.

## Safety Preconditions
Before any smoke test:
- STOP ALL must remain ON.
- Activation authorization must remain OFF.
- Automation armed must remain FALSE.
- All external feature switches must remain FALSE.
- `external_actions_performed` must remain FALSE.
- No external credentials may be exercised.
- Tests must use local/internal repositories, mock records, or fixture data only.

## Primary Internal Workflow
The target internal workflow is:

1. Opportunity
2. Product Family / Product / Product Version
3. Production Plan / Asset Planning
4. Digital Product or POD Product Record
5. Listing Record
6. Etsy Draft Package Generation
7. Mock Order
8. Fulfillment Plan
9. Finance Ledger Entry
10. Analytics / Reporting Visibility
11. Audit Trail Verification
12. Readiness and Safety Re-check

## Smoke Test Matrix

| Step | Area | Minimum validation | External action allowed? |
| --- | --- | --- | --- |
| 1 | Research / Opportunity | Create or load a local test opportunity and verify valid initial state | No |
| 2 | Product Factory | Create linked product family/product/version fixture chain and verify state transitions | No |
| 3 | Production | Create internal production-plan fixture and verify referential links | No |
| 4 | Digital/POD | Create internal digital or POD product record without provider execution | No |
| 5 | Listings | Create internal listing data and validate required fields | No |
| 6 | Etsy Draft Package | Generate local draft package only; no Etsy API call | No |
| 7 | Orders | Create mock/local order fixture | No |
| 8 | Fulfillment | Generate internal fulfillment plan only | No |
| 9 | Finance | Record internal ledger/financial projection entry only | No |
| 10 | Analytics | Confirm created records appear in analytics/reporting | No |
| 11 | Audit | Confirm each material action is represented in the audit trail | No |
| 12 | Safety | Confirm STOP ALL, authorization OFF, armed FALSE, switches FALSE, external actions FALSE | No |

## Pass Criteria
P5 passes only when all of the following are true:
- Every internal workflow layer can read/write its expected local records.
- Entity relationships remain valid across the workflow.
- State transitions reject invalid or out-of-order changes.
- Idempotency protections do not produce duplicate records for repeated test requests.
- Queue operations, where used, remain queryable and do not leave expired leases.
- No external HTTP execution occurs as part of the smoke path.
- No external feature switch changes from FALSE.
- STOP ALL remains ON for the entire run.
- `external_actions_performed` remains FALSE.
- Audit evidence can trace the workflow.
- Readiness does not regress because of the smoke test itself.

## Failure Rules
If any step fails:
- Stop the smoke path at the failing layer.
- Keep STOP ALL ON.
- Do not bypass validation by changing evidence flags or state directly.
- Record the failed layer, record IDs, expected result, actual result, and audit reference.
- Repair only the failing layer and rerun from the nearest safe checkpoint.

## Live-Site Execution Gate
Repository and CI preparation may proceed before live execution. Actual production smoke execution on `converentis.com` must not start until:
1. A genuine production database backup exists and has been verified.
2. `digiforge_recovery_database_backup_available` can truthfully be set to true.
3. Recovery readiness is rerun.
4. `recovery_drill_passed=true` is confirmed.
5. The site remains externally locked.

## P5 Completion Evidence
Retain:
- test record identifiers,
- timestamps,
- audit references,
- readiness evidence hash before and after,
- recovery evidence hash,
- queue health snapshot,
- confirmation that all effective external switches remained false,
- confirmation that `external_actions_performed=false`.
