# Isolated c2f producer handoff — safety and availability assessed separately

Candidate only: B `75ee5c9d4117d6a627930fed5d0f00bf89ef7395` plus the exact eight A blobs from `c2f8dcf4315b591ad415de53bbc77c7d8bcc5354`. Primary B runtime, source pins, and all selected B consumer/legacy tests are unchanged. The import manifest and assessment JSON record hashes.

| Assessment | Actual result |
| --- | --- |
| Real two-connection old epoch and old legacy NULL | 2 tests / 44 assertions PASS by exact caller-transaction denial |
| Stable managed caller transaction | 1 test / 14 assertions PASS by exact denial |
| Unmodified original local order workflow | 14 tests / 50 assertions, 8 actual failures |
| Unmodified autocommit photo → render review → package review → approved plan → BLOCKED intent → held preparation | 1 test / 35 assertions PASS |

The two external repros prime valid public selection/NULL in autocommit before opening RR. Their old setup called the public reader inside RR to obtain a successful prime; that is expressly unsupported by this new contract. The adapted assertions require `photo_current_read_caller_transaction` both before and after actual independent producer commits, caller transaction still active, and an uncommitted test comment still visible. Only the test's rollback removes that pending write. The stable-managed test establishes the same property without a source change. No producer mutation authority or pre-transaction cached selection is used as B authority. An initial option-table marker interfered with A's option-CAS scan; the marker was moved to the unrelated comments table before the retained passing runs.

The original local workflow suite is byte-identical to the baseline. Eight positive/after-write cases fail: confirmation enters its established business transaction, authoritative photo resolution denies even legacy NULL, readiness cannot pass, and the intended local transition or DRAFT write is not reached. These failures were not converted to expected denials or skipped. The JSON lists each failed method, and XML retains the actual assertions.

The A contract explicitly excludes every caller-owned transaction, including a read lease acquired before B starts its transaction. Removing B's older managed-only transaction guard cannot overcome the new producer denial. Calling `acquireMutation()`/`startMutationTransaction()` as a B transaction primitive, changing isolation/committing caller work, or treating pre-transaction data as current would contradict the handoff. This is an explicit-denial security improvement with an observed legacy availability regression, **not compatible B transaction integration acceptance**. The autocommit success does not repair that regression or promote production authority.

All commands ran from `/workspace/digiforge-platform-sprint/photo-c2f-integration` against the exclusive disposable test database using this prefix:

```sh
PATH=/workspace/.onboarding/bin:$PATH DIGIFORGE_CUTOUT_PYTHON=/workspace/.onboarding/cutout-runtime/bin/python WP_TESTS_DIR=/workspace/digiforge-platform-sprint/wp-develop-released/tests/phpunit /workspace/.onboarding/bin/php -d auto_prepend_file=/workspace/digiforge-platform-sprint/preload-clean-lane-b.php /workspace/digiforge/vendor/bin/phpunit -c phpunit.wordpress.xml.dist
```

Append the following targets separately:

```text
docs/engineering/shop2-platform-1266/evidence/CustomerPhotoOwnerCurrentReadFailureReproTest.php --filter 'testActualProducerSelectionCannotRemainOldAcrossCommittedSamePixelRevision|testLegacyNullCannotRemainAfterFirstManagedSelectionCommitted'
docs/engineering/shop2-platform-1266/evidence/CustomerPhotoCandidateConsumerBoundaryTest.php --filter testStableManagedCallerTransactionIsExplicitlyDeniedWithoutFinishingCallerFrame
tests/wordpress/OrderLocalWorkflowTest.php
tests/wordpress/CustomerPhotoHeldIntegrationTest.php --filter testActualSelectedPhotoFlowsToCanonicalPlanHeldBundleAndRejectsSamePixelOldEpoch
```
