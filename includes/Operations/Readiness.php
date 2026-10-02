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
        $artifactEvidence = RecoveryEvidence::snapshot();
        $drillEvidence = RecoveryDrillEvidence::snapshot();
        $recovery = RecoveryDrill::evaluate([
            'database_backup_available' => $artifactEvidence['database_backup_available'],
            'database_backup_retrievable' => $artifactEvidence['database_backup_retrievable'],
            'database_backup_identity_recorded' => $artifactEvidence['database_backup_identity_recorded'],
            'plugin_package_available' => $artifactEvidence['plugin_package_available'],
            'plugin_package_retrievable' => $artifactEvidence['plugin_package_retrievable'],
            'plugin_package_identity_recorded' => $artifactEvidence['plugin_package_identity_recorded'],
            'checksum_verified' => $artifactEvidence['checksum_verified'],
            'schema_version_known' => $schemaCurrent,
            'restore_instructions_available' => $this->optionEnabled('digiforge_recovery_restore_instructions_available'),
            'stop_all_confirmed' => $stopAll,
        ]);
        if (($drillEvidence['passed'] ?? false) !== true) {
            $recovery['status'] = 'REVIEW_REQUIRED';
            $recovery['drill_evidence_required'] = true;
            $recovery['drill_evidence'] = $drillEvidence;
            $recovery['evidence_hash'] = hash('sha256', (string) wp_json_encode($recovery));
        }

        $externalActions = $this->externalActionsEvidence();

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
            'recovery_drill_passed' => ($recovery['status'] ?? '') === 'PASS' && ($drillEvidence['passed'] ?? false) === true,
            'external_actions_query_verified' => $externalActions['query_state'] === 'AVAILABLE',
        ];

        $ready = ! in_array(false, $checks, true);
        $payload = [
            'status' => $ready ? 'READY_LOCKED' : 'REVIEW_REQUIRED',
            'version' => defined('DIGIFORGE_VERSION') ? DIGIFORGE_VERSION : 'unknown',
            'schema' => $health['schema'] ?? [],
            'externally_locked' => Settings::safety_locked(),
            'checks' => $checks,
            'recovery' => $recovery,
            'recovery_artifact_evidence' => $artifactEvidence,
            'recovery_drill_evidence' => $drillEvidence,
            'effective_switches' => $effective,
            'external_actions_performed' => $externalActions['performed'],
            'external_actions_query_state' => $externalActions['query_state'],
        ];
        $payload['evidence_hash'] = hash('sha256', (string) wp_json_encode($payload));

        return $payload;
    }

    private function optionEnabled(string $name): bool
    {
        return filter_var(get_option($name, false), FILTER_VALIDATE_BOOLEAN) === true;
    }
    /** @return array{performed:?bool,query_state:string} */
    private function externalActionsEvidence(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'digiforge_etsy_operations';
        $wpdb->last_error = '';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE state IN (%s,%s,%s,%s,%s) LIMIT 1",
            'SENT', 'UNKNOWN', 'RECONCILIATION', 'RECONCILED', 'CONFIRMED_SUCCESS'
        ));
        if ($wpdb->last_error !== '') {
            return ['performed' => null, 'query_state' => 'UNAVAILABLE'];
        }
        return ['performed' => (int) $exists > 0, 'query_state' => 'AVAILABLE'];
    }
}
