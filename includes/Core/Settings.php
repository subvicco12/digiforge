<?php
declare(strict_types=1);
namespace DigiForge\Core;
use DigiForge\Database\Tables;
/** Settings live in a private custom table; secrets are stored only as opaque values and never returned by public APIs. */
final class Settings {
    public static function ensure_defaults(): void { foreach (Config::default_settings() as $key => $value) { if (self::get($key, null) === null) { self::set($key, $value, 'boolean'); } } }
    public static function get(string $key, mixed $default = null): mixed { if ($key === 'cleanup_on_uninstall') { return (bool) get_option('digiforge_cleanup_on_uninstall', false); } global $wpdb; $value = $wpdb->get_var($wpdb->prepare('SELECT setting_value FROM ' . Tables::settings() . ' WHERE setting_key = %s', $key)); return $value === null ? $default : self::decode((string) $value); }
    public static function set(string $key, mixed $value, string $type = 'string'): bool { if ($key === 'cleanup_on_uninstall') { return update_option('digiforge_cleanup_on_uninstall', (bool) $value, false); } global $wpdb; $encoded = wp_json_encode($value); return false !== $wpdb->query($wpdb->prepare('INSERT INTO ' . Tables::settings() . ' (setting_key, setting_value, setting_type, updated_at) VALUES (%s,%s,%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_type=VALUES(setting_type), updated_at=UTC_TIMESTAMP()', $key, $encoded, $type)); }
    public static function is_enabled(string $switch): bool { return ! (bool) self::get('stop_all', false) && (bool) self::get($switch, false); }
    public static function secrets(): array { return []; } // Reserved secure accessor; credentials are never exposed through REST/UI.
    private static function decode(string $value): mixed { $decoded = json_decode($value, true); return json_last_error() === JSON_ERROR_NONE ? $decoded : $value; }
}
