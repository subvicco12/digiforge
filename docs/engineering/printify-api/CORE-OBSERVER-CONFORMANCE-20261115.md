# Core / checkpoint-observer conformance — offline only

Reference core78e7f973455a3c89319be06f0907caf2103658a7 and companion4da802a1568b13bcf1bbca28eba009b8b6148ef1 remain unchanged. This extends API/Cloudflare PR1260 baselinee95cfff9. STOP ALL/external lock ON, automation OFF, RELEASE HOLD. No provider/account request, real credential, installation, provisioning, merge or policy activation.

## Actual contract findings

NativeCorePort advertises only PANEL/GET_READ. Its canonical v1 mutation grammar supports AUTHORIZE,REGISTER,CLAIM,RECOVER,CANCEL,RECEIPT_READ,RECONCILE,OUTCOME. RESERVE is not an action: CLAIM is the canonical reservation action. Valid requests still return journal_and_independent_checkpoint_required after real module/session authorization. Publication remains denied under STOP/lock; order submission is neither a supported GET intent nor an authorized workflow action.

NativeReadOnlyInspection provides protected READINESS,HELD_OPERATION,ORDER_OBSERVATIONS without operational epochs or independently verified checkpoint authority. The observer's cryptographically_verified=true instead describes synthetic issuer/witness signature verification at signing. These are different assertions. No observation, content hash, manifest or local held-journal revision can fill the native mutation response's independently qualified epoch/journal requirements.

## New non-authorizing comparator

NativeObservationConformance::compare accepts a native manifest, minimized protected inspection, native binding, minimized observer result, supplied observer binding and explicit diagnostic time/generation reference. It uses the real core NativePortContract validator/hash implementation. It compares exact pinned manifest/version/capabilities,15-field scope, native inspection hash/shape, historical proof shape, signed minimum expiry, supplied generation and HELD/FENCED/UNKNOWN revision semantics. Errors produce bounded codes without copying input payloads or private values.

READ_ONLY_CONFORMANT means compatible supplied data only. The comparator does not authenticate the minimized records' origin, signatures, context linkage or current native lease. A caller can align fields or fabricate a schema-valid response; this never yields authority. Its output explicitly states signature_verification=NOT_PERFORMED_BY_COMPARATOR, context_link_verified=false and native_runtime_verified=false. Every result keeps operational epochs null, independent_checkpoint_verified=false, all mutation/dispatch/network flags false, retry/rearm false and RELEASE HOLD true. Request actual protected native inspection for each evaluation; do not use a cached diagnostic record as authority.

There is no core hook, endpoint, callback, sender, vault, transaction store, dynamic account mapping or native port implementation in this comparator. It is in contracts, excluded from WordPress plugin exports. The core remains the sole authority. No actual core runtime defect requiring a guard change was demonstrated.

## Evidence layers and native matrix

| Path | Evidence | Disposition |
|---|---|---|
| Existing observer/final-sender boundary | All305 inherited synthetic cases retained | Synthetic; no hosted/operational authority |
| Portable PHP cross-language comparison | Exact frozen core validator, held-state class and schema with source/Git-blob/SHA-256 manifest | Real core code, synthetic record samples; not a native WordPress runtime pass |
| Protected READINESS | Actual extracted core78/companion075, actual owner/session/scope/CSRF | Native positive read, locks remain ON, epochs null |
| Actual fixture scope versus observer | Core provider_shop12; observer28930593; integration revisions also independently scoped | MISMATCH, no fabricated mapping or joined provider certification |
| Caller-aligned context fields | Actual native read plus caller-aligned minimized inputs | Diagnostic compatibility only; context linkage and runtime verification remain false |
| REGISTER and CLAIM | Actual canonical exchange, authenticated module, synthetic evidence in request data | Denied: journal_and_independent_checkpoint_required |
| RESERVE | Actual native schema/exchange | Denied: mutation_contract; no invented alias |
| Publication/order submission/dispatch | Actual publication/workflow/read-intent boundaries and comparator output | Denied/unreachable; zero transport invocations |
| Stale/revoked generation references | Actual observer output and diagnostic reference mismatch | Non-authorizing diagnostic rejection; not a native epoch producer |
| Expired read scope, wrong shop, revoked session | Actual NativeReadOnlyInspection/NativeAuthorityAdapter | Native denial |
| Missing held journal | Actual native protected query | Native denial; comparator cannot substitute observation data |
| UNKNOWN held state | Actual NativeHeldJournal and protected inspection | Read-only UNKNOWN, no FENCE/retry/rearm transition |
| All-root rollback | Actual core held-journal counterexample plus existing final-sender counterexample | Recovery guarantee remains BLOCKED |

The real-core profile runs the8 new acceptance tests plus unchanged NativeReadOnlyInspectionTest and NativeHeldJournalWordPressTest:35 tests /418 assertions passed locally, with no skips. Every new native case asserts zero provider requests; diagnostic/native read calls additionally count zero SQL writes. Fixture creation and cleanup are isolated synthetic test operations, not part of the read-only comparator. No missing native authority was replaced with a mock. Source-schema tests, local native WordPress and hosted source CI remain separate evidence categories.

Two comparator defects were reproduced and corrected: object-member ordering no longer invalidates canonical readiness semantics, and UNKNOWN with a stale journal revision is rejected. Requested guarded SESSION and standalone CHECKPOINT_EXECUTE denials are now explicit regressions. Existing frozen runtime files, Stage A build identity, inventory workflow and previous packages are preserved.

Fresh read-only review also reproduced an Important in-process PHP purity defect: json_encode could invoke a nested JsonSerializable callback before the malformed record was rejected. The comparator now applies the real core's recursive data-only json_object validation to every record before any serialization/hash. Two callback-zero tests were observed failing with count1, then corrected to count0. Every output remains non-authorizing, and no core runtime source change or safety exception is involved. Reviewer verified45 focused final-sender tests and the frozen reference bytes; full native/portable/source-CI results are separate execution evidence.

## Reproduction

Portable suite: Node24 with native SQLite, PHP8.3+ and local child-process permission; run node --test --test-isolation=none contracts/cloudflare-dispatcher/test/*.test.mjs. The fixed PHP probe loads only byte-verified frozen real-core pure classes, never WordPress stubs or arbitrary paths. It does not execute native runtime ports.

For actual WordPress, verify the qualified core78 artifact SHA-256 6c07cdd99397ee3423420e3db97519e027d1eff87dd6b4c944fbec65a83c2270, its build-owned native manifest, and the companion075 artifact SHA-2569091ec0a029ebe228cf0cc47c631a787adeb7a28d3333a13579a0157be94f361. Use an isolated network-disabled WordPress fixture, actual companion autoloading and synthetic owner/scope data. Generate only minimized synthetic data with test/export-core-observations.mjs to the private test path /private/core-conformance-observations.json. Load test/native-core-wordpress.php together with the unchanged core inspection/held-journal tests; require the API contracts directory separately. Parent/child tests must share the same private WordPress namespace/default config. No source files from either qualified PR are modified. Never run this profile against a site database.

## Remaining gates and next step

The physical account/credential/Shop2 association, independently governed epoch/witness root, exclusive usable credential custody, old-token invalidation, hostile/all-root rollback resistance, actual hosted final boundary and Free/USD0 entitlement remain unqualified. Native mutation leases and authoritative identity/product/approval/journal joins remain unavailable, not mocked as successful. Historical observations remain non-authorizing; counterexample assertions do not qualify recovery.

Next: the separate core session can consume this diagnostic contract and conformance fixtures without promoting observation epochs or enabling REGISTER/CLAIM. Any future operational native producer requires actual independently qualified epoch/provenance/currentness and mandatory final-sender/custody admission first. A hosted synthetic pilot needs a separately reviewed private factory/invocation package, entitlement/quota/CPU evidence, bounded USD0 budget, explicit deployment/execution/cleanup approval and reviewed administrative/restore boundaries. No such approval is implied here.
