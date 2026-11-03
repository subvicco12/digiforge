# Read-only acceptance evidence resolver integration

Baseline: draft #1204, exact `4792eac61aad1f25b383a38233508b538c003acd`, audit #4736 PASS. Head verified unchanged; main fetched as `844107ab9baf7e1303cd47708e9eab3bc700b348`. The complete #1194–#1204 dependency ancestry is preserved. Existing source inventory, owner contracts and 268-criterion ledger are reused. Available operating-manual/AGENTS snapshots remain proposed unless merged; no new authority is inferred from them.

## Integration and compatibility

The existing authenticated GET route `/digiforge/v1/reviewed-contracts/{shop}/acceptance` accepts the existing criterion/scope plus optional `environment=sandbox|production`. URL shop isolation, explicit assigned-reader authorization, GET-only admission, empty-body and exact-query validation remain unchanged. No new route, scope authority, write endpoint or guard exception is introduced.

With an explicit environment, `ReviewedContractsController::readAcceptance` calls #1204's unchanged `AcceptanceEvidenceResolver::resolve`. Its result is nested under **evidence_resolution**, separately from parent review state and certification flags. The controller rechecks the parent status projection after resolution and returns 409 if it changed, rather than composing an old APPROVED view with a newer review snapshot. Authorization/read errors remain errors. A regression revokes the real synthetic parent during the source read and proves the mixed snapshot is rejected.

Old callers omitting environment remain supported and receive the original review status plus an additive UNKNOWN resolution (`environment=UNKNOWN`, zero matching records, empty references). No environment is guessed from a shop, provider label, WordPress host, reference or default. Invalid/extra environment fields fail typed validation.

The panel adds an explicitly selected evidence environment, labelled **not activation**, with no default selection. Selecting production means only the existing policy evidence context; it grants no production access. Status display separates exact local record matching from approval/certification/authority. All evidence remains UNVERIFIED and values UNKNOWN; purpose verification, supplier approval, publishing, AI and external execution flags remain false.

The browser includes the selected environment in same-origin authenticated GET requests, uses no-store reads, validates response criterion/scope and resolution shop/environment context, bounds/validates the resolution collection and projects only known reference fields. It uses textContent, not HTML. Unrecognized authority fields from a synthetic response cannot become portal authority. Changing scope/environment invalidates pending responses; failed or mismatched responses clear old results. Missing resolution from an older server displays UNKNOWN. Existing policy/ownership editors and their order are untouched.

Only the version-pinned signed-policy reference codec implemented by #1204 is consumed. Opaque local references, artifacts, supplier records, geometry, shipping, rights, metrics, thresholds, spelling exceptions and production authority are not invented or certified. Source matching remains a bounded point-in-time identity observation, not a lease or permission for downstream actions.

## Changed ownership and schema

- `includes/REST/ReviewedContractsController.php`: existing GET projection composition and snapshot consistency.
- `includes/Portal/ReviewedContractsPanel.php`: explicit read-only evidence environment and explanatory text.
- `assets/acceptance-contract-status.js`: separate bounded resolution display and context/error handling.
- `tests/wordpress/AcceptanceResolverIntegrationTest.php`: native REST integration cases.
- `tests/browser/acceptance-contract-status.cjs`: extends existing synthetic browser cases without another harness.
- This completion record.

No edits to Codex B's resolver or tests, underlying signed contract/review store, REST guards, migrations, shared CI, Finance, coordinator execution ledger or existing PRs. No schema change or startup DDL.

## Verification

- Test-first native integration failed because the GET projection rejected environment and lacked evidence_resolution. Final composed selection (`AcceptanceResolverIntegrationTest|AcceptanceEvidenceResolverTest|AcceptanceContractStatusTest|ReviewedAcceptanceContractTest|ReviewedContracts`) **65 tests / 501 assertions PASS**. Twelve new integration cases cover real matching, legacy reads, foreign/deferred/anonymous denial, malformed environment/scope claims, environment/hash/version mismatch, unsupported references, source revocation/expiry, duplicates/conflicting hashes and parent revocation during composition. Existing source-store malformed/history/review tests are reused.
- Native panel fixtures regenerated from real PHP: **3 / 27 PASS**.
- Extended browser: **84 checks PASS**, 390/768/1280 widths, plain/pretty permalinks, keyboard navigation, mandatory explicit environment, matching/unknown/legacy projection, foreign response rejection, malicious text/untrusted authority fields, read failure and stale-request invalidation. Existing contract-editor browser **12 checks PASS**, zero unexpected requests. Browser responses are synthetic, not installed-site acceptance.
- Related units **107 / 678 PASS**; legacy suites PASS; standard analysis/standards and additional WordPress-aware level-5 analysis PASS. `git diff --check` PASS.
- Mandatory hosted exact-head audit, remote SHA and package evidence are recorded in the draft PR without changing an audited head solely to log its audit.

## Ledger and blockers

The authoritative matrix remains **246/268 implemented candidates; 22 UNVERIFIED**. This integrates an existing local software boundary; it does not supply authentic owner/source/supplier/installed-site evidence and promotes no acceptance criterion. #1203/#1204 remain required dependencies. The smallest next action is an actual criterion/scope packet with concrete reviewed definitions and supported exact local record references. Further artifact/provider/recovery adapters require explicit domain identity/environment/review contracts, not guessed local-URI mappings.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No Finance changes, real credentials, live providers, production/staging writes, activation, orders, publishing, merges or deployments. Production impact: NONE.
