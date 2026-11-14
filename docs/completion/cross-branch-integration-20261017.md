# Local Blueprint / Printify / Finance integration

Inputs: main `844107ab9baf7e1303cd47708e9eab3bc700b348`, PR1185
`726a7d54acb338a3a07e68c4bcce6836c2c232ac` (audit4691 PASS), PR1193
`2f1413a54cd26fb2175c0e51f174edeb07e8dbf1` (audit4720 PASS).
This local branch starts at1185, preserving its full ancestry, and applies1193's
delta from the actual common ancestor1172. No source branch or main is modified.
Source audit passes do not certify this combined tree.

## Conflict resolution and integration contracts

- Keep1185's digest-pinned official MariaDB, complete Python/matting/history/security
  checks and two mandatory WordPress profiles. Add1193's three catalog smoke checks;
  Composer advisories remain mandatory.
- Keep1185's validated official explicit-release URLs and downloaded-version equality
  checks. All fixtures still use the installed release tag. Retain1193's executable
  installer regressions, updating only the expected stronger rejection diagnostic.
- Preserve1185's variantsWithEvidence/getWithEvidence API, transport-derived response
  hashes/timestamps and the data-only legacy wrapper. Retain1193's mandatory concrete
  scoped admission before/after dispatch, credential/ID bounds, endpoint response
  validation, deletion-resistant revocation/dispatch evidence and retry authorization.
- Add ScopedSmokeTest and IntegrationTest to the production profile, excluding them
  from the local profile. Existing exactly-once coverage enforcement is unchanged.
- Route catalog target resolution through ShopAccessTargetScope and the existing
  global ShopAccessRestGuard. Resolve a new grant through its exact configured
  integration/business/store/program; resolve existing routes through sealed/audited
  stored grant evidence. Never infer target shop from an arbitrary caller selector.
  Both connection and POD capabilities require explicit current shop assignments,
  in addition to existing owner/reader capabilities and REST nonce checks.
- Reuse the existing integration config.shop_key contract. Include that exact canonical
  shop in immutable grant binding. Missing, conflicting or drifted bindings fail closed;
  old grants without the new complete binding require fresh explicit owner review.
  Admission rechecks current assignments before credential decryption, immediately
  before dispatch and after response; in-flight revocation discards the result.
  No configuration or assignment is provisioned by this implementation.

## Acceptance reconciliation

The authoritative source ledger remains
`docs/engineering/original-source/distinct-nonfinance-acceptance.tsv` from1185.
Its268 atomic mandatory software rows remain246 IMPLEMENTED and22 UNVERIFIED:
11 owner approvals,7 external dependencies,4 composite acceptance items. No row
is promoted or its original source references/IDs altered by this integration.
All cited implementation/test paths and named test methods resolve in the composed
tree. The77 criteria previously absent from1193 depend on46 paths already in1185;
these are integrated dependencies, not77 new software features. Source records
(1771) are not distinct requirements. Full Blueprint acceptance remains INCOMPLETE.

Finance code is preserved from1193. Active-period approval remains denied pending
authenticated Etsy payment/fees and actual POD-charge imports/certification. Empty
local-period approval does not certify provider authenticity. Supplier geometry,
DPI/bleed/safe zones, licensed mockups/UV/seams, shipping and owner/installed-site
certification remain separate UNKNOWN or deferred evidence. Work D report was not
available to this integration; none of its findings is assumed.

No hosted audit, remote PR, merge, deployment or activation is authorized by this
local task. STOP ALL/external lock ON; automation OFF. Only synthetic local evidence
and disposable test databases are used; no real provider calls or credentials.

## Local validation

- Production catalog profile:36 tests/549 assertions, including the new scoped
  assignment and in-flight revocation regressions. The latter reproduced HTTP200
  after revocation before the fix and now returns403 without exposing draft data.
- Full local WordPress profile:891 tests/14746 assertions;2 existing real-SAM
  inference cases skipped because the optional isolated runtime is not configured.
- Full PHP suite:1354 tests/8216 assertions;1 existing optional real-SAM case skipped.
  Configured static/standards, preserved matting static and extra WordPress-aware
  catalog/assignment boundary analysis pass. Final affected-unit checks also run.
- Offline132 tests pass, including release installer and exactly-once profile coverage.
- Native synthetic portal/browser checks:81 two-shop plus102 composed checks at
  390/768/1280px, keyboard access and zero network requests. This is not authenticated
  deployed-site HTTP E2E or supplier certification.
- Credential checker regressions:6 tests pass. Final clean-HEAD history scan and
  package identity verification are recorded separately against the local commit.

No source-ledger category was promoted. Final-head hosted audit remains pending
separate authorization to publish this local candidate.
