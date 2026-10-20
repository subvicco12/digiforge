# Policy acceptance adversarial hardening

Base: unchanged draft #1215, `7569d238bd18f2a4d9a9c7a9a7977d7f65e3f4bd`, exact-head audit #4748 PASS; main `844107ab9baf7e1303cd47708e9eab3bc700b348`. Owner authority is issue #1209 comment 6282712678. The existing ten-row matrix in `approved-policy-contracts-20261020.md`, original-source census, repository guidance and proposed Operating Manual are reused; no new policy, metric formula, evidence adapter or privilege is introduced.

## Reproduced defects and fixes

Five adversarial cases were run on unchanged #1215: four failed and the valid-duplicate control passed before implementation.

1. The duplicate-fact guard used PHP loose array equality. A UNIT with `on_time` or `exception` null compared equal to a conflicting known-false UNIT. Whichever row was last could change UNKNOWN into an apparent known zero. Compare canonical typed JSON instead: null and false conflict in both orders, while associative key order alone remains immaterial. Existing normalized ID, scope and timestamp constraints are unchanged.
2. Timeliness/exception dimensions could say UNKNOWN but expose zero numerators when some facts were null. Emit null for unknown numerators; retain independently known eligible-unit counts. Valid known-false facts still produce known zero counts.
3. Completely missing population coverage produced top-level LOCAL_CALCULATED despite every metric being UNKNOWN. The parent state/value are now UNKNOWN when neither orders nor fulfilled-unit populations are complete. Complete zero-event populations retain their known-zero counts; zero-denominator percentages remain UNKNOWN.

All changes are confined to the shared local metric evaluator plus tests and acceptance references. Version 1 review contracts, signed review lifecycle, transport, credentials, geometry, spelling and production gates are unchanged. No new tables, migrations, APIs, dependencies or settings.

## Challenge coverage and limitations

- `ApprovedPolicyAdversarialTest`: null/false conflicts in either row order; true duplicates with reordered keys; null versus known-zero numerators; fully missing populations.
- Reused `ApprovedAcceptancePolicyTest`: all ten approved rules and missing evidence; matched/incomplete denominators; duplicate/conflicting units, cross-shop and stale-version rows; supplier/product/shop attribution; revocation, repeat reads and rule tampering; wrong REST environment/anonymous access; seam geometry and preview declarations; AI deterministic-first fallback; 48-code-point/glyph/overflow/text/template guards; readiness without authority; feedback correction hashes, immutable ancestry and privacy.
- Reused `ReviewedAcceptanceContractTest`, `AcceptanceContractStatusTest`, `AcceptanceEvidenceResolverTest`: v1 compatibility, current/expired/revoked/future review and assignment checks, stale/replayed review denial, reference conflicts, malformed records and unsupported evidence remaining UNKNOWN.
- Reused `FC01MultiProductDraftJourneyTest`: actual synthetic FC01 render, optional established omission, independent review, separate non-executable product drafts, revoked ownership, locks and zero provider calls.
- Browser synthetic tests: UNKNOWN and foreign-scope projection rejection, certification flag suppression, safe text rendering, errors/stale requests, plain/pretty routes and 390/768/1280 px. Browser mocks do not establish installed-site authority or authentic provider evidence.

Focused native validation: **78 tests / 1,033 assertions PASS**. Browser, static, source-reference and final exact-head audit evidence is recorded in the PR/issue after completion. No mandatory check or existing skip configuration is weakened.

All ten policy criteria remain **UNVERIFIED authentic acceptance**. Ledger counts remain **246/268 implemented candidates, 22 UNVERIFIED**; this batch repairs local behavior, not authentic acceptance. Source observation provenance, actual population completeness and linguistic/supplier review remain declarations until supported by authentic records. The existing evaluator still cannot certify supplier READY, fonts/rights, native output or production authority.

Minimum owner evidence remains the existing scoped supplier/product/variant/geometry/rights bundle, independently reviewed FC01 text/font/art, eligible attributable cohort records and completeness, feedback provenance, exact AI benefit and supplier eligibility definitions, plus separately authorized installed-site acceptance. Do not request reapproval of the already approved policy principles.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. Finance excluded. No real credentials, live providers, production/staging writes, activation, orders, publishing, merges or deployments. NO RELEASE.
