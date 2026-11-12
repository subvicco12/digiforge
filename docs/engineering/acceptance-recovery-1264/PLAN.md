# PR1264 comprehensive acceptance recovery

Starting source: dccaf3c65ce402935d42bf04392db2deb422a2f0. Continue the same branch/PR; no hosted mutations or live provider execution.

1. Reproduce native-owner to legacy-fixture lifecycle contamination; reconcile synthetic event/audit cleanup by provenance, preserving business/denial audit records. Run contamination reproducer and largest affected groups.
2. Trace catalog smoke failure against .17/.25 source contracts; preserve supported catalog data/credential contracts through exact signed scoped admission without introducing any independent execution route. Add offline regression coverage before a source fix.
3. Re-run remaining named failures in fresh local state. Preserve assertions; correct real regressions and migrate obsolete fixture setup to current native owner entry points where warranted, with explicit negative legacy-handler coverage.
4. Run all unit, WordPress, offline, security, static and standards gates. Classify only the exact21 reviewed Git-object scanner findings using independently verified object identity; unknown/new findings must still fail.
5. Independent review, exact-head CI on PR1264, acceptance receipt and remaining FL03/FL04 priorities. No merge, installation, credential changes or activation.

## Decisions and evidence

- 198 of the original 199 errors report `digiforge_shop_access_lifecycle_revision`; the remaining error is registered MVP admission (`Schema25MvpAccessTest`). Native-owner audit proofs survived legacy option cleanup. Cleanup now recognizes only approval IDs issued by the synthetic fixture; business/denial audit records are retained and asserted.
- The early compatibility-factory proposal was rejected in review and removed: an arbitrary caller-supplied transport is not concrete signed admission. Runtime raw catalog, connection and reconciliation denial remains intact. Catalog positive cases now use real signed grants/reservations; the no-retry policy consumes a 429 attempt and denies replay.
- Legacy per-shop ENROLL is intentionally closed in recovered production. Positive lifecycle tests now exercise verified email, host approval and four native global-owner assignments, including all capabilities, replay, revocation, zero HTTP and hold posture. Scoped-operator positive/negative checks remain.
- Durable quota admission cannot execute inside WordPress's test-owned transaction. Catalog fixtures snapshot affected local tables, enter a durable session and restore it afterwards. Runtime transaction guards are unchanged.
- Scanner classification is structural and historical: exact manifest location, canonical single member, actual commit/blob kind, and exact path/blob at the referenced start commit. Duplicate JSON members, wrong locations/objects and new credentials fail closed. All14 security tests pass.
- Current completed checks: PHP2193/10770 (one pre-existing skip), offline198 passed, static analysis and coding standards passed. Comprehensive and updated focused runs remain in progress; no cumulative acceptance claim yet.

- Full c1d2d20c diagnostic:1761 tests,61598 assertions,153 lifecycle errors,1 secondary mockup failure,4 existing skips. Earliest late poison was custom research approval provenance not covered by the initial host-approval registry. Shared signer provenance regression reproduced that exact error and then passed; research reset now uses common narrow cleanup. A341-test late window subsequently had zero errors and all rendering/supplier tests passed; its one prefix-identity assertion failure was corrected without changing runtime ownership. Fresh22-case transition/inbox/isolation group passes393 assertions. Unknown proof is explicitly retained and remains fail-closed. Final exact-head full acceptance still required.
