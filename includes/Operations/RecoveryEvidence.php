<?php

declare(strict_types=1);

namespace DigiForge\Operations;

final class RecoveryEvidence
{
    private const BACKUP_VERIFICATION_MAX_AGE_SECONDS = 86400;
    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $backup = self::record('digiforge_recovery_database_backup_evidence');
        $package = self::record('digiforge_recovery_plugin_package_evidence');
        return [
            'database_backup' => $backup,
            'database_backup_history' => self::backupHistory(),
            'plugin_package' => $package,
            'database_backup_available' => self::complete($backup, ['identifier','captured_at','location']),
            'database_backup_retrievable' => self::backupVerified($backup),
            'database_backup_verification_fresh' => self::backupVerificationFresh($backup),
            'database_backup_identity_recorded' => self::complete($backup, ['identifier','captured_at','location']),
            'plugin_package_available' => self::complete($package, ['identifier','version','source_commit','sha256','location']),
            'plugin_package_retrievable' => self::truthy($package, 'retrievable'),
            'plugin_package_identity_recorded' => self::complete($package, ['identifier','version','source_commit','sha256','location']),
            'checksum_verified' => self::truthy($package, 'checksum_verified'),
        ];
    }

    /** @param array<string,mixed> $record */
    public static function storeDatabaseBackup(array $record): bool
    {
        $normalized = self::normalize($record, ['identifier','captured_at','location','verification_method','verified_at','verified_by'], ['retrievable']);
        if ($normalized === null) return false;
        if (! self::truthy($normalized, 'retrievable')) return false;
        if (! self::validTimestamp($normalized['captured_at']) || ! self::validTimestamp($normalized['verified_at'])) return false;
        if (strtotime($normalized['verified_at']) < strtotime($normalized['captured_at'])) return false;
        if (strtotime($normalized['verified_at']) > time() + 300) return false;
        $normalized['verification_status'] = 'VERIFIED';
        $normalized['evidence_hash'] = hash('sha256', wp_json_encode($normalized, JSON_UNESCAPED_SLASHES));
        if (! self::appendBackupHistory($normalized)) return false;
        return update_option('digiforge_recovery_database_backup_evidence', $normalized, false);
    }

    /** @param array<string,mixed> $record */
    public static function storePluginPackage(array $record): bool
    {
        $normalized = self::normalize($record, ['identifier','version','source_commit','sha256','location'], ['retrievable','checksum_verified']);
        if ($normalized === null) return false;
        if (! preg_match('/^[a-f0-9]{40}$/', $normalized['source_commit'])) return false;
        if (! preg_match('/^[a-f0-9]{64}$/', $normalized['sha256'])) return false;
        return update_option('digiforge_recovery_plugin_package_evidence', $normalized, false);
    }

    /** @param list<string> $strings @param list<string> $booleans @return array<string,mixed>|null */
    private static function normalize(array $record, array $strings, array $booleans): ?array
    {
        $out = [];
        foreach ($strings as $key) {
            $value = isset($record[$key]) ? trim(sanitize_text_field((string) $record[$key])) : '';
            if ($value === '') return null;
            $out[$key] = $value;
        }
        foreach ($booleans as $key) {
            if (! array_key_exists($key, $record)) return null;
            $out[$key] = filter_var($record[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($out[$key] === null) return null;
        }
        $out['recorded_at'] = gmdate('c');
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function backupHistory(): array
    {
        $value = get_option('digiforge_recovery_database_backup_evidence_history', []);
        if (! is_array($value)) return [];
        return array_values(array_filter($value, 'is_array'));
    }

    /** @param array<string,mixed> $record */
    private static function appendBackupHistory(array $record): bool
    {
        $history = self::backupHistory();
        foreach ($history as $item) {
            if (($item['evidence_hash'] ?? '') === ($record['evidence_hash'] ?? '')) return true;
        }
        $history[] = $record;
        return update_option('digiforge_recovery_database_backup_evidence_history', $history, false);
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
    private static function backupVerified(array $record): bool
    {
        return self::truthy($record, 'retrievable')
            && ($record['verification_status'] ?? '') === 'VERIFIED'
            && self::complete($record, ['verification_method','verified_at','verified_by'])
            && self::backupVerificationFresh($record);
    }

    /** @param array<string,mixed> $record */
    private static function backupVerificationFresh(array $record): bool
    {
        if (! isset($record['verified_at']) || ! self::validTimestamp((string) $record['verified_at'])) return false;
        $verified = strtotime((string) $record['verified_at']);
        if ($verified === false || $verified > time() + 300) return false;
        return (time() - $verified) <= self::BACKUP_VERIFICATION_MAX_AGE_SECONDS;
    }

    private static function validTimestamp(string $value): bool
    {
        if (trim($value) === '') return false;
        $parsed = strtotime($value);
        return $parsed !== false;
    }

    /** @param array<string,mixed> $record */
    private static function truthy(array $record, string $key): bool
    {
        return isset($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_BOOLEAN) === true;
    }
}
