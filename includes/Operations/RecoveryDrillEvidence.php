<?php

declare(strict_types=1);

namespace DigiForge\Operations;

final class RecoveryDrillEvidence
{
    private const MAX_AGE_SECONDS = 86400;

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $record = get_option('digiforge_recovery_drill_evidence', []);
        if (! is_array($record)) $record = [];

        $backup = RecoveryEvidence::snapshot();
        $databaseIdentifier = (string) ($backup['database_backup']['identifier'] ?? '');
        $packageIdentifier = (string) ($backup['plugin_package']['identifier'] ?? '');

        $complete = self::complete($record, [
            'drill_id','performed_at','environment','database_backup_identifier',
            'plugin_package_identifier','performed_by',
        ]);
        $bound = $complete
            && $databaseIdentifier !== ''
            && $packageIdentifier !== ''
            && hash_equals($databaseIdentifier, (string) $record['database_backup_identifier'])
            && hash_equals($packageIdentifier, (string) $record['plugin_package_identifier']);
        $fresh = $complete && self::fresh((string) $record['performed_at']);
        $verified = $complete
            && self::truthy($record, 'restore_verified')
            && self::truthy($record, 'schema_verified')
            && self::truthy($record, 'application_health_verified');

        return [
            'evidence' => $record,
            'evidence_available' => $complete,
            'evidence_bound_to_current_artifacts' => $bound,
            'evidence_fresh' => $fresh,
            'restore_verified' => $verified,
            'passed' => $complete && $bound && $fresh && $verified,
            'external_actions_performed' => false,
            'external_execution_authorized' => false,
        ];
    }

    /** @param array<string,mixed> $record */
    public static function store(array $record): bool
    {
        $strings = ['drill_id','performed_at','environment','database_backup_identifier','plugin_package_identifier','performed_by'];
        $booleans = ['restore_verified','schema_verified','application_health_verified'];
        $normalized = [];
        foreach ($strings as $key) {
            $value = isset($record[$key]) ? trim(sanitize_text_field((string) $record[$key])) : '';
            if ($value === '') return false;
            $normalized[$key] = $value;
        }
        foreach ($booleans as $key) {
            if (! array_key_exists($key, $record)) return false;
            $normalized[$key] = filter_var($record[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized[$key] !== true) return false;
        }
        if (! self::fresh($normalized['performed_at'])) return false;

        $artifacts = RecoveryEvidence::snapshot();
        $databaseIdentifier = (string) ($artifacts['database_backup']['identifier'] ?? '');
        $packageIdentifier = (string) ($artifacts['plugin_package']['identifier'] ?? '');
        if ($databaseIdentifier === '' || $packageIdentifier === '') return false;
        if (! hash_equals($databaseIdentifier, $normalized['database_backup_identifier'])) return false;
        if (! hash_equals($packageIdentifier, $normalized['plugin_package_identifier'])) return false;
        if (($artifacts['database_backup_retrievable'] ?? false) !== true) return false;
        if (($artifacts['plugin_package_retrievable'] ?? false) !== true) return false;
        if (($artifacts['checksum_verified'] ?? false) !== true) return false;

        $normalized['recorded_at'] = gmdate('c');
        $normalized['evidence_hash'] = hash('sha256', wp_json_encode($normalized, JSON_UNESCAPED_SLASHES));
        return update_option('digiforge_recovery_drill_evidence', $normalized, false);
    }

    /** @param array<string,mixed> $record @param list<string> $keys */
    private static function complete(array $record, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! isset($record[$key]) || trim((string) $record[$key]) === '') return false;
        }
        return true;
    }

    private static function fresh(string $value): bool
    {
        $time = strtotime($value);
        if ($time === false || $time > time() + 300) return false;
        return (time() - $time) <= self::MAX_AGE_SECONDS;
    }

    /** @param array<string,mixed> $record */
    private static function truthy(array $record, string $key): bool
    {
        return isset($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_BOOLEAN) === true;
    }
}
