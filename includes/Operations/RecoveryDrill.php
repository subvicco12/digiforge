<?php

declare(strict_types=1);

namespace DigiForge\Operations;

final class RecoveryDrill
{
    /** @param array<string, bool> $checks */
    public static function evaluate(array $checks): array
    {
        $required = [
            'database_backup_available',
            'plugin_package_available',
            'checksum_verified',
            'schema_version_known',
            'restore_instructions_available',
            'stop_all_confirmed',
        ];

        $normalized = [];
        foreach ($required as $check) {
            $normalized[$check] = ($checks[$check] ?? false) === true;
        }

        $passed = ! in_array(false, $normalized, true);
        $payload = [
            'status' => $passed ? 'PASS' : 'REVIEW_REQUIRED',
            'checks' => $normalized,
            'external_actions_performed' => false,
        ];
        $payload['evidence_hash'] = hash('sha256', (string) wp_json_encode($payload));

        return $payload;
    }
}
