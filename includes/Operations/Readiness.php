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
            'database_backup_available' => $this->optionEnabled('digiforge_recovery_database_backup_available'),
            'plugin_package_available' => $this->optionEnabled('digiforge_recovery_plugin_package_available'),
            'checksum_verified' => $this->optionEnabled('digiforge_recovery_checksum_verified'),
            'schema_version_known' => $schemaCurrent,
            'restore_instructions_available' => $this->optionEnabled('digiforge_recovery_restore_instructions_available'),
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
            'recovery_drill_available' => method_exists(RecoveryDrill::class, 'evaluate'),
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
            'external_actions_performed' => $this->externalActionsPerformed(),
        ];
        $payload['evidence_hash'] = hash('sha256', (string) wp_json_encode($payload));

        return $payload;
    }

    private function optionEnabled(string $name): bool
    {
        return filter_var(get_option($name, false), FILTER_VALIDATE_BOOLEAN) === true;
    }
    private function externalActionsPerformed(): bool
    {
        global $wpdb;
        $table=$wpdb->prefix.'digiforge_etsy_operations';
        $exists=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE state IN (%s,%s,%s,%s,%s) LIMIT 1",
            'SENT','UNKNOWN','RECONCILIATION','RECONCILED','CONFIRMED_SUCCESS'
        ));
        return (int)$exists>0;
    }
}
