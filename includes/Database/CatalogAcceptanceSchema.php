<?php
declare(strict_types=1);
namespace DigiForge\Database;
/** Additive v24 immutable human acceptance evidence for governed catalog migration candidates. Never promotion authority. */
final class CatalogAcceptanceSchema {
 public const VERSION=24;
 public static function migrateIfNeeded():bool {
  global $wpdb;$table=Tables::catalog_acceptance_evidence();
  $exists=static function()use($wpdb,$table):bool{$s=$wpdb->suppress_errors(true);$ok=$wpdb->query('SELECT 1 FROM `'.esc_sql($table).'` LIMIT 0')!==false;$wpdb->suppress_errors($s);$wpdb->last_error='';return $ok;};
  if((int)get_option('digiforge_db_schema_version',0)>=self::VERSION&&$exists())return true;
  require_once ABSPATH.'wp-admin/includes/upgrade.php';$charset=$wpdb->get_charset_collate();
  $sql="CREATE TABLE ".Tables::catalog_acceptance_evidence()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  catalog_version_id bigint(20) unsigned NOT NULL,
  parent_version_id bigint(20) unsigned NOT NULL,
  source_sha256 char(64) NOT NULL,
  fingerprint char(64) NOT NULL,
  decision varchar(16) NOT NULL,
  reviewer_id bigint(20) unsigned NOT NULL,
  acknowledgement_hash char(64) NOT NULL,
  reviewed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY catalog_version (catalog_version_id),
  UNIQUE KEY acknowledgement_hash (acknowledgement_hash),
  KEY parent_version (parent_version_id),
  KEY reviewed_at (reviewed_at)
) $charset;";
  $wpdb->last_error='';$created=$wpdb->query(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$sql));$err=(string)$wpdb->last_error;
  if($created===false||!$exists()){update_option('digiforge_last_migration_failure',['error_code'=>'CATALOG_ACCEPTANCE_SCHEMA_UPDATE_FAILED','database_error'=>$err,'table_name'=>$table,'create_result'=>$created,'occurred_at'=>current_time('mysql',true)],false);return false;}
  update_option('digiforge_db_schema_version',self::VERSION,false);update_option('digiforge_db_version',(string)self::VERSION,false);return true;
 }
}