<?php

declare(strict_types=1);

namespace DigiForge\Operations;

use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Observability\HealthMonitor;

final class Readiness
{
    /** @return array<string, mixed> */
    public function report(): array
    {
        $health = (new HealthMonitor())->snapshot();
        $effective = [];
        foreach (Config::SWITCHES as $switch) {
            if ($switch === 'stop_all') {
                continue;
            }
            $effective[$switch] = Settings::is_enabled($switch);
        }

        $schemaCurrent = ($health['schema']['current'] ?? -1) === ($health['schema']['expected'] ?? -2);
        $stopAll = Settings::get('stop_all', true) === true;
        $recovery = RecoveryDrill::evaluate([
            'database_backup_available' => get_option('digiforge_recovery_database_backup_available', false) === true,
            'plugin_package_available' => get_option('digiforge_recovery_plugin_package_available', false) === true,
            'checksum_verified' => get_option('digiforge_recovery_checksum_verified', false) === true,
            'schema_version_known' => $schemaCurrent,
            'restore_instructions_available' => get_option('digiforge_recovery_restore_instructions_available', false) === true,
            'stop_all_confirmed' => $stopAll,
        ]);

        $checks = [
            'schema_current' => $schemaCurrent,
            'stop_all_active' => $stopAll,
            'activation_not_authorized' => Settings::get('activation_authorized', false) !== true,
            'automation_unarmed' => Settings::get('automation_armed', false) !== true,
            'no_effective_feature_switches' => ! in_array(true, $effective, true),
            'audit_healthy' => ($health['audit']['status'] ?? '') === 'HEALTHY',
            'queue_query_verified' => ($health['queue']['query_ok'] ?? false) === true,
            'queue_has_no_expired_leases' => ($health['queue']['query_ok'] ?? false) === true && (int) ($health['queue']['expired_leases'] ?? 0) === 0,
            'retention_fail_closed' => RetentionPolicy::describe()['automatic_deletion_enabled'] === false,
            'recovery_drill_passed' => ($recovery['status'] ?? '') === 'PASS',
        ];

        $ready = ! in_array(false, $checks, true);
        $payload = [
            'status' => $ready ? 'READY_LOCKED' : 'REVIEW_REQUIRED',
            'version' => defined('DIGIFORGE_VERSION') ? DIGIFORGE_VERSION : 'unknown',
            'schema' => $health['schema'] ?? [],
            'externally_locked' => Settings::safety_locked(),
            'checks' => $checks,
            'recovery' => $recovery,
            'effective_switches' => $effective,
            'external_actions_performed' => false,
        ];
        $payload['evidence_hash'] = hash('sha256', (string) wp_json_encode($payload));

        return $payload;
    }
}
