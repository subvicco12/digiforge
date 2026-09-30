<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/** Insert-only dispatch identities in the unique WordPress option-name boundary. */
final class RecoveryDispatchLedger
{
    public static function insert(string $name, array $record): bool
    {
        global $wpdb;
        $database = self::database();
        if ($database instanceof \WP_Error) { return false; }
        // add_option uses an UPSERT in WordPress and cannot implement a one-winner claim.
        // Plain INSERT must fail on duplicate option_name, even with stale object caches.
        $previous = $database->suppress_errors(true);
        try {
            $written = $database->insert($wpdb->options, ['option_name' => $name, 'option_value' => maybe_serialize($record), 'autoload' => 'no'], ['%s', '%s', '%s']);
        } finally { $database->suppress_errors($previous); }
        self::invalidate($name);
        return $written === 1 && self::read($name) === $record;
    }

    /** Uncached authoritative lookup: read failure never means missing claim. */
    public static function read(string $name): array|\WP_Error
    {
        global $wpdb;
        $database = self::database();
        if ($database instanceof \WP_Error) { return $database; }
        $database->last_error = '';
        $row = $database->get_row($database->prepare('SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s LIMIT 1', $name), ARRAY_A);
        if ($database->last_error !== '') {
            return new \WP_Error('digiforge_recovery_ledger_unavailable', __('Recovery dispatch persistence cannot be verified. Reconciliation is required.', 'digiforge'), ['status' => 503, 'reconciliation_required' => true]);
        }
        if ($row === null) { return []; }
        $record = maybe_unserialize($row['option_value']);
        if (! is_array($record) || $record === []) {
            return new \WP_Error('digiforge_recovery_ledger_invalid', __('Recovery dispatch identity is unreadable. Reconciliation is required.', 'digiforge'), ['status' => 503, 'reconciliation_required' => true]);
        }
        return $record;
    }

    /** Atomic compare-and-swap; used only to advance the global slot from a terminal supersession marker. */
    public static function compareAndSwap(string $name, array $expected, array $record): bool
    {
        global $wpdb;
        $database = self::database();
        if ($database instanceof \WP_Error) { return false; }
        $written = $database->update(
            $wpdb->options,
            ['option_value' => maybe_serialize($record)],
            ['option_name' => $name, 'option_value' => maybe_serialize($expected)],
            ['%s'],
            ['%s','%s']
        );
        self::invalidate($name);
        return $written === 1 && self::read($name) === $record;
    }

    public static function update(string $name, array $record): bool
    {
        global $wpdb;
        $database = self::database();
        if ($database instanceof \WP_Error) { return false; }
        $written = $database->update($wpdb->options, ['option_value' => maybe_serialize($record)], ['option_name' => $name], ['%s'], ['%s']);
        self::invalidate($name);
        return $written !== false && self::read($name) === $record;
    }

    /** Committed safety values cannot be supplied by an uncommitted caller snapshot. */
    public static function safetyLocked(): bool
    {
        $database = self::database();
        if ($database instanceof \WP_Error) { return false; }
        foreach (['stop_all' => true, 'activation_authorized' => false, 'automation_armed' => false] as $key => $expected) {
            $row = $database->get_row($database->prepare('SELECT setting_value, setting_type FROM ' . \DigiForge\Database\Tables::settings() . ' WHERE setting_key = %s', $key), ARRAY_A);
            if (! is_array($row) || ($row['setting_type'] ?? '') !== 'boolean'
                || json_decode((string) ($row['setting_value'] ?? ''), true) !== $expected) { return false; }
        }
        return true;
    }

    public static function artifactsMatch(string $hash): bool
    {
        $backup = self::read('digiforge_recovery_database_backup_evidence');
        $package = self::read('digiforge_recovery_plugin_package_evidence');
        return is_array($backup) && $backup !== [] && is_array($package) && $package !== []
            && RecoveryOrchestrator::artifactEvidenceHash(['database_backup' => $backup, 'plugin_package' => $package]) === $hash;
    }

    public static function connectorMatches(array $connection): bool
    {
        $database = self::database();
        if ($database instanceof \WP_Error) { return false; }
        $rows = $database->get_results($database->prepare('SELECT id, provider, status, enabled, config FROM ' . \DigiForge\Database\Tables::integrations() . ' WHERE provider = %s AND status = %s AND enabled = %d ORDER BY id ASC LIMIT 2', 'hostinger', 'CONFIGURED', 1), ARRAY_A);
        $row = is_array($rows) && count($rows) === 1 ? $rows[0] : null;
        return is_array($row) && (int) $row['id'] === (int) ($connection['id'] ?? 0) && $row['provider'] === 'hostinger' && $row['status'] === 'CONFIGURED'
            && (int) $row['enabled'] === 1 && json_decode((string) $row['config'], true) === ($connection['config'] ?? null);
    }

    public static function ciphertext(int $id, string $name): string|\WP_Error
    {
        $database = self::database();
        if ($database instanceof \WP_Error) { return $database; }
        $row = $database->get_row($database->prepare('SELECT ciphertext FROM ' . \DigiForge\Database\Tables::integration_secrets() . ' WHERE integration_id = %d AND secret_name = %s LIMIT 1', $id, $name), ARRAY_A);
        return is_array($row) && is_string($row['ciphertext'] ?? null) ? $row['ciphertext'] : self::unavailable();
    }

    /** A separate autocommit session never commits or rolls back the caller's work. */
    private static function database(): \wpdb|\WP_Error
    {
        global $wpdb;
        static $database = null;
        if (! $database instanceof \wpdb) {
            if (! defined('DB_USER') || ! defined('DB_PASSWORD') || ! defined('DB_NAME') || ! defined('DB_HOST')) {
                return self::unavailable();
            }
            $database = new class(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends \wpdb {
                public function db_connect($allow_bail = true) {
                    $this->suppress_errors(true);
                    $this->hide_errors();
                    set_error_handler(static fn() => true);
                    try {
                        if (! parent::db_connect(false)) { return false; }
                        return $this->query('SET SESSION autocommit = 1') !== false
                            && (string) $this->get_var('SELECT @@session.autocommit') === '1'
                            && $this->get_var('SELECT DATABASE()') === DB_NAME;
                    }
                    finally { restore_error_handler(); }
                }
                public function show_errors($show = true) { return parent::show_errors(false); }
            };
            if (! $database->ready || $database->query('SET SESSION autocommit = 1') === false
                || (string) $database->get_var('SELECT @@session.autocommit') !== '1'
                || $database->get_var('SELECT DATABASE()') !== DB_NAME) {
                $database = null;
                return self::unavailable();
            }
        }
        // Verify every use; the override also initializes every transparent reconnect.
        if (! $database->ready || (string) $database->get_var('SELECT @@session.autocommit') !== '1'
            || $database->get_var('SELECT DATABASE()') !== DB_NAME) { return self::unavailable(); }
        return $database;
    }

    private static function unavailable(): \WP_Error
    {
        return new \WP_Error('digiforge_recovery_ledger_unavailable', __('Durable recovery persistence is unavailable. No dispatch is permitted.', 'digiforge'), ['status' => 503, 'reconciliation_required' => true]);
    }

    private static function invalidate(string $name): void
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }
}
