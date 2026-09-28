<?php
declare(strict_types=1);
namespace DigiForge\Database;
/** Additive v23 durable scoped capability-policy evidence. Policy rows never grant external authority by themselves. */
final class ScopedPolicySchema {
 public const VERSION=23;
 public static function migrateIfNeeded():bool {
  global $wpdb;if((int)get_option('digiforge_db_schema_version',0)>=self::VERSION)return true;require_once ABSPATH.'wp-admin/includes/upgrade.php';$charset=$wpdb->get_charset_collate();
  $sql="CREATE TABLE ".Tables::scoped_capability_policies()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shop_key varchar(100) NOT NULL,
  workflow_key varchar(100) NOT NULL,
  capability varchar(64) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  policy_version int unsigned NOT NULL,
  policy_hash char(64) NOT NULL,
  previous_policy_hash char(64) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY scope_version (shop_key,workflow_key,capability,policy_version),
  UNIQUE KEY policy_hash (policy_hash),
  KEY scope_latest (shop_key(40),workflow_key(40),capability(32),created_at)
) $charset;";
  $wpdb->last_error='';$created=$wpdb->query($sql);$exists=$wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',Tables::scoped_capability_policies()));if($exists!==Tables::scoped_capability_policies()){update_option('digiforge_last_migration_failure',['error_code'=>'SCOPED_POLICY_SCHEMA_UPDATE_FAILED','database_error'=>(string)$wpdb->last_error,'table_name'=>Tables::scoped_capability_policies(),'create_result'=>$created,'observed_table'=>$exists,'occurred_at'=>current_time('mysql',true)],false);return false;}update_option('digiforge_db_schema_version',self::VERSION,false);update_option('digiforge_db_version',(string)self::VERSION,false);return true;
 }
}