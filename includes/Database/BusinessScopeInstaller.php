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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        foreach (BusinessScopeSchema::statements($charset) as $statement) {
            $wpdb->last_error = '';
            dbDelta($statement);
            if ($wpdb->last_error !== '') {
                return self::fail('BUSINESS_SCOPE_SCHEMA_UPDATE_FAILED');
            }
        }

        foreach ($tables as $table) {
            $present = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($present !== $table) {
                return self::fail('BUSINESS_SCOPE_SCHEMA_VERIFY_FAILED');
            }
        }

        // Table presence is insufficient: ownership safety depends on the
        // recovered columns and unique indexes being present after dbDelta.
        $requiredColumns = [
            Tables::businesses() => ['id','business_key','status'],
            Tables::stores() => ['id','business_id','store_key','status'],
            Tables::product_programs() => ['id','business_id','store_id','program_key','status'],
            Tables::pod_business_mappings() => ['id','business_id','store_id','product_program_id','product_version_id','provider_mapping_id','idempotency_key'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            $actual = $wpdb->get_col('SHOW COLUMNS FROM ' . $table, 0);
            foreach ($columns as $column) {
                if (!in_array($column, $actual, true)) {
                    return self::fail('BUSINESS_SCOPE_SCHEMA_COLUMN_VERIFY_FAILED');
                }
            }
        }

        $indexes = $wpdb->get_results('SHOW INDEX FROM ' . Tables::pod_business_mappings(), ARRAY_A);
        $unique = [];
        foreach ((array) $indexes as $index) {
            if ((int) ($index['Non_unique'] ?? 1) === 0) {
                $name = (string) ($index['Key_name'] ?? '');
                $seq = (int) ($index['Seq_in_index'] ?? 0);
                if ($name !== '' && $seq > 0) {
                    $unique[$name][$seq] = (string) ($index['Column_name'] ?? '');
                }
            }
        }
        foreach ($unique as &$columns) {
            ksort($columns);
            $columns = array_values($columns);
        }
        unset($columns);
        if (($unique['product_provider_owner'] ?? null) !== ['product_version_id','provider_mapping_id'] ||
            ($unique['idempotency_key'] ?? null) !== ['idempotency_key']) {
            return self::fail('BUSINESS_SCOPE_SCHEMA_INDEX_VERIFY_FAILED');
        }

        update_option('digiforge_db_schema_version', 14, false);
        delete_option('digiforge_last_migration_failure');
        return true;
    }

    private static function fail(string $code): bool
    {
        update_option('digiforge_last_migration_failure', [
            'error_code' => $code,
            'occurred_at' => current_time('mysql', true),
        ], false);
        return false;
    }
}
