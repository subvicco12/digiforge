<?php

declare(strict_types=1);

namespace DigiForge\Database;

final class ListingSchema
{
    public static function migrateIfNeeded(): bool
    {
        global $wpdb;
        $currentVersion = (int) get_option('digiforge_db_schema_version', 0);
        if ($currentVersion >= 11) {
            self::grantCapability();
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        foreach (self::statements($charset) as $statement) {
            $wpdb->last_error = '';
            dbDelta($statement);
            if ($wpdb->last_error !== '') {
                update_option('digiforge_last_migration_failure', [
                    'error_code' => 'LISTING_SCHEMA_UPDATE_FAILED',
                    'occurred_at' => current_time('mysql', true),
                ], false);
                return false;
            }
        }

        if ($currentVersion === 10) {
            update_option('digiforge_db_schema_version', 11, false);
        }
        self::grantCapability();
        return true;
    }

    /** @return list<string> */
    public static function statements(string $charset): array
    {
        return [
            "CREATE TABLE " . Tables::listings() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_version_id bigint(20) unsigned NOT NULL,
  channel varchar(32) NOT NULL DEFAULT 'etsy',
  environment varchar(20) NOT NULL,
  shop_reference varchar(191) NOT NULL DEFAULT '',
  title varchar(191) NOT NULL,
  description longtext NULL,
  taxonomy_metadata longtext NULL,
  price_amount decimal(12,4) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'USD',
  quantity_policy longtext NULL,
  personalization_enabled tinyint(1) NOT NULL DEFAULT 0,
  state varchar(32) NOT NULL DEFAULT 'DRAFT',
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY product_state (product_version_id,state),
  KEY channel_environment (channel,environment)
) $charset;",
            "CREATE TABLE " . Tables::listing_seo() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  tags longtext NULL,
  keywords longtext NULL,
  materials longtext NULL,
  attributes longtext NULL,
  audience_metadata longtext NULL,
  evidence longtext NULL,
  canonical_hash char(64) NOT NULL DEFAULT '',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY listing_id (listing_id)
) $charset;",
            "CREATE TABLE " . Tables::listing_media() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  asset_revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
  release_bundle_id bigint(20) unsigned NOT NULL DEFAULT 0,
  media_role varchar(32) NOT NULL DEFAULT 'image',
  position_index smallint unsigned NOT NULL DEFAULT 0,
  state varchar(20) NOT NULL DEFAULT 'BOUND',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY listing_position (listing_id,position_index)
) $charset;",
            "CREATE TABLE " . Tables::listing_pod_bindings() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  provider_mapping_id bigint(20) unsigned NOT NULL,
  personalization_schema_id bigint(20) unsigned NOT NULL DEFAULT 0,
  readiness_hash char(64) NOT NULL DEFAULT '',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY listing_mapping (listing_id,provider_mapping_id),
  UNIQUE KEY idempotency_key (idempotency_key)
) $charset;",
            "CREATE TABLE " . Tables::etsy_draft_packages() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  package_version varchar(64) NOT NULL,
  canonical_payload longtext NOT NULL,
  payload_hash char(64) NOT NULL,
  readiness longtext NOT NULL,
  readiness_hash char(64) NOT NULL,
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY listing_package (listing_id,package_version),
  UNIQUE KEY payload_hash (payload_hash),
  UNIQUE KEY idempotency_key (idempotency_key)
) $charset;",
            "CREATE TABLE " . Tables::etsy_intents() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  draft_package_id bigint(20) unsigned NOT NULL DEFAULT 0,
  environment varchar(20) NOT NULL,
  intent_type varchar(64) NOT NULL,
  input_payload longtext NULL,
  state varchar(32) NOT NULL DEFAULT 'BLOCKED',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY listing_state (listing_id,state),
  KEY environment_state (environment,state)
) $charset;",
            "CREATE TABLE " . Tables::listing_readiness_reviews() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  readiness longtext NOT NULL,
  readiness_hash char(64) NOT NULL,
  decision varchar(20) NOT NULL DEFAULT 'PENDING',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY listing_decision (listing_id,decision),
  KEY readiness_hash (readiness_hash)
) $charset;",
        ];
    }

    private static function grantCapability(): void
    {
        if ($role = get_role('administrator')) {
            $role->add_cap('manage_digiforge_listings');
        }
    }
}
