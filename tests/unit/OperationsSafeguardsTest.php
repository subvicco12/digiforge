<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Operations\RecoveryDrill;
use DigiForge\Operations\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class OperationsSafeguardsTest extends TestCase
{
    public function testImmutableRecordsCannotBeAutomaticallyDeleted(): void
    {
        foreach (['finance_ledger', 'fx_snapshots', 'analytics_snapshots', 'audit_log'] as $type) {
            self::assertTrue(RetentionPolicy::isImmutable($type));
            self::assertNull(RetentionPolicy::retentionDays($type));
            self::assertFalse(RetentionPolicy::automaticDeletionAllowed($type));
        }
    }

    public function testRetentionPolicyIsFailClosed(): void
    {
        self::assertSame(365, RetentionPolicy::retentionDays('health_events'));
        self::assertSame(730, RetentionPolicy::retentionDays('operational_alerts'));
        self::assertFalse(RetentionPolicy::automaticDeletionAllowed('health_events'));
        self::assertFalse(RetentionPolicy::automaticDeletionAllowed('unknown'));
        self::assertTrue(RetentionPolicy::describe()['human_review_required']);
    }

    public function testRecoveryDrillRequiresEveryLocalSafetyCheck(): void
    {
        $pass = RecoveryDrill::evaluate([
            'database_backup_available' => true,
            'plugin_package_available' => true,
            'checksum_verified' => true,
            'schema_version_known' => true,
            'restore_instructions_available' => true,
            'stop_all_confirmed' => true,
        ]);
        self::assertSame('PASS', $pass['status']);
        self::assertFalse($pass['external_actions_performed']);
        self::assertRegExp('/^[a-f0-9]{64}$/', $pass['evidence_hash']);

        $review = RecoveryDrill::evaluate(['database_backup_available' => true]);
        self::assertSame('REVIEW_REQUIRED', $review['status']);
        self::assertFalse($review['external_actions_performed']);
    }
}
