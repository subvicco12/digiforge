<?php

declare(strict_types=1);

namespace DigiForge\Database;

final class OrderSchema
{
    public static function migrateIfNeeded(): bool
    {
        global $wpdb;
        $currentVersion = (int) get_option('digiforge_db_schema_version', 0);
        if ($currentVersion >= 15) {
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
                    'error_code' => 'ORDER_SCHEMA_UPDATE_FAILED',
                    'occurred_at' => current_time('mysql', true),
                ], false);
                return false;
            }
        }

        // Preserve the historical 11 -> 12 handoff so FinanceSchema can run.
        // Schema 15 finalization remains owned by the coordinated migration chain.
        if ($currentVersion === 11) {
            update_option('digiforge_db_schema_version', 12, false);
        }
        self::grantCapability();
        return true;
    }

    /** @return list<string> */
    public static function statements(string $charset): array
    {
        return [
            "CREATE TABLE " . Tables::orders() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel varchar(32) NOT NULL DEFAULT 'etsy',
  environment varchar(20) NOT NULL,
  external_order_reference varchar(191) NOT NULL DEFAULT '',
  shop_reference varchar(191) NOT NULL DEFAULT '',
  buyer_reference varchar(191) NOT NULL DEFAULT '',
  currency char(3) NOT NULL DEFAULT 'USD',
  subtotal_amount decimal(12,4) NOT NULL DEFAULT 0,
  shipping_amount decimal(12,4) NOT NULL DEFAULT 0,
  tax_amount decimal(12,4) NOT NULL DEFAULT 0,
  total_amount decimal(12,4) NOT NULL DEFAULT 0,
  personalization_required tinyint(1) NOT NULL DEFAULT 0,
  state varchar(32) NOT NULL DEFAULT 'RECEIVED',
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY channel_environment (channel,environment),
  KEY external_order_reference (external_order_reference),
  KEY state (state)
) $charset;",
            "CREATE TABLE " . Tables::order_line_items() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_version_id bigint(20) unsigned NOT NULL,
  provider_mapping_id bigint(20) unsigned NOT NULL DEFAULT 0,
  quantity int unsigned NOT NULL DEFAULT 1,
  unit_price_amount decimal(12,4) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'USD',
  personalization_payload longtext NULL,
  environment varchar(20) NOT NULL,
  validation_status varchar(32) NOT NULL DEFAULT 'PENDING',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY order_id (order_id),
  KEY product_version_id (product_version_id),
  KEY environment (environment)
) $charset;",
            "CREATE TABLE " . Tables::personalization_submissions() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_line_item_id bigint(20) unsigned NOT NULL,
  personalization_schema_id bigint(20) unsigned NOT NULL,
  canonical_payload longtext NOT NULL,
  payload_hash char(64) NOT NULL,
  review_status varchar(32) NOT NULL DEFAULT 'UNREVIEWED',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  environment varchar(20) NOT NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY line_schema_hash (order_line_item_id,personalization_schema_id,payload_hash),
  KEY environment (environment)
) $charset;",
            "CREATE TABLE " . Tables::fulfillment_plans() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  plan_version varchar(64) NOT NULL,
  provider varchar(64) NOT NULL DEFAULT '',
  provider_mapping_snapshot longtext NULL,
  print_area_snapshot longtext NULL,
  personalization_snapshot longtext NULL,
  shipping_method_metadata longtext NULL,
  cost_snapshot_metadata longtext NULL,
  canonical_payload longtext NOT NULL,
  payload_hash char(64) NOT NULL,
  readiness longtext NOT NULL,
  readiness_hash char(64) NOT NULL,
  state varchar(32) NOT NULL DEFAULT 'DRAFT',
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  environment varchar(20) NOT NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_plan (order_id,plan_version),
  UNIQUE KEY payload_hash (payload_hash),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY order_state (order_id,state),
  KEY environment (environment)
) $charset;",
            "CREATE TABLE " . Tables::fulfillment_intents() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  fulfillment_plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
  fulfillment_plan_payload_hash char(64) NOT NULL DEFAULT '',
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
  KEY order_state (order_id,state),
  KEY environment_state (environment,state)
) $charset;",
            "CREATE TABLE " . Tables::fulfillment_readiness_reviews() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  fulfillment_plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
  KEY order_decision (order_id,decision),
  KEY readiness_hash (readiness_hash)
) $charset;",
        ];
    }

    private static function grantCapability(): void
    {
        if ($role = get_role('administrator')) {
            $role->add_cap('manage_digiforge_orders');
        }
    }
}
