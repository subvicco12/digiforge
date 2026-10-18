<?php
declare(strict_types=1);
namespace DigiForge\Database;

/** DF-OWN-001 additive schema v1. No approval backfill or global version rewind. */
final class ProviderConnectionSchema
{
 public static function table():string { global $wpdb;return $wpdb->prefix.'digiforge_provider_connections'; }
 public static function migrateIfNeeded():bool {
  global $wpdb;$table=self::table();
  $probe=static function()use($wpdb,$table):bool {
   $previous=$wpdb->suppress_errors(true);
   $ok=$wpdb->query("SELECT id,binding_hash,review_key,integration_id,business_id,store_id,program_id,shop_key,provider,capability,payload,created_at FROM {$table} LIMIT 0")!==false;
   $wpdb->suppress_errors($previous);return $ok;
  };
  if($probe() && get_option('digiforge_provider_connection_schema')==='1')return true;
  require_once ABSPATH.'wp-admin/includes/upgrade.php';$charset=$wpdb->get_charset_collate();
  $sql="CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  binding_hash char(64) NOT NULL,
  review_key char(64) NOT NULL,
  integration_id bigint(20) unsigned NOT NULL,
  business_id bigint(20) unsigned NOT NULL,
  store_id bigint(20) unsigned NOT NULL,
  program_id bigint(20) unsigned NOT NULL,
  shop_key varchar(32) NOT NULL,
  provider varchar(32) NOT NULL,
  capability varchar(64) NOT NULL,
  payload longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY binding_hash (binding_hash),
  KEY scoped_connection (shop_key,integration_id,capability),
  KEY review_key (review_key)
) ENGINE=InnoDB {$charset};";
  $wpdb->last_error='';dbDelta($sql);
  if(self::databaseFailed() || !$probe())return self::failure();
  update_option('digiforge_provider_connection_schema','1',false);
  if(get_option('digiforge_provider_connection_schema')!=='1')return self::failure();
  return true;
 }
 /** @phpstan-impure */
 private static function databaseFailed():bool {global $wpdb;return $wpdb->last_error!=='';}
 private static function failure():bool {
  update_option('digiforge_last_migration_failure',['error_code'=>'PROVIDER_CONNECTION_SCHEMA_FAILED','occurred_at'=>gmdate('c')],false);return false;
 }
}
