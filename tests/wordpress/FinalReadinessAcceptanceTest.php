<?php
declare(strict_types=1);

/** Local failure evidence only; no restore, installation or business certification. */
final class FinalReadinessAcceptanceTest extends WP_UnitTestCase
{
    private array $saved = [];
    private int $networkCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        foreach (['digiforge_recovery_database_backup_evidence', 'digiforge_recovery_plugin_package_evidence', 'digiforge_recovery_drill_evidence', 'digiforge_db_schema_version'] as $name) {
            $this->saved[$name] = get_option($name, false);
        }
        add_filter('pre_http_request', [$this, 'blockNetwork'], 1, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'blockNetwork'], 1);
        foreach ($this->saved as $name => $value) {
            $value === false ? delete_option($name) : update_option($name, $value, false);
        }
        DigiForge\Core\Settings::protectProduction();
        parent::tearDown();
    }

    public function blockNetwork(): WP_Error
    {
        ++$this->networkCalls;
        return new WP_Error('local_readiness_network_forbidden');
    }

    private function assertProtected(array $report): void
    {
        self::assertSame('REVIEW_REQUIRED', $report['status']);
        self::assertTrue($report['externally_locked']);
        self::assertTrue($report['checks']['stop_all_active']);
        self::assertTrue($report['checks']['activation_not_authorized']);
        self::assertTrue($report['checks']['automation_unarmed']);
        self::assertFalse(in_array(true, $report['effective_switches'], true));
        $hash = $report['evidence_hash'];
        unset($report['evidence_hash']);
        self::assertSame(hash('sha256', (string)wp_json_encode($report)), $hash);
        self::assertSame(0, $this->networkCalls);
    }

    public function testMissingRecoveryAndSchemaDriftCannotClaimReadyLocked(): void
    {
        foreach (['digiforge_recovery_database_backup_evidence', 'digiforge_recovery_plugin_package_evidence', 'digiforge_recovery_drill_evidence'] as $name) delete_option($name);
        $report = (new DigiForge\Operations\Readiness())->report();
        $this->assertProtected($report);
        self::assertFalse($report['checks']['recovery_drill_passed']);
        self::assertFalse($report['recovery_artifact_evidence']['database_backup_retrievable']);
        self::assertFalse($report['recovery_artifact_evidence']['checksum_verified']);
        update_option('digiforge_db_schema_version', DigiForge\Database\MigrationPlan::LATEST - 1, false);
        $report = (new DigiForge\Operations\Readiness())->report();
        $this->assertProtected($report);
        self::assertFalse($report['checks']['schema_current']);
    }

    public function testUnavailableQueueAndExternalEvidenceRemainUnknownNotZero(): void
    {
        global $wpdb;
        foreach (['queue', 'external'] as $failure) {
            $filter = static function (string $sql) use ($failure): string {
                $table = $failure === 'queue' ? DigiForge\Database\Tables::jobs() : $GLOBALS['wpdb']->prefix.'digiforge_etsy_operations';
                return str_starts_with($sql, 'SELECT ') && str_contains($sql, $table)
                    ? str_replace($table, $table.'_offline_unavailable', $sql) : $sql;
            };
            $previous = $wpdb->suppress_errors(true);
            add_filter('query', $filter);
            try { $report = (new DigiForge\Operations\Readiness())->report(); }
            finally { remove_filter('query', $filter); $wpdb->suppress_errors($previous); $wpdb->last_error = ''; }
            $this->assertProtected($report);
            if ($failure === 'queue') {
                self::assertFalse($report['checks']['queue_query_verified']);
                self::assertFalse($report['checks']['queue_has_no_expired_leases']);
            } else {
                self::assertFalse($report['checks']['external_actions_query_verified']);
                self::assertSame('UNAVAILABLE', $report['external_actions_query_state']);
                self::assertNull($report['external_actions_performed']);
            }
        }
    }
}
