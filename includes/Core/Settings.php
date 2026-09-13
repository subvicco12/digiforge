<?php
declare(strict_types=1);

namespace DigiForge\Core;

use DigiForge\Database\Tables;

/** Typed, fail-closed access to non-secret DigiForge settings. */
final class Settings
{
    public static function ensure_defaults(): void
    {
        foreach (Config::default_settings() as $key => $value) {
            if (self::get($key, null) === null) {
                self::setInternal($key, $value);
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! Config::allowed_setting($key)) {
            return $default;
        }

        if ($key === 'cleanup_on_uninstall') {
            return (bool) get_option('digiforge_cleanup_on_uninstall', false);
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT setting_value, setting_type FROM ' . Tables::settings() . ' WHERE setting_key = %s',
                $key
            ),
            ARRAY_A
        );

        if (! is_array($row) || ($row['setting_type'] ?? '') !== Config::setting_type($key)) {
            return $default;
        }

        $decoded = self::decode((string) $row['setting_value']);
        return Config::valid_value($key, $decoded) ? $decoded : $default;
    }

    public static function set(string $key, mixed $value, string $type = ''): bool
    {
        if (! Config::writable_setting($key) || ! Config::valid_value($key, $value)) {
            return false;
        }

        if ($type !== '' && $type !== Config::setting_type($key)) {
            return false;
        }

        return self::persist($key, $value);
    }

    public static function is_enabled(string $switch): bool
    {
        if (! Config::allowed_switch($switch) || $switch === 'stop_all') {
            return false;
        }

        return self::get('activation_authorized', false) === true
            && self::get('automation_armed', false) === true
            && self::get('stop_all', true) === false
            && self::get($switch, false) === true;
    }

    public static function safety_locked(): bool
    {
        return self::get('activation_authorized', false) !== true
            || self::get('automation_armed', false) !== true
            || self::get('stop_all', true) === true;
    }

    /** Credentials are never exposed through this settings API. */
    public static function secrets(): array
    {
        return [];
    }

    private static function setInternal(string $key, mixed $value): bool
    {
        if (! Config::allowed_setting($key) || ! Config::valid_value($key, $value)) {
            return false;
        }

        return self::persist($key, $value);
    }

    private static function persist(string $key, mixed $value): bool
    {
        if ($key === 'cleanup_on_uninstall') {
            return update_option('digiforge_cleanup_on_uninstall', (bool) $value, false);
        }

        global $wpdb;
        return false !== $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . Tables::settings() . ' (setting_key, setting_value, setting_type, updated_at) VALUES (%s,%s,%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_type=VALUES(setting_type), updated_at=UTC_TIMESTAMP()',
                $key,
                wp_json_encode($value),
                Config::setting_type($key)
            )
        );
    }

    private static function decode(string $value): mixed
    {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
