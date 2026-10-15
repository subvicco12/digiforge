# Phase-1 two-shop local acceptance

Authority: [owner scope decision on PR #1183](https://github.com/subvicco12/digiforge/pull/1183#issuecomment-6197374752), 2026-10-15. Baseline `bd93a06636a75401ca64b8251cfaf7abc4eaf709`; main `844107ab9baf7e1303cd47708e9eab3bc700b348`. Intended initial shops: `digital`, `personalized_pod`. `standard_pod` and `jewelry` onboarding/activation and EU/Germany expansion are deferred. Four canonical shops and their stronger authorization boundaries are unchanged. This is an acceptance projection, not a new assignment, activation setting or production policy.

## Bounded evidence projection

| Existing requirement / boundary | Local evidence | Qualification |
| --- | --- | --- |
| BP-portal-3-selected-shop-view-isolation | `TwoShopPortalAcceptanceTest::testTwoShopOperatorCanInspectScopedPersonalizedWorkflowWithoutGlobalCatalogQueries` | Two-shop-assigned actor can inspect personalized packages/artwork and digital page. Own package visible, foreign deferred-shop package excluded. Global Master 500 queries/panels withheld. |
| Four canonical shops / deferred access | `TwoShopPortalAcceptanceTest::testDeferredShopsAndGlobalViewsRemainDeniedWithoutChangingFourShopArchitecture` | Deferred shops and global scope denied; selector contains only the two explicit assignments. No dormant-shop grants, identities or activation created. |
| Current assignment revocation | `TwoShopPortalAcceptanceTest::testRevocationRemovesPreviouslyAvailableWorkflowWithoutGrantingDeferredAccess` | Removing synthetic authority immediately denies every formerly available shop workflow. |
| Ownership drift, expiry, revocation | Existing `ComposedOwnershipLifecycleAcceptanceTest` (three tests), `ComposedPersonalizedOrderAcceptanceTest::testProviderIdentityDriftInvalidatesQueueReadinessWithoutRewritingReviewHistory` | Existing local synthetic ownership/plan/REST/queue contracts reused. Immutable old evidence retained, current qualification invalidated. No authentic ownership claimed. |
| BP-portal-11-prepared-orders | Existing `ComposedPersonalizedOrderAcceptanceTest::testPreparedPersonalizationEntersOnlySelectedQueueAndActualPortalReviewPersistsOnce`, `testForeignAndRevokedOperatorsCannotAcceptPreparedPackage` | Existing real local renderer → stored artifacts → package → selected queue → human review/replay coverage reused. |
| Keyboard / responsive projection | `tests/browser/two-shop-portal.cjs` | Native authenticated WordPress output at 390/768/1280: 39 checks, zero network requests. Synthetic fixture rendering, not browser-authenticated HTTP/deployed-site E2E. |
| BP-printify-shop-scope | Existing local isolation tests only | EXTERNAL/UNVERIFIED authentic account, environment, shop and integration identity. Owner report/screenshot naming DigicraftifyShop is insufficient. |
| BP-portal-7-mockups | Existing fail-closed synthetic asset/geometry tests | Real licensed assets, display/derivative rights, exact variant/panel/UV/seam geometry and supplier certification remain external. |
| BP-portal-11-production-authorization; BP-live-portal-normal-work | Existing local envelope/STOP ALL denial and fixture-driven portal tests | Authentic production authority and installed live-site acceptance remain unverified. No activation/deployment authorized. |

## Confirmed defect and fix

The personalized portal previously required all four shop assignments because it combined scoped workflows with global catalog evidence. A two-shop operator could not reach its own existing workflow. The fix admits the selected-shop page and returns before reading/rendering global catalog evidence unless the actor holds all four assignments. Generic global tables retain their existing separate guard; scoped tables and render preparation retain existing server-side shop authorization. No global/dashboard access is granted. Existing all-four-shop tests are retained, not replaced.

Focused cohort: 23 native tests / 652 assertions PASS. Full composer checks: 1,337 unit tests / 8,067 assertions, one unchanged optional-model skip; legacy, baseline static and standards PASS. Matrix regression: 16 PASS. New tests first reproduced the access denial before the production fix. Complete exact-head hosted audit is required on the published candidate and recorded in its PR; no previous-head PASS is inherited.

## Readiness and owner actions

The scoped local behaviors above are verified; this is not a claim that every portal surface supports partial grants. Global dashboard, configuration, reconciliation and supplier administration continue to require their existing stronger all-shop authorization where applicable. Do not assign dormant shops to bypass those guards. A new requirement to expose additional partially assigned administrative surfaces needs its own scoped implementation/acceptance, not a certification shortcut.

The persistent full Blueprint matrix remains **246/268 verified local criteria (91.79%)**, **4 UNVERIFIED, 11 OWNER APPROVAL, 7 EXTERNAL**. No denominator or historical requirement changed because shops were deferred. Two-shop local evidence does not establish full four-shop, installed-site, commercial or final-product acceptance.

Owner must supply authentic Etsy/Printify account/environment/shop/integration identity; licensed mockups with customer-display and derivative rights; exact supplier variant/panel geometry and certification; and separate production/release authority plus installed-site evidence when authorized. Deferred-shop onboarding requires a later explicit owner decision. Existing Administration READ/protection export, CI artifact retrieval and independently identified published-environment revision evidence remain release blockers. No repeated access attempts were made. Finance #1147 remains excluded and untouched.

STOP ALL ON; external safety lock ON; automation OFF. Tests use only local disposable databases and synthetic evidence. No production/staging writes, live provider calls, merge, deployment, activation, spending or fabricated evidence.
