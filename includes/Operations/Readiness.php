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

        $checks = [
            'schema_current' => ($health['schema']['current'] ?? -1) === ($health['schema']['expected'] ?? -2),
            'stop_all_active' => Settings::get('stop_all', true) === true,
            'activation_not_authorized' => Settings::get('activation_authorized', false) !== true,
            'automation_unarmed' => Settings::get('automation_armed', false) !== true,
            'no_effective_feature_switches' => ! in_array(true, $effective, true),
            'audit_healthy' => ($health['audit']['status'] ?? '') === 'HEALTHY',
            'queue_has_no_expired_leases' => (int) ($health['queue']['expired_leases'] ?? 0) === 0,
            'retention_fail_closed' => RetentionPolicy::describe()['automatic_deletion_enabled'] === false,
            'recovery_drill_available' => method_exists(RecoveryDrill::class, 'evaluate'),
        ];

        $ready = ! in_array(false, $checks, true);
        $payload = [
            'status' => $ready ? 'READY_LOCKED' : 'REVIEW_REQUIRED',
            'version' => defined('DIGIFORGE_VERSION') ? DIGIFORGE_VERSION : 'unknown',
            'schema' => $health['schema'] ?? [],
            'externally_locked' => Settings::safety_locked(),
            'checks' => $checks,
            'effective_switches' => $effective,
            'external_actions_performed' => false,
        ];
        $payload['evidence_hash'] = hash('sha256', (string) wp_json_encode($payload));

        return $payload;
    }
}
