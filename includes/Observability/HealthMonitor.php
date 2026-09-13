<?php

declare(strict_types=1);

namespace DigiForge\Observability;

use DigiForge\Core\Settings;
use DigiForge\Database\MigrationPlan;
use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

final class HealthMonitor
{
    /** @return array{status:string, automation_locked:bool, schema:array{current:int, expected:int}, audit:array{status:string, last_failure:?array<string, string>}, queue:array{status:string, query_ok:bool, expired_leases:int, dead_letters:int}} */
    public function snapshot(): array
    {
        global $wpdb;
        $currentSchema = (int) get_option('digiforge_db_schema_version', 0);

        $wpdb->last_error = '';
        $expiredRaw = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Tables::jobs() . " WHERE lease_expires_at IS NOT NULL AND lease_expires_at < %s AND state = 'RUNNING'",
                current_time('mysql', true)
            )
        );
        $expiredQueryOk = $wpdb->last_error === '' && $expiredRaw !== null;
        $expiredLeases = $expiredQueryOk ? (int) $expiredRaw : 0;

        $wpdb->last_error = '';
        $deadRaw = $wpdb->get_var("SELECT COUNT(*) FROM " . Tables::jobs() . " WHERE state = 'DEAD_LETTER'");
        $deadQueryOk = $wpdb->last_error === '' && $deadRaw !== null;
        $deadLetters = $deadQueryOk ? (int) $deadRaw : 0;
        $queueQueryOk = $expiredQueryOk && $deadQueryOk;

        $auditFailure = Logger::lastFailure();

        $schemaStatus = $currentSchema === MigrationPlan::LATEST ? HealthStatus::HEALTHY : HealthStatus::UNHEALTHY;
        $auditStatus = $auditFailure === null ? HealthStatus::HEALTHY : HealthStatus::DEGRADED;
        $queueStatus = ! $queueQueryOk
            ? HealthStatus::UNHEALTHY
            : ($expiredLeases > 0 ? HealthStatus::DEGRADED : HealthStatus::HEALTHY);

        return [
            'status' => HealthStatus::aggregate([$schemaStatus, $auditStatus, $queueStatus]),
            'automation_locked' => Settings::safety_locked(),
            'schema' => ['current' => $currentSchema, 'expected' => MigrationPlan::LATEST],
            'audit' => ['status' => $auditStatus, 'last_failure' => $auditFailure],
            'queue' => ['status' => $queueStatus, 'query_ok' => $queueQueryOk, 'expired_leases' => $expiredLeases, 'dead_letters' => $deadLetters],
        ];
    }
}
