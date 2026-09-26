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
            if (self::get($key, null) === null) { self::setInternal($key, $value); }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! Config::allowed_setting($key)) { return $default; }
        if ($key === 'cleanup_on_uninstall') { return (bool) get_option('digiforge_cleanup_on_uninstall', false); }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT setting_value, setting_type FROM ' . Tables::settings() . ' WHERE setting_key = %s', $key), ARRAY_A);
        if (! is_array($row) || ($row['setting_type'] ?? '') !== Config::setting_type($key)) { return $default; }
        $decoded = self::decode((string) $row['setting_value']);
        return Config::valid_value($key, $decoded) ? $decoded : $default;
    }

    public static function set(string $key, mixed $value, string $type = ''): bool
    {
        if (! Config::writable_setting($key) || ! Config::valid_value($key, $value)) { return false; }
        if ($type !== '' && $type !== Config::setting_type($key)) { return false; }
        return self::persist($key, $value);
    }

    /**
     * Protected release transition. Internal activation gates cannot be changed
     * through the generic settings API; this is the sole runtime release path.
     * Existing feature switches are deliberately left unchanged.
     */
    public static function activateProduction(): bool
    {
        global $wpdb;
        $table = Tables::settings();
        $wpdb->query('START TRANSACTION');
        try {
            foreach (['activation_authorized' => true, 'automation_armed' => true, 'stop_all' => false] as $key => $value) {
                if (! self::persist($key, $value)) { throw new \RuntimeException('activation persistence failed'); }
            }
            if (false === $wpdb->query('COMMIT')) { throw new \RuntimeException('activation commit failed'); }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * Return production to the protected pre-release posture without changing
     * any feature configuration. Used after a scoped activation validation.
     */
    public static function protectProduction(): bool
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            foreach (['stop_all' => true, 'automation_armed' => false, 'activation_authorized' => false] as $key => $value) {
                if (! self::persist($key, $value)) { throw new \RuntimeException('protection persistence failed'); }
            }
            if (false === $wpdb->query('COMMIT')) { throw new \RuntimeException('protection commit failed'); }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * Returns the configured state of an internal-only switch without releasing
     * the production/external execution interlock. This must never be used for
     * publishing, POD, orders, fulfillment, tax, or other external actions.
     */
    public static function is_internal_enabled(string $switch): bool
    {
        if (! in_array($switch, ['ai', 'product_development'], true)) { return false; }
        return self::get($switch, false) === true;
    }

    public static function is_enabled(string $switch): bool
    {
        if (! Config::allowed_switch($switch) || $switch === 'stop_all') { return false; }
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

    public static function secrets(): array { return []; }

    private static function setInternal(string $key, mixed $value): bool
    {
        if (! Config::allowed_setting($key) || ! Config::valid_value($key, $value)) { return false; }
        return self::persist($key, $value);
    }

    private static function persist(string $key, mixed $value): bool
    {
        if ($key === 'cleanup_on_uninstall') { return update_option('digiforge_cleanup_on_uninstall', (bool) $value, false); }
        global $wpdb;
        return false !== $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Tables::settings() . ' (setting_key, setting_value, setting_type, updated_at) VALUES (%s,%s,%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_type=VALUES(setting_type), updated_at=UTC_TIMESTAMP()',
            $key, wp_json_encode($value), Config::setting_type($key)
        ));
    }

    private static function decode(string $value): mixed
    {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
