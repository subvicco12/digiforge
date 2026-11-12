# PR1264 acceptance recovery

PR: https://github.com/subvicco12/digiforge/pull/1264
Starting source: `dccaf3c65ce402935d42bf04392db2deb422a2f0`.
Reviewed recovery checkpoint: `8c176e8c8007a39720cbda07e9623dcad5e2f7f6`.
The follow-up restores all ownership-fixture tables and inherits the exact test configuration in concurrent ledger workers. Runtime source is unchanged from the reviewed recovery checkpoint.

## Original root-cause reconciliation

The reported run contained 1762 tests, 199 errors and 29 failures. Its two proof-review failures predate the already-corrected starting head; they are not new regressions in this batch.

| Group | Reported count | Cause and correction |
| --- | ---: | --- |
| Authority fixtures | 198 errors | Native global-owner and custom research approval proofs survived legacy option cleanup. Track every approval issued by the shared synthetic signer, plus host-fixture approvals and exact historical fixture hashes; retain business/denial audits and unowned lifecycle corruption. |
| Registered MVP admission | 1 error, 1 failure | Recovered guard omitted the two exact Personalized POD admin actions. Restore their existing signed shop admission; handler nonce/capability checks remain. |
| REST contracts | 8 failures | Overlay preempted authentication, malformed-scope and finance denial response contracts. Restore401/400/error identity while retaining exact route, native capability, scope and denial-audit checks. |
| Artwork review fixtures | 3 failures | Two earlier proof fixtures and one full mug/poster workflow reviewed a specification hash instead of the imported artwork hash. Review the exact imported asset; do not relax human review. |
| Schema markers | 2 failures | Component-only v22/v23 migrations do not own the legacy core marker. Assert legacy22 and canonical23 separately; full Migrator still requires23. |
| Denied portal reads | 2 failures | Exact native access-profile proof queries were misclassified as business reads. Allow only that prepared authentication-proof query; retain no-business-read assertions. |
| Expiring invitation | 1 failure | WordPress numeric object-ID inference changed a synthetic SHA identifier in the fixture update. Bind the audit identifier explicitly as a string. |
| Catalog contract | 1 failure | Legacy raw positive tests conflicted with retained concrete grant/reservation admission. Move positive response, provenance, secret fallback and shipping checks through signed grants; preserve zero-send legacy denials and no automatic replay. |
| Safety/error ordering | 2 failures | Legacy connection scope and STOP ALL denials were preempted by generic governed-route rejection. Restore precise preflight denial without enabling old execution paths. |
| Owner enrollment | 8 failures | Positive per-shop ENROLL assertions described a deliberately retired handler. Exercise verified-email/host-approved four-shop global enrollment, all capabilities, exact scoped delegation, replay, revocation, audit rollback and holds; explicitly deny legacy enrollment. |
| Mockup fixture | 1 failure | Appeared after failed shared fixture setup. Fresh sequential supplier-switch acceptance passes, preserving reviewed geometry, placement and immutable history. No mockup runtime rule was relaxed. |

Further errors previously hidden behind the authority cascade were incompatible legacy consumer assertions and durable-session fixture setup. A late full-suite transition exposed the additional custom research signer: the shared signer registry now covers that provenance, and the research reset delegates common narrow cleanup. Foreign listing assertions compare whole cells so Listing #1 cannot be confused with Listing #10; scope and pending-count assertions are unchanged. Positive provider-response tests now use real independently reviewed plans; catalog tests enter and restore a genuine durable local session. No runtime transaction protection is bypassed. Concurrent recovery workers now inherit the parent WordPress config and PHP config, so both contend on the same local database. Each PHPUnit process uses an isolated upload root: immutable custody correctly rejects files intentionally tampered by an earlier run under recycled test IDs. Runtime custody rules are unchanged. Catalog boundary coverage now checks both locked-default denial and governed denial under query-only synthetic enabled observations, while retaining the original zero-send assertions and actual hold posture.

## Completed evidence

- PHP unit:2193 tests,10770 assertions, one existing skip; legacy PHP suites also pass.
- Catalog WordPress:48 tests, zero errors/failures (production-mode local suite). All moved response tests remain included in the catalog configuration.
- Signed admission/owner focused group:71 tests,1527 assertions passed.
- Fresh sequential consumer, FL03/FL04 held-package and supplier-switch group:19 tests,2544 assertions passed.
- Shared signer/research-to-product transition plus exact four-shop listing identity group:22 tests,393 assertions passed. Unowned lifecycle proofs remain and still deny reads; audit preservation markers are unique per case.
- Late rendering/migration/supplier window:341 tests, zero errors; one brittle listing-ID prefix assertion was identified and corrected, then the focused22-test group passed. All render and supplier-switch tests in that window passed.
- Retained document custody/listing, raw catalog boundary and STOP ALL regression:34 tests,652 assertions passed with process-isolated uploads.
- Concurrent durable claim regression:1 test,17 assertions passed, including exactly one winner and persisted UNKNOWN.
- Offline acceptance:198 tests passed, using the pinned cutout runtime.
- Security:14 tests passed, including genuine-new-credential, wrong-object/location, duplicate-member and suppression-immunity cases.
- Static analysis, configured coding standards, PHP syntax, whitespace and recovered HTTP/secret policy gates pass.
- Reproducible package builds and its checksum verifies; no installation performed.
- Exact8c176e8c history evidence:3086 reachable commits;21 raw scanner findings,21 structurally verified Git identifiers, zero unresolved. See `history-8c176e8c.json`. Historical commit/blob identity is checked against the finding's own commit; no ignore/config suppression was added.

The environment restart interrupted earlier local comprehensive runs; those incomplete runs are not acceptance certificates. A subsequent fresh run completed825 tests before identifying the now-fixed child-worker configuration mismatch at test826. Comprehensive exact-head CI remains the final acceptance gate; do not infer a PASS from these focused results.

## Release posture and next batch

STOP ALL, external safety lock, automation OFF and RELEASE HOLD remain required. No hosted installation, database mutation, merge, live Printify/Etsy operation, Cloudflare deployment or credential change is authorized by this evidence. Production installed-byte equivalence remains unverified, as recorded in the earlier recovery report.

After comprehensive acceptance, qualify FL03 mug and FL04 poster artwork/typography boundaries against actual supplier geometry and native field IDs; obtain five distinct rights-reviewed supplier mockups with parity evidence; complete commercial artwork/listing review and held export evidence. Keep provider transport in the independently qualified PR1263 interface lane. Local synthetic proofs, imported images and passing CI do not certify supplier authenticity or launch authorization.
