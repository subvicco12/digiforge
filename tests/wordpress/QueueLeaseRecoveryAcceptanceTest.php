<?php
declare(strict_types=1);

/** Disposable local queue persistence; never dispatches a worker or provider. */
final class QueueLeaseRecoveryAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
    }

    private function job(): int
    {
        $id = (new DigiForge\Queue\JobRepository())->enqueue('local_queue_acceptance', [], 'local_queue_'.wp_generate_uuid4());
        self::assertIsInt($id);
        return $id;
    }

    private function row(int $id): array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d', $id), ARRAY_A);
    }

    public function testIdempotencyBindsExactJobTypeAndPayloadWithoutChangingStoredIntent(): void
    {
        $repository = new DigiForge\Queue\JobRepository();
        $key = 'queue_identity_'.wp_generate_uuid4();
        $payload = ['shop'=>'digital', 'source_id'=>42, 'options'=>['mode'=>'review']];
        $id = $repository->enqueue('local_queue_acceptance', $payload, $key);
        self::assertIsInt($id);
        $before = $this->row($id);
        self::assertSame($id, $repository->enqueue('local_queue_acceptance', $payload, $key));
        foreach ([['another_job', $payload], ['local_queue_acceptance', array_replace($payload, ['shop'=>'jewelry'])]] as [$type, $changed]) {
            $result = $repository->enqueue($type, $changed, $key);
            self::assertWPError($result);
            self::assertSame('job_idempotency_conflict', $result->get_error_code());
        }
        self::assertSame($before, $this->row($id));
        self::assertSame('BLOCKED', $before['state']);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

    public function testDurableDependenciesGateLeaseAndStartUntilSuccessAndRejectConflictingReplay(): void
    {
        global $wpdb;
        $repository = new DigiForge\Queue\JobRepository();
        $dependency = $this->job();
        $key = 'queue_dependent_'.wp_generate_uuid4();
        $id = $repository->enqueue('dependent_acceptance', [], $key, [$dependency]);
        self::assertIsInt($id);
        self::assertTrue($repository->transition($id, 'BLOCKED', 'QUEUED'));
        self::assertFalse($repository->acquireLease($id, 'dependent_worker'));
        self::assertFalse($repository->start($id, 'dependent_worker'));
        self::assertWPError($repository->enqueue('dependent_acceptance', [], $key, []));
        $wpdb->update(DigiForge\Database\Tables::jobs(), ['state'=>'SUCCESS'], ['id'=>$dependency]);
        $table = DigiForge\Database\Tables::jobs();
        $filter = static fn(string $sql): string => str_starts_with($sql, 'SELECT id,state FROM '.$table) ? str_replace($table, $table.'_unavailable', $sql) : $sql;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try { self::assertFalse($repository->acquireLease($id, 'dependent_worker')); }
        finally { remove_filter('query', $filter); $wpdb->suppress_errors($previous); $wpdb->last_error = ''; }
        self::assertNull($this->row($id)['locked_by']);
        self::assertTrue($repository->acquireLease($id, 'dependent_worker'));
        self::assertTrue($repository->start($id, 'dependent_worker'));
        self::assertSame('RUNNING', $this->row($id)['state']);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        $tampered = $this->job();
        $wpdb->update($table, ['payload'=>'{"_digiforge_dependencies":null}'], ['id'=>$tampered]);
        self::assertTrue($repository->transition($tampered, 'BLOCKED', 'QUEUED'));
        self::assertFalse($repository->acquireLease($tampered, 'dependent_worker'));
        self::assertFalse($repository->start($tampered, 'dependent_worker'));
    }

    public function testUnknownFailureClassificationCannotScheduleReplayWhileKnownRateLimitPersistsBoundedBackoff(): void
    {
        $repository = new DigiForge\Queue\JobRepository();
        $id = $this->job();
        self::assertTrue($repository->transition($id, 'BLOCKED', 'QUEUED'));
        self::assertTrue($repository->acquireLease($id, 'classified_worker'));
        self::assertTrue($repository->start($id, 'classified_worker'));
        $before = $this->row($id);
        self::assertFalse($repository->scheduleRetry($id, 60, 'UNKNOWN'));
        self::assertFalse($repository->scheduleRetry($id, 60, 'unclassified error'));
        self::assertFalse($repository->scheduleClassifiedRetry($id, 60, 'digiforge_launch_ai_rate_limited', ['retry_permitted'=>true,'provider_outcome'=>'UNKNOWN']));
        self::assertSame($before, $this->row($id));
        self::assertTrue($repository->scheduleClassifiedRetry($id, 60, 'digiforge_launch_ai_rate_limited', ['retry_permitted'=>true,'provider_outcome'=>'NOT_SENT']));
        $after = $this->row($id);
        self::assertSame('RETRY', $after['state']);
        self::assertSame(1, (int)$after['attempts']);
        self::assertNotNull($after['next_attempt_at']);
        self::assertNull($after['locked_by']);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

    public function testLeaseOwnershipAndOrphanRecoveryRequireReviewWithoutReplay(): void
    {
        global $wpdb;
        $repository = new DigiForge\Queue\JobRepository();
        $id = $this->job();
        self::assertFalse($repository->start($id, 'worker_a'));
        self::assertTrue($repository->transition($id, 'BLOCKED', 'QUEUED'));
        self::assertTrue($repository->acquireLease($id, 'worker_a'));
        self::assertFalse($repository->acquireLease($id, 'worker_b'));
        self::assertFalse($repository->start($id, 'worker_b'));
        self::assertFalse($repository->releaseLease($id, 'worker_b'));
        self::assertTrue($repository->start($id, 'worker_a'));
        self::assertFalse($repository->recoverExpiredLease($id));
        $wpdb->update(DigiForge\Database\Tables::jobs(), ['lease_expires_at'=>gmdate('Y-m-d H:i:s', time()-120)], ['id'=>$id]);
        $diagnostics = (new DigiForge\Queue\OperatorQueueReadModel())->diagnostics();
        self::assertSame('AVAILABLE', $diagnostics['query_state']);
        self::assertGreaterThanOrEqual(1, $diagnostics['orphaned_expired_leases']);
        self::assertFalse($diagnostics['replay_permitted']);
        self::assertTrue($repository->recoverExpiredLease($id));
        self::assertFalse($repository->recoverExpiredLease($id));
        $row = $this->row($id);
        self::assertSame('HUMAN_REVIEW', $row['state']);
        self::assertSame('LEASE_EXPIRED', $row['last_error']);
        self::assertNull($row['locked_by']);
        self::assertNull($row['lease_expires_at']);
        $attention = (new DigiForge\Queue\OperatorQueueReadModel())->attentionById($id);
        self::assertIsArray($attention);
        self::assertFalse($attention['retry_permitted']);
        self::assertFalse($attention['external_execution_authorized']);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

    public function testRetryCeilingPersistsDeadLetterAndReadFailureCannotChangeJob(): void
    {
        global $wpdb;
        $repository = new DigiForge\Queue\JobRepository();
        $id = $this->job();
        self::assertTrue($repository->transition($id, 'BLOCKED', 'QUEUED'));
        self::assertTrue($repository->acquireLease($id, 'worker'));
        self::assertTrue($repository->start($id, 'worker'));
        $before = $this->row($id);
        $table = DigiForge\Database\Tables::jobs();
        $filter = static fn(string $sql): string => str_starts_with($sql, 'SELECT attempts') && str_contains($sql, ' FROM '.$table) ? str_replace($table, $table.'_unavailable', $sql) : $sql;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try { self::assertFalse($repository->scheduleRetry($id, 60, 'digiforge_launch_ai_rate_limited', ['retry_permitted'=>true,'provider_outcome'=>'NOT_SENT'])); }
        finally { remove_filter('query', $filter); $wpdb->suppress_errors($previous); $wpdb->last_error = ''; }
        self::assertSame($before, $this->row($id));
        for ($attempt=1; $attempt<=3; ++$attempt) {
            self::assertTrue($repository->scheduleRetry($id, 60, 'digiforge_launch_ai_rate_limited', ['retry_permitted'=>true,'provider_outcome'=>'NOT_SENT']));
            $row = $this->row($id);
            self::assertSame($attempt, (int)$row['attempts']);
            self::assertNull($row['locked_by']);
            if ($attempt<3) {
                self::assertSame('RETRY', $row['state']);
                self::assertTrue($repository->transition($id, 'RETRY', 'QUEUED'));
                self::assertTrue($repository->acquireLease($id, 'worker'));
                self::assertTrue($repository->start($id, 'worker'));
            }
        }
        self::assertSame('DEAD_LETTER', $this->row($id)['state']);
        self::assertFalse($repository->scheduleRetry($id, 60, 'again'));
        self::assertFalse($repository->transition($id, 'DEAD_LETTER', 'QUEUED'));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }
}
