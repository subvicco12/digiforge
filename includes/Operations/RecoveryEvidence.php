<?php

declare(strict_types=1);

namespace DigiForge\Operations;

final class RecoveryEvidence
{
    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $backup = self::record('digiforge_recovery_database_backup_evidence');
        $package = self::record('digiforge_recovery_plugin_package_evidence');
        return [
            'database_backup' => $backup,
            'plugin_package' => $package,
            'database_backup_available' => self::complete($backup, ['identifier','captured_at','location']),
            'database_backup_retrievable' => self::truthy($backup, 'retrievable'),
            'database_backup_identity_recorded' => self::complete($backup, ['identifier','captured_at','location']),
            'plugin_package_available' => self::complete($package, ['identifier','version','source_commit','sha256','location']),
            'plugin_package_retrievable' => self::truthy($package, 'retrievable'),
            'plugin_package_identity_recorded' => self::complete($package, ['identifier','version','source_commit','sha256','location']),
            'checksum_verified' => self::truthy($package, 'checksum_verified'),
        ];
    }

    /** @return array<string,mixed> */
    private static function record(string $option): array
    {
        $value = get_option($option, []);
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $record @param list<string> $keys */
    private static function complete(array $record, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! isset($record[$key]) || trim((string) $record[$key]) === '') return false;
        }
        return true;
    }

    /** @param array<string,mixed> $record */
    private static function truthy(array $record, string $key): bool
    {
        return isset($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_BOOLEAN) === true;
    }
}
