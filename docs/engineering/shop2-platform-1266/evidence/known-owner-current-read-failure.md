# Known owner current-read failure — FAIL / PENDING

**Managed integration: PARTIAL / NOT ACCEPTED.** A green default CI run does not
establish acceptance of these failures. Safety holds do not repair stale
currentness.

The executable reproduction is intentionally outside the default WordPress acceptance suite. It reuses `CustomerPhotoHeldIntegrationTest` and the actual photo upload, canonical submission, independent review and native business fixtures. No resolver stand-in or private photo option mutation is used.

Run only against the disposable local WordPress fixture database, from the repository root with the exclusive test database slot. Requalification command selecting the dedicated disposable configuration:

```sh
PATH=/workspace/.onboarding/bin:$PATH DIGIFORGE_CUTOUT_PYTHON=/workspace/.onboarding/cutout-runtime/bin/python WP_TESTS_DIR=/workspace/digiforge-platform-sprint/wp-develop-released/tests/phpunit /workspace/.onboarding/bin/php -d auto_prepend_file=/workspace/digiforge-platform-sprint/preload-clean-lane-b.php /workspace/digiforge/vendor/bin/phpunit -c phpunit.wordpress.xml.dist docs/engineering/shop2-platform-1266/evidence/CustomerPhotoOwnerCurrentReadFailureReproTest.php --filter 'testActualProducerSelectionCannotRemainOldAcrossCommittedSamePixelRevision|testLegacyNullCannotRemainAfterFirstManagedSelectionCommitted'
```

The initial observation used the released test-library default config. The dedicated-config command above has now reproduced the same two actual failures on the explicit isolated configuration, with PHPUnit exit 1, not xfail or passing-test semantics.

Observed against imported A `784458955d8882e11429980355a94f338a67ae4d`: **2 tests, 22 assertions, 2 actual failures**, 3.309 seconds. The retained XML captures the dedicated-config actual result. The sanitized `.log` is gitignored and remains workspace-only.

1. A real second wpdb connection uploads the same pixels, selects revision 2 and independently approves it, then proves the committed revision is visible. The first connection's existing REPEATABLE READ snapshot still returns revision 1 from the public authoritative resolver.
2. The first connection primes an authoritative legacy NULL and valid old canonical input. A real second connection creates and approves the first managed photo selection. The first connection still returns the legacy projection through B currentness, despite the committed managed selection.

| Caller snapshot and independent commit | Expected | Actual |
| --- | --- | --- |
| RR sees approved selection revision 1; second connection commits independently approved revision 2 | Public A reader returns revision 2 or an explicit denial | Public A reader returns revision 1 |
| RR sees legacy NULL; second connection commits the first independently approved managed selection | B returns the current managed selection identity or an explicit denial | B returns the old legacy projection without selection SHA/revision |

Lane A handoff request: <https://github.com/subvicco12/digiforge/issues/1266#issuecomment-6908593170>.
The source owner must establish fresh selection/legacy-NULL and stored-render epoch
semantics inside caller-owned transactions, including writer coordination through
the consuming decision, current scope/revocation and unavailable-evidence denial.
B cannot commit or roll back a caller transaction, inspect private A storage, or
derive a fresh-absence guarantee from repeated ordinary RR reads. A blanket B
transaction denial would also block existing legacy operator handlers; it is not
a compatibility-preserving repair.

The B guard denying transactions when a managed record is already visible does **not** close the second case. Managed registered transaction flows remain unavailable, and overall currentness acceptance remains pending authoritative fresh-read semantics from A. The separate passing managed consumer tests establish autocommit composition, epoch binding and denials; they do not establish transactional freshness or native execution authority. No provider request or production release occurs.
