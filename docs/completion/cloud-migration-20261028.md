# DigiForge cloud migration verification — 2026-10-28

DRAFT / RELEASE HOLD / NO-GO. STOP ALL ON; external lock ON; automation OFF.
No merge, deployment, production access/write, provider activation or Finance change.

## Source and preservation

Published cloud instance configuration revision 1 is running and connected, with current observations and enforced restricted network policy. No environment draft/configuration was changed or published.

GitHub was read through the configured proxy. Remote default HEAD/main is `844107ab9baf7e1303cd47708e9eab3bc700b348`. PR #1242 is OPEN and DRAFT, base main, head branch `feat/google-drive-scoped-grant-contract-20261024`, exact head `fac3634615c72ffdb0fdbce8ef1fbd3ae3c9e4c0`; engineering-safety-audit SUCCESS. This is the owner's supplied integration head, with main as an ancestor and 201 candidate commits ahead. It is not merged main.

All 1,250 advertised remote branches were inventoried and fetched without pruning. A read-only open-PR inventory records 91 open PRs, including 58 drafts; none was modified. No existing local or remote branch was deleted, reset, rebased or merged. Original `/workspace/digiforge` remains on clean branch `work` at main's SHA. Isolated linked worktree `/workspace/digiforge-cloud`, branch `cloud/nonfinance-migration-20261028`, was created from exact PR head. Its vendor directory is a separate copy with regenerated development autoloading.

Desktop-only migration is **UNVERIFIED**. No desktop filesystem, unpushed commit inventory, bundle, patch or untracked-file export is available. Remote branch coverage cannot prove that desktop-only work is absent. The owner confirms no verified desktop-only commit list or bundle currently exists. This does not block cloud setup, testing or independent non-Finance engineering. Request a bundle only if a specific missing dependency is demonstrated; never claim desktop-only migration is complete.

## Runtime and repeatability

- PHP 8.4.27; Composer 2.8.8; PHPUnit 9.6.38. Composer manifest validation and installed platform requirements pass. PHP includes mysqli, PDO MySQL, mbstring, XML, curl, sodium and zip.
- MariaDB 11.8.6, disposable local fixture `digiforge_tests`, loopback 127.0.0.1:3306. No production database is configured or used.
- WordPress development fixture `f96b4441e11517c74f5974a2919dd4a461563c61`, version `7.2-alpha-63166-src`. This alpha fixture is not a production WordPress compatibility certification.
- PHP/Composer wrappers require `PATH=/workspace/.onboarding/bin:$PATH`.
- `WP_TESTS_DIR=/workspace/.onboarding/wordpress-develop/tests/phpunit`.
- Repository-pinned numpy 2.2.6, opencv-python-headless 4.12.0.88 and Pillow 13.0.0 installed into `/workspace/.onboarding/cutout-runtime`.
- `DIGIFORGE_CUTOUT_PYTHON=/workspace/.onboarding/cutout-runtime/bin/python` and `DIGIFORGE_CUTOUT_PYTHONPATH=/workspace/.onboarding/cutout-runtime/lib/python3.12/site-packages`. Python offline regressions use the same site directory as PYTHONPATH and `PYTHONPYCACHEPREFIX=/tmp/digiforge-migration-pycache`.

Start `bash /workspace/.onboarding/start-db.sh` and run database tests sequentially in the **same command group**. Sandboxed networking needs approved execution through the configured proxy/local fixture; do not bypass policy. Background fixture lifetime does not survive its owning command group reliably. Do not overlap WordPress suites against this database: bootstrap drops/recreates test tables.

## Fresh test evidence at integration SHA plus recorded fixture delta

Raw logs and GitHub snapshots: `/workspace/digiforge-migration-evidence/`.

| Check | Result |
| --- | --- |
| Focused unit: OWNER policy, acceptance review and catalog | PASS: 98 tests / 349 assertions |
| Complete unit (`composer test:unit`) | PASS: 1,836 tests / 9,864 assertions / 1 skip; PHP 8.4 dynamic-property deprecation notices retained |
| External document admission/listing integrity WordPress | PASS: 25 tests / 598 assertions |
| Native OWNER and shop-isolation WordPress | PASS: 59 tests / 1,605 assertions |
| Offline OWNER package/capture regressions | PASS: 20 tests |
| Complete offline regressions | PASS: 194 tests |
| Catalog WordPress | PASS: 54 tests / 971 assertions |
| Complete WordPress, fresh database after fixture correction | PASS: 1,443 tests / 24,251 assertions / 2 skips |
| Legacy foundation/product/digital-product scripts | PASS: all three |
| Coding standards | PASS |
| PHPStan | PASS: no errors (loopback socket permission required) |
| Printify offline PHP smoke scripts | PASS: all three scripts |
| Google HTTP-boundary regressions | PASS: 3 tests |

Initial full-unit execution failed because the cutout runtime was not configured; the unchanged suite passed after installing/configuring the pinned dependencies. Initial extra integration runs were invalidated by overlapping fixture initialization/process lifetime; these infrastructure failures are not accepted test evidence. Clean sequential results replace them below. Negative-fixture audit/database error messages remain visible in raw logs; an exit status/count determines each result. No warning suppression, test deletion or production code change was used to obtain a pass.

## Continued non-Finance engineering

Continued existing OWNER overlay/capture, external-document, shop-isolation, acceptance and catalog qualification using their current regression suites. Runtime gaps above were repaired locally without changing application behavior or Finance. Continued independent non-Finance engineering also corrected two Drive integration corruption fixtures: assert successful COMMIT before deleting separately committed ledger options, while retaining exact deletion-count, denied-execution and zero-network assertions. No production PHP changed. The existing source acceptance ledger remains 246/268 bounded implementation candidates plus 22 non-Finance UNVERIFIED, with 23 Finance-dependent criteria separately excluded; no criterion is promoted by migration tests.

Next authorized local work follows the existing live-readiness and acceptance-review contracts. Authentic desktop inventory, private production source/preimages/dependency closure, matched restore evidence, genuine mailbox/installed browser acceptance and exact supplier/variant/geometry/rights evidence remain missing. Do not fabricate these from synthetic tests or expand into production installation, protected enrollment/configuration or live catalog/provider requests. Prior approvals are retained; this report grants no new activation authority.

## Broad WordPress failure investigation

The completed broad run has nine failures: two DriveArtifactTransferTest option-deletion assertions, one PrintifyPreflightTest readiness assertion, and six Schema19LifecycleProvenanceTest permit/provenance assertions. The log contains duplicate durable fixture identities; this is not a green broad-suite result. Fresh database isolation reduced these nine failures to two Drive stale-snapshot fixture failures; the other seven did not reproduce with a newly created database. A subsequent isolation attempt also lost the reused daemon when its earlier owning command group exited; that result is invalid infrastructure evidence. Future runs must own a newly started daemon, start from a fresh disposable test database, and explicitly stop it before command completion.

Two existing Drive corruption fixtures failed RED on MariaDB 11.8.6 with error1020 (record changed since last read) before they reached the intended missing-evidence assertions. RecoveryDispatchLedger deliberately uses a separate committed connection. Added an asserted COMMIT before each direct fixture DELETE to end the stale WordPress test transaction; retained the exact one-row deletion assertion, post-deletion commit/start, fail-closed workflow and zero-network guard. All three previously affected classes now PASS:55tests/684assertions. Read-only independent review found no important defect. Final complete-suite rerun on a newly created disposable database PASS:1,443tests/24,251assertions/2skips, exit0, runtime7m36.546s. Original broad result remains in wordpress.log; final result is wordpress-clean-final.log. Complete composer test also PASS after correction. MariaDB was explicitly shut down at command completion.

## Final local working state

Branch `cloud/nonfinance-migration-20261028` remains based at `fac3634615c72ffdb0fdbce8ef1fbd3ae3c9e4c0`. Two local reviewable files differ from the integration candidate: the Drive test fixture and this report. Changes are uncommitted and unpushed. Existing integration PR/remote branches remain unchanged. Full Composer and WordPress verification includes the test fixture delta. This is cloud development readiness, not complete desktop parity or production release certification.
