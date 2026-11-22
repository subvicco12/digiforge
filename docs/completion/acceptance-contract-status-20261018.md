# Read-only reviewed acceptance-contract consumer

Base: draft #1202, `c71766f0ebd75a9ec1edb71a842931bbd2fcc499`, audit #4734 PASS; main `844107ab9baf7e1303cd47708e9eab3bc700b348`. Both heads were verified unchanged. Existing source inventory, 268-criterion acceptance ledger and owner-direction definitions are reused; original documents are not regenerated. No active parallel writer was reported. This batch owns only the metadata projection, its GET controller/guard/scope registration, portal panel/script and focused tests. No Finance, migration, shared CI or existing PR changes.

## Read surface and safety

`GET /digiforge/v1/reviewed-contracts/{digital|personalized_pod}/acceptance?criterion=…&scope=…` requires an authenticated explicitly assigned shop reader (`manage_digiforge`). URL scope controls the global REST guard and target resolver; foreign/deferred shops, extra parameters, bodies and writes are rejected. Plain-permalink routing metadata must exactly match the registered route. Existing policy/ownership write routes are unchanged.

`ReviewedAcceptanceContract::status` reuses the existing bounded signed policy stream and append-only audit validation. It returns only typed criterion/scope, review state/revision, effective/expiry timestamps, revocation and validated local references/hashes. It never returns the full definition, author identities or secrets. Missing streams report MISSING/UNKNOWN; malformed signed definitions report INVALID_EVIDENCE without references; audit/database failures remain errors. Approved reviews are distinguished as current, future, expired or stale after reviewer/author authority loss; a changed second snapshot cannot become current.

References are declarations, not fetched or verified records. Every response keeps values UNKNOWN, evidence UNVERIFIED and supplier/publishing/AI/external execution authority false. No new approval actions, credential access, provider calls, file dereferencing or certification pathways exist. Existing independent global locks and execution checks remain intact. Deferred shops remain denied; Digital acquires no POD dependency.

The existing selected-shop contract panel gains a separate read-only status view. The original editor order and controls remain unchanged. Browser requests are same-origin GET with existing WordPress authentication/nonce handling. Text-only DOM output rejects HTML execution; failed reads clear prior results, and changed inputs invalidate in-flight responses. No acceptance write/review buttons are exposed.

## Reproducible focused evidence

- Native WordPress: `AcceptanceContractStatusTest|ReviewedAcceptanceContractTest|ReviewedContracts`: **35 tests / 277 assertions PASS**, using isolated local DB, synthetic signed review fixtures and blocked actual HTTP. New coverage includes scoped read-only operators, anonymous/foreign/deferred denial, read immutability, method/body/query rejection, missing/pending/revoked/future/expired/stale states, malformed references, read failure and native panel markup.
- Related unit selection `Reviewed|ShopAccess|Portal`: **107 / 678 PASS**. Existing legacy suites PASS.
- Browser: `DF_BROWSER_NO_SANDBOX=1 node tests/browser/acceptance-contract-status.cjs FIXTURE_DIR`: **42 checks PASS**, widths 390/768/1280, plain/pretty permalinks, keyboard navigation, text escaping, failed reads and stale response rejection. Fixture HTML comes from the native panel test with `DF_ACCEPTANCE_STATUS_FIXTURE_DIR`. Browser HTTP responses are synthetic and do not establish installed-site acceptance.
- Existing portal browser regression: **12 checks PASS**, zero unexpected requests; regenerated native portal fixture **2 tests / 23 assertions PASS**.
- Standard analysis/standards and additional WordPress-aware level-5 analysis of all changed PHP classes PASS; `git diff --check` PASS. The additional analyzer declares the existing dynamically computed plugin URL for symbol resolution; production bootstrap is unchanged.
- Final exact-head hosted audit and independent remote SHA are recorded in the draft PR, avoiding a documentation-only audited-head change.

## Acceptance and remaining inputs

The authoritative ledger stays **246/268 implemented candidates; 22 UNVERIFIED**. No criterion is promoted by displaying declarations. The ten owner-directed criterion IDs remain as listed in #1202's completion record. Referenced source records, concrete approved metrics/rules, human review, authentic account/supplier evidence, geometry/shipping/rights, production authority and installed-site verification remain outstanding as applicable. The coordinator execution ledger is unchanged.

Next independent engineering requires a demonstrated consumer defect or approved concrete inputs; this UI does not authorize speculative metric/AI execution. Owner next step: supply the criterion/scope's concrete evidence packet for the existing independent review workflow. No production activation is required or authorized for this draft.

STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD. Production impact: NONE. No live calls, real credentials, production/staging writes, orders, publishing, merges or deployments.
