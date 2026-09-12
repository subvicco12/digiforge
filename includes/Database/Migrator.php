<?php
declare(strict_types=1);
namespace DigiForge\Database;
use DigiForge\Core\Capabilities;
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
        // The audit table is stable across schema v1-v6. Re-running dbDelta against the existing
        // AUTO_INCREMENT primary key can make WordPress attempt an invalid empty-string default on MariaDB.
        // Skip only that unchanged table when it already exists; fresh installs still create it below.
        $existing_audit_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Tables::audit_log())));
        $sql = [
            'CREATE TABLE ' . Tables::settings() . " (setting_key varchar(191) NOT NULL, setting_value longtext NOT NULL, setting_type varchar(32) NOT NULL DEFAULT 'string', updated_at datetime NOT NULL, PRIMARY KEY  (setting_key), KEY updated_at (updated_at)) $charset;",
            'CREATE TABLE ' . Tables::audit_log() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, event_type varchar(100) NOT NULL, actor_id bigint(20) unsigned NOT NULL DEFAULT 0, object_type varchar(100) NOT NULL DEFAULT '', object_id varchar(191) NOT NULL DEFAULT '', context longtext NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY event_created (event_type,created_at), KEY object_lookup (object_type,object_id)) $charset;",
            'CREATE TABLE ' . Tables::jobs() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, job_type varchar(100) NOT NULL, state varchar(20) NOT NULL, payload longtext NULL, idempotency_key varchar(191) NULL DEFAULT NULL, scheduled_at datetime NULL, next_attempt_at datetime NULL, locked_by varchar(100) NULL, locked_at datetime NULL, lease_expires_at datetime NULL, completed_at datetime NULL, dead_lettered_at datetime NULL, attempts smallint unsigned NOT NULL DEFAULT 0, max_attempts smallint unsigned NOT NULL DEFAULT 3, last_error varchar(255) NOT NULL DEFAULT '', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY state_schedule (state,scheduled_at), KEY retry_schedule (state,next_attempt_at), KEY lease_expiry (state,lease_expires_at), KEY type_state (job_type,state), UNIQUE KEY idempotency_key (idempotency_key)) $charset;",
            'CREATE TABLE ' . Tables::idempotency() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, operation_key varchar(191) NOT NULL, operation_type varchar(100) NOT NULL, status varchar(20) NOT NULL, response_hash char(64) NOT NULL DEFAULT '', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY operation_key (operation_key), KEY type_status (operation_type,status)) $charset;",
            'CREATE TABLE ' . Tables::health_events() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, component varchar(100) NOT NULL, status varchar(20) NOT NULL, error_code varchar(100) NOT NULL DEFAULT '', details longtext NULL, observed_at datetime NOT NULL, PRIMARY KEY  (id), KEY component_observed (component,observed_at), KEY status_observed (status,observed_at)) $charset;",
            'CREATE TABLE ' . Tables::opportunities() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, title varchar(191) NOT NULL, description longtext NULL, state varchar(20) NOT NULL DEFAULT 'NEW', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY state_updated (state,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::product_families() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, opportunity_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, description longtext NULL, state varchar(20) NOT NULL DEFAULT 'DRAFT', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY opportunity_state (opportunity_id,state)) $charset;",
            'CREATE TABLE ' . Tables::products() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_family_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, description longtext NULL, state varchar(20) NOT NULL DEFAULT 'DRAFT', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY family_state (product_family_id,state)) $charset;",
            'CREATE TABLE ' . Tables::product_versions() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_id bigint(20) unsigned NOT NULL, version_label varchar(100) NOT NULL, notes longtext NULL, state varchar(20) NOT NULL DEFAULT 'DRAFT', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), UNIQUE KEY product_version (product_id,version_label), KEY product_state (product_id,state)) $charset;",
            'CREATE TABLE ' . Tables::digital_products() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_id bigint(20) unsigned NOT NULL, product_version_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, category varchar(100) NOT NULL, state varchar(32) NOT NULL DEFAULT 'DRAFT', readiness longtext NULL, idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY product_version (product_version_id), UNIQUE KEY idempotency_key (idempotency_key), KEY product_category (product_id,category), KEY state_updated (state,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::digital_files() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, digital_product_id bigint(20) unsigned NOT NULL, product_version_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, file_type varchar(32) NOT NULL, mime_type varchar(100) NOT NULL DEFAULT '', storage_reference varchar(255) NOT NULL DEFAULT '', checksum_sha256 char(64) NOT NULL DEFAULT '', status varchar(32) NOT NULL DEFAULT 'PENDING', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY product_version (digital_product_id,product_version_id), KEY status_updated (status,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::digital_file_versions() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, digital_file_id bigint(20) unsigned NOT NULL, version_label varchar(100) NOT NULL, storage_reference varchar(255) NOT NULL DEFAULT '', checksum_sha256 char(64) NOT NULL DEFAULT '', byte_size bigint(20) unsigned NOT NULL DEFAULT 0, status varchar(32) NOT NULL DEFAULT 'PENDING', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY file_version (digital_file_id,version_label), UNIQUE KEY idempotency_key (idempotency_key), KEY file_status (digital_file_id,status)) $charset;",
            'CREATE TABLE ' . Tables::digital_packages() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, digital_product_id bigint(20) unsigned NOT NULL, product_version_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, manifest longtext NULL, checksum_sha256 char(64) NOT NULL DEFAULT '', byte_size bigint(20) unsigned NOT NULL DEFAULT 0, generation_status varchar(32) NOT NULL DEFAULT 'PENDING', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY product_version (digital_product_id,product_version_id), KEY generation_updated (generation_status,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::digital_previews() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, digital_product_id bigint(20) unsigned NOT NULL, digital_file_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, preview_reference varchar(255) NOT NULL DEFAULT '', status varchar(32) NOT NULL DEFAULT 'PENDING', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY product_file (digital_product_id,digital_file_id), KEY status_updated (status,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::digital_templates() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, digital_product_id bigint(20) unsigned NOT NULL, digital_file_id bigint(20) unsigned NOT NULL, digital_preview_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, template_reference varchar(255) NOT NULL DEFAULT '', access_instructions longtext NULL, status varchar(32) NOT NULL DEFAULT 'DRAFT', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY product_file (digital_product_id,digital_file_id), KEY preview_status (digital_preview_id,status)) $charset;",
            'CREATE TABLE ' . Tables::digital_licenses() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, digital_product_id bigint(20) unsigned NOT NULL, name varchar(191) NOT NULL, license_type varchar(64) NOT NULL, terms longtext NULL, license_code_hash char(64) NOT NULL DEFAULT '', status varchar(32) NOT NULL DEFAULT 'ACTIVE', idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY product_status (digital_product_id,status)) $charset;",
            'CREATE TABLE ' . Tables::digital_download_checks() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, digital_product_id bigint(20) unsigned NOT NULL, target_type varchar(32) NOT NULL, target_id bigint(20) unsigned NOT NULL, check_type varchar(64) NOT NULL, validation_result varchar(20) NOT NULL DEFAULT 'PENDING', failure_reason varchar(255) NOT NULL DEFAULT '', review_status varchar(20) NOT NULL DEFAULT 'UNREVIEWED', details longtext NULL, idempotency_key varchar(191) NULL DEFAULT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY product_result (digital_product_id,validation_result), KEY target_check (target_type,target_id,check_type), KEY review_updated (review_status,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::integrations() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, provider varchar(32) NOT NULL, connection_key varchar(100) NOT NULL, display_name varchar(191) NOT NULL, status varchar(20) NOT NULL DEFAULT 'DISCONNECTED', enabled tinyint(1) NOT NULL DEFAULT 0, config longtext NOT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY provider_connection (provider,connection_key), KEY provider_status (provider,status), KEY enabled_updated (enabled,updated_at)) $charset;",
            'CREATE TABLE ' . Tables::integration_secrets() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, integration_id bigint(20) unsigned NOT NULL, secret_name varchar(100) NOT NULL, ciphertext longtext NOT NULL, fingerprint char(16) NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY integration_secret (integration_id,secret_name), KEY integration_updated (integration_id,updated_at)) $charset;",
        ];
        $schemaFailed = false;
        foreach ($sql as $statement) {
            if ($existing_audit_table === Tables::audit_log() && str_starts_with($statement, 'CREATE TABLE ' . Tables::audit_log())) {
                continue;
            }
            $wpdb->last_error = '';
            dbDelta($statement);
            if ($wpdb->last_error !== '') {
                $schemaFailed = true;
                break;
            }
        }
        if ($schemaFailed) {
            update_option(
                'digiforge_last_migration_failure',
                ['error_code' => 'SCHEMA_UPDATE_FAILED', 'occurred_at' => current_time('mysql', true)],
                false
            );
            return;
        }

        $currentVersion = (int) get_option('digiforge_db_schema_version', 0);
        foreach (MigrationPlan::pending($currentVersion) as $version) {
            update_option('digiforge_db_schema_version', $version, false);
        }

        // Versioned upgrades grant only the capability introduced by the Digital Factory migration.
        Capabilities::addDigital();
        delete_option('digiforge_last_migration_failure');
        update_option('digiforge_db_version', DIGIFORGE_DB_VERSION, false);
    }
}
