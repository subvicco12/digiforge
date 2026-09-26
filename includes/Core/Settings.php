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
        // Legacy global release is fail-closed: capability authorization must use scoped paths.
        return false;
    }

    public static function activateResearch(): bool
    {
        global $wpdb;
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Tables::settings()
        ));
        if (! is_string($engine) || ! in_array(strtoupper($engine), ['INNODB', 'XTRADB'], true)) { return false; }
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            foreach (['activation_authorized' => true, 'automation_armed' => true, 'research_activation_authorized' => true, 'stop_all' => false] as $key => $value) {
                if (! self::persist($key, $value)) { throw new \RuntimeException('activation persistence failed'); }
            }
            if (false === $wpdb->query('COMMIT')) { throw new \RuntimeException('activation commit failed'); }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    public static function activateAi(): bool
    {
        global $wpdb;
        if (self::get('research_activation_authorized', false) !== true || ! self::is_enabled('research')) { return false; }
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Tables::settings()
        ));
        if (! is_string($engine) || ! in_array(strtoupper($engine), ['INNODB', 'XTRADB'], true)) { return false; }
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            if (! self::persist('ai_activation_authorized', true)) { throw new \RuntimeException('AI activation persistence failed'); }
            if (false === $wpdb->query('COMMIT')) { throw new \RuntimeException('AI activation commit failed'); }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    public static function activateProductDevelopment(): bool
    {
        global $wpdb;
        if (self::get('ai_activation_authorized', false) !== true || ! self::is_enabled('ai')) { return false; }
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Tables::settings()
        ));
        if (! is_string($engine) || ! in_array(strtoupper($engine), ['INNODB', 'XTRADB'], true)) { return false; }
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            if (! self::persist('product_development_activation_authorized', true)) { throw new \RuntimeException('Product Development activation persistence failed'); }
            if (false === $wpdb->query('COMMIT')) { throw new \RuntimeException('activation commit failed'); }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    public static function activateEtsyDraft(): bool
    {
        global $wpdb;
        if (self::get('product_development_activation_authorized', false) !== true || ! self::is_enabled('product_development')) { return false; }
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Tables::settings()
        ));
        if (! is_string($engine) || ! in_array(strtoupper($engine), ['INNODB', 'XTRADB'], true)) { return false; }
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            if (! self::persist('etsy_draft_activation_authorized', true)) { throw new \RuntimeException('Etsy Draft activation persistence failed'); }
            if (false === $wpdb->query('COMMIT')) { throw new \RuntimeException('activation commit failed'); }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    public static function activatePrintify(): bool
    {
        global $wpdb;
        if (self::get('product_development_activation_authorized', false) !== true || ! self::is_enabled('product_development')) { return false; }
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Tables::settings()
        ));
        if (! is_string($engine) || ! in_array(strtoupper($engine), ['INNODB', 'XTRADB'], true)) { return false; }
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            if (! self::persist('printify_activation_authorized', true)) { throw new \RuntimeException('Printify activation persistence failed'); }
            if (false === $wpdb->query('COMMIT')) { throw new \RuntimeException('activation commit failed'); }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    public static function activateEtsyPublish(): bool
    {
        return self::activateScoped('etsy_publish_activation_authorized', 'etsy_draft_activation_authorized', 'etsy_draft');
    }

    public static function activateOrderAutomation(): bool
    {
        return self::activateScoped('order_automation_activation_authorized', 'printify_activation_authorized', 'printify');
    }

    public static function activateGstAutomation(): bool
    {
        return self::activateScoped('gst_automation_activation_authorized', 'order_automation_activation_authorized', 'order_automation');
    }

    private static function activateScoped(string $gate, string $prerequisiteGate, string $prerequisiteSwitch): bool
    {
        global $wpdb;
        if (self::get($prerequisiteGate, false) !== true || ! self::is_enabled($prerequisiteSwitch)) { return false; }
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Tables::settings()
        ));
        if (! is_string($engine) || ! in_array(strtoupper($engine), ['INNODB', 'XTRADB'], true)) { return false; }
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            if (! self::persist($gate, true)) { throw new \RuntimeException('scoped activation persistence failed'); }
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
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Tables::settings()
        ));
        if (! is_string($engine) || ! in_array(strtoupper($engine), ['INNODB', 'XTRADB'], true)) { return false; }
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            foreach (['stop_all' => true, 'automation_armed' => false, 'activation_authorized' => false, 'research_activation_authorized' => false, 'ai_activation_authorized' => false, 'product_development_activation_authorized' => false, 'etsy_draft_activation_authorized' => false, 'printify_activation_authorized' => false, 'etsy_publish_activation_authorized' => false, 'order_automation_activation_authorized' => false, 'gst_automation_activation_authorized' => false] as $key => $value) {
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
            && self::get($switch, false) === true
            && ($switch !== 'research' || self::get('research_activation_authorized', false) === true)
            && ($switch !== 'ai' || self::get('ai_activation_authorized', false) === true)
            && ($switch !== 'product_development' || self::get('product_development_activation_authorized', false) === true)
            && ($switch !== 'etsy_draft' || self::get('etsy_draft_activation_authorized', false) === true)
            && ($switch !== 'printify' || self::get('printify_activation_authorized', false) === true)
            && ($switch !== 'etsy_publish' || self::get('etsy_publish_activation_authorized', false) === true)
            && ($switch !== 'order_automation' || self::get('order_automation_activation_authorized', false) === true)
            && ($switch !== 'gst_automation' || self::get('gst_automation_activation_authorized', false) === true)
            && in_array($switch, ['research', 'ai', 'product_development', 'etsy_draft', 'printify', 'etsy_publish', 'order_automation', 'gst_automation'], true);
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
