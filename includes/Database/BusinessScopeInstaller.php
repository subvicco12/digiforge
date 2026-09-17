<?php

declare(strict_types=1);

namespace DigiForge\Database;

/** Installs and reconciles v14 ownership tables without activating commerce. */
final class BusinessScopeInstaller
{
    public static function migrateIfNeeded(): bool
    {
        global $wpdb;

        $currentVersion = (int) get_option('digiforge_db_schema_version', 0);
        if ($currentVersion < 13) {
            return false;
        }

        $tables = [
            Tables::businesses(),
            Tables::stores(),
            Tables::product_programs(),
            Tables::pod_business_mappings(),
        ];

        // Never trust only the stored version or table existence. dbDelta is
        // intentionally rerun so a partial v14 table/index installation is
        // reconciled before the ownership boundary is considered healthy.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        foreach (BusinessScopeSchema::statements($charset) as $statement) {
            $wpdb->last_error = '';
            dbDelta($statement);
            if ($wpdb->last_error !== '') {
                update_option('digiforge_last_migration_failure', [
                    'error_code' => 'BUSINESS_SCOPE_SCHEMA_UPDATE_FAILED',
                    'occurred_at' => current_time('mysql', true),
                ], false);
                return false;
            }
        }

        foreach ($tables as $table) {
            $present = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($present !== $table) {
                update_option('digiforge_last_migration_failure', [
                    'error_code' => 'BUSINESS_SCOPE_SCHEMA_VERIFY_FAILED',
                    'occurred_at' => current_time('mysql', true),
                ], false);
                return false;
            }
        }

        update_option('digiforge_db_schema_version', 14, false);
        delete_option('digiforge_last_migration_failure');
        return true;
    }
}
