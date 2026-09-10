<?php
declare(strict_types=1);
namespace DigiForge\Database;
/** Versioned, additive schema migrations. dbDelta is used for WordPress-compatible table updates. */
final class Migrator {
    public function maybe_migrate(): void { if ((string) get_option('digiforge_db_version', '0') !== DIGIFORGE_DB_VERSION) { $this->migrate(); } }
    public function migrate(): void {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $charset = $wpdb->get_charset_collate();
        // V2 converts the empty-string sentinel to NULL so jobs without an idempotency key can coexist.
        $existing_jobs_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Tables::jobs())));
        if ($existing_jobs_table === Tables::jobs() && (int) get_option('digiforge_db_schema_version', 0) < 2) {
            $wpdb->query('UPDATE ' . Tables::jobs() . " SET idempotency_key = NULL WHERE idempotency_key = ''");
        }
        $sql = [
            'CREATE TABLE ' . Tables::settings() . " (setting_key varchar(191) NOT NULL, setting_value longtext NOT NULL, setting_type varchar(32) NOT NULL DEFAULT 'string', updated_at datetime NOT NULL, PRIMARY KEY  (setting_key), KEY updated_at (updated_at)) $charset;",
            'CREATE TABLE ' . Tables::audit_log() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, event_type varchar(100) NOT NULL, actor_id bigint(20) unsigned NOT NULL DEFAULT 0, object_type varchar(100) NOT NULL DEFAULT '', object_id varchar(191) NOT NULL DEFAULT '', context longtext NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY event_created (event_type,created_at), KEY object_lookup (object_type,object_id)) $charset;",
            'CREATE TABLE ' . Tables::jobs() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, job_type varchar(100) NOT NULL, state varchar(20) NOT NULL, payload longtext NULL, idempotency_key varchar(191) NULL DEFAULT NULL, scheduled_at datetime NULL, locked_at datetime NULL, completed_at datetime NULL, attempts smallint unsigned NOT NULL DEFAULT 0, last_error varchar(255) NOT NULL DEFAULT '', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY state_schedule (state,scheduled_at), KEY type_state (job_type,state), UNIQUE KEY idempotency_key (idempotency_key)) $charset;",
            'CREATE TABLE ' . Tables::idempotency() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, operation_key varchar(191) NOT NULL, operation_type varchar(100) NOT NULL, status varchar(20) NOT NULL, response_hash char(64) NOT NULL DEFAULT '', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY operation_key (operation_key), KEY type_status (operation_type,status)) $charset;",
            'CREATE TABLE ' . Tables::opportunities() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, title varchar(191) NOT NULL, description longtext NULL, status varchar(20) NOT NULL DEFAULT 'NEW', source varchar(100) NOT NULL DEFAULT '', created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY status_updated (status,updated_at), KEY source_status (source,status)) $charset;",
            'CREATE TABLE ' . Tables::product_families() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, opportunity_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, description longtext NULL, status varchar(20) NOT NULL DEFAULT 'DRAFT', created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY opportunity_status (opportunity_id,status), KEY status_updated (status,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::products() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_family_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, sku varchar(100) NULL DEFAULT NULL, description longtext NULL, status varchar(20) NOT NULL DEFAULT 'DRAFT', created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY sku (sku), KEY family_status (product_family_id,status), KEY status_updated (status,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::product_versions() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_id bigint(20) unsigned NOT NULL, version_number int unsigned NOT NULL, name varchar(191) NOT NULL, status varchar(20) NOT NULL DEFAULT 'DRAFT', metadata longtext NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY product_version (product_id,version_number), KEY product_status (product_id,status), KEY status_updated (status,updated_at)) $charset;",
        ];
        foreach ($sql as $statement) { dbDelta($statement); }
        update_option('digiforge_db_schema_version', 3, false);
        update_option('digiforge_db_version', DIGIFORGE_DB_VERSION, false);
    }
}
