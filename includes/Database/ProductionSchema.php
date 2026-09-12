<?php

declare(strict_types=1);

namespace DigiForge\Database;

/**
 * Batch 6 additive schema installer.
 *
 * Existing schema-v8 installations receive only Batch 6 tables. Fresh/older
 * installations may pre-create these tables before the core migrator creates
 * the rest of the current schema. No executable automation is introduced.
 */
final class ProductionSchema
{
    public static function migrateIfNeeded(): bool
    {
        global $wpdb;

        $currentVersion = (int) get_option('digiforge_db_schema_version', 0);
        if ($currentVersion >= 9) {
            self::grantCapability();
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = self::statements($charset);

        foreach ($sql as $statement) {
            $wpdb->last_error = '';
            dbDelta($statement);
            if ($wpdb->last_error !== '') {
                update_option('digiforge_last_migration_failure', [
                    'error_code' => 'PRODUCTION_SCHEMA_UPDATE_FAILED',
                    'occurred_at' => current_time('mysql', true),
                ], false);
                return false;
            }
        }

        // On an existing v8 installation this migration is the only required
        // schema delta. Advance to v9 here so the legacy migrator cannot rerun
        // stable Batch 5 tables. Fresh/older installs are advanced by Migrator.
        if ($currentVersion === 8) {
            update_option('digiforge_db_schema_version', 9, false);
        }

        self::grantCapability();
        return true;
    }

    /** @return list<string> */
    public static function statements(string $charset): array
    {
        return [
            "CREATE TABLE " . Tables::asset_specs() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_version_id bigint(20) unsigned NOT NULL,
  digital_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  asset_key varchar(100) NOT NULL,
  asset_type varchar(64) NOT NULL,
  purpose varchar(191) NOT NULL DEFAULT '',
  format varchar(32) NOT NULL,
  width_px int unsigned NOT NULL DEFAULT 0,
  height_px int unsigned NOT NULL DEFAULT 0,
  dpi smallint unsigned NOT NULL DEFAULT 0,
  color_space varchar(32) NOT NULL DEFAULT '',
  orientation varchar(32) NOT NULL DEFAULT '',
  variant_key varchar(100) NOT NULL DEFAULT '',
  locale varchar(20) NOT NULL DEFAULT '',
  content_requirements longtext NULL,
  design_constraints longtext NULL,
  source_policy varchar(64) NOT NULL DEFAULT 'local_only',
  state varchar(20) NOT NULL DEFAULT 'DRAFT',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY product_asset_variant (product_version_id,asset_key,variant_key,locale),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY product_state (product_version_id,state)
) $charset;",
            "CREATE TABLE " . Tables::production_plans() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_version_id bigint(20) unsigned NOT NULL,
  plan_key varchar(100) NOT NULL,
  version_label varchar(100) NOT NULL,
  channel varchar(20) NOT NULL,
  production_type varchar(64) NOT NULL,
  requirements_version varchar(64) NOT NULL DEFAULT 'v1',
  state varchar(20) NOT NULL DEFAULT 'DRAFT',
  notes longtext NULL,
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY plan_version (product_version_id,plan_key,version_label),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY product_state (product_version_id,state)
) $charset;",
            "CREATE TABLE " . Tables::production_plan_assets() . " (
  production_plan_id bigint(20) unsigned NOT NULL,
  asset_spec_id bigint(20) unsigned NOT NULL,
  is_required tinyint(1) NOT NULL DEFAULT 1,
  sequence_no smallint unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (production_plan_id,asset_spec_id),
  KEY asset_spec_id (asset_spec_id)
) $charset;",
            "CREATE TABLE " . Tables::production_intents() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  production_plan_id bigint(20) unsigned NOT NULL,
  asset_spec_id bigint(20) unsigned NOT NULL,
  intent_type varchar(32) NOT NULL,
  provider_class varchar(32) NOT NULL,
  requested_capability varchar(100) NOT NULL DEFAULT '',
  input_contract_version varchar(64) NOT NULL DEFAULT 'v1',
  input_payload longtext NULL,
  state varchar(32) NOT NULL DEFAULT 'BLOCKED',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY plan_state (production_plan_id,state),
  KEY asset_state (asset_spec_id,state)
) $charset;",
            "CREATE TABLE " . Tables::asset_revisions() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  asset_spec_id bigint(20) unsigned NOT NULL,
  revision_label varchar(100) NOT NULL,
  storage_reference varchar(255) NOT NULL DEFAULT '',
  checksum_sha256 char(64) NOT NULL,
  mime_type varchar(100) NOT NULL DEFAULT '',
  byte_size bigint(20) unsigned NOT NULL DEFAULT 0,
  width_px int unsigned NOT NULL DEFAULT 0,
  height_px int unsigned NOT NULL DEFAULT 0,
  provenance longtext NULL,
  state varchar(20) NOT NULL DEFAULT 'PENDING_QA',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY asset_revision (asset_spec_id,revision_label),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY asset_state (asset_spec_id,state)
) $charset;",
            "CREATE TABLE " . Tables::production_qa() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  target_type varchar(32) NOT NULL,
  target_id bigint(20) unsigned NOT NULL,
  check_type varchar(64) NOT NULL,
  check_version varchar(64) NOT NULL DEFAULT 'v1',
  status varchar(20) NOT NULL DEFAULT 'PENDING',
  details longtext NULL,
  waiver_reason varchar(255) NOT NULL DEFAULT '',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY target_check (target_type,target_id,check_type),
  KEY status_updated (status,updated_at)
) $charset;",
            "CREATE TABLE " . Tables::release_bundles() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  production_plan_id bigint(20) unsigned NOT NULL,
  bundle_key varchar(100) NOT NULL,
  version_label varchar(100) NOT NULL,
  manifest longtext NULL,
  checksum_sha256 char(64) NOT NULL DEFAULT '',
  state varchar(20) NOT NULL DEFAULT 'DRAFT',
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  readiness longtext NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY plan_bundle_version (production_plan_id,bundle_key,version_label),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY plan_state (production_plan_id,state)
) $charset;",
            "CREATE TABLE " . Tables::release_bundle_revisions() . " (
  release_bundle_id bigint(20) unsigned NOT NULL,
  asset_revision_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (release_bundle_id,asset_revision_id),
  KEY asset_revision_id (asset_revision_id)
) $charset;",
        ];
    }

    private static function grantCapability(): void
    {
        if ($role = get_role('administrator')) {
            $role->add_cap('manage_digiforge_production');
        }
    }
}
