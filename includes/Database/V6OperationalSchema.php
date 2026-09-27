<?php
declare(strict_types=1);
namespace DigiForge\Database;

/** Additive v16 persistence for v6 governed catalog, shop AI policy/usage, and webhook evidence. */
final class V6OperationalSchema {
 public const VERSION=16;
 public static function migrateIfNeeded():bool{
  global $wpdb;
  if((int)get_option('digiforge_db_schema_version',0)>=self::VERSION)return true;
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  $charset=$wpdb->get_charset_collate();
  foreach(self::statements($charset) as $sql){$wpdb->last_error='';dbDelta($sql);if($wpdb->last_error!==''){update_option('digiforge_last_migration_failure',['error_code'=>'V6_OPERATIONAL_SCHEMA_UPDATE_FAILED','occurred_at'=>current_time('mysql',true)],false);return false;}}
  update_option('digiforge_db_schema_version',self::VERSION,false);
  return true;
 }
 /** @return list<string> */
 public static function statements(string $charset):array{return[
  "CREATE TABLE ".Tables::catalog_versions()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  catalog_key varchar(100) NOT NULL,
  version_label varchar(64) NOT NULL,
  source_sha256 char(64) NOT NULL,
  source_state varchar(32) NOT NULL DEFAULT 'IMMUTABLE_REFERENCE',
  parent_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
  migration_metadata longtext NULL,
  row_count int unsigned NOT NULL DEFAULT 0,
  fingerprint char(64) NOT NULL,
  production_authority tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY catalog_version (catalog_key,version_label),
  UNIQUE KEY fingerprint (fingerprint),
  KEY parent_version (parent_version_id)
) $charset;",
  "CREATE TABLE ".Tables::catalog_items()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  catalog_version_id bigint(20) unsigned NOT NULL,
  listing_id varchar(16) NOT NULL,
  family varchar(191) NOT NULL,
  concept varchar(255) NOT NULL,
  engine varchar(64) NOT NULL,
  physical_product varchar(191) NOT NULL,
  supplier_gate varchar(64) NOT NULL,
  template_state varchar(64) NOT NULL,
  attributes longtext NULL,
  row_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY version_listing (catalog_version_id,listing_id),
  KEY family_engine (family,engine)
) $charset;",
  "CREATE TABLE ".Tables::shop_ai_policies()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shop_key varchar(100) NOT NULL,
  environment varchar(20) NOT NULL DEFAULT 'production',
  currency char(3) NOT NULL,
  policy longtext NOT NULL,
  policy_hash char(64) NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'ACTIVE',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY shop_environment (shop_key,environment),
  KEY state_updated (state,updated_at)
) $charset;",
  "CREATE TABLE ".Tables::shop_ai_usage()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shop_key varchar(100) NOT NULL,
  workflow varchar(64) NOT NULL,
  stage varchar(64) NOT NULL,
  model_key varchar(100) NOT NULL DEFAULT '',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  quantity int unsigned NOT NULL DEFAULT 1,
  estimated_cost decimal(14,6) NOT NULL DEFAULT 0,
  actual_cost decimal(14,6) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL,
  occurred_at datetime NOT NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY shop_stage_time (shop_key,stage,occurred_at),
  KEY product_id (product_id),
  KEY order_id (order_id)
) $charset;",
  "CREATE TABLE ".Tables::webhook_evidence()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(32) NOT NULL,
  event_id varchar(191) NOT NULL,
  event_type varchar(100) NOT NULL DEFAULT '',
  shop_reference varchar(191) NOT NULL DEFAULT '',
  verification_status varchar(32) NOT NULL,
  body_sha256 char(64) NOT NULL,
  headers_evidence longtext NULL,
  validation_evidence longtext NULL,
  processing_status varchar(32) NOT NULL DEFAULT 'RECEIVED',
  result_hash char(64) NOT NULL DEFAULT '',
  received_at datetime NOT NULL,
  processed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_event (provider,event_id),
  KEY shop_status (shop_reference,processing_status),
  KEY received_at (received_at)
) $charset;"
 ];}
}