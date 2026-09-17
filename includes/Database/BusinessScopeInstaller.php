<?php

declare(strict_types=1);

namespace DigiForge\Database;

/** Installs v14 business/store/program ownership tables without activating commerce. */
final class BusinessScopeInstaller
{
    public static function migrateIfNeeded(): bool
    {
        global $wpdb;

        $currentVersion = (int) get_option('digiforge_db_schema_version', 0);
        if ($currentVersion >= 14) {
            return true;
        }
        if ($currentVersion !== 13) {
            return false;
        }

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

        update_option('digiforge_db_schema_version', 14, false);
        delete_option('digiforge_last_migration_failure');
        return true;
    }
}
