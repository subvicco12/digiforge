<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Passive statement only: no hooks, migrations, installation, activation or backfill. */
final class NativeBusinessVersionSchema {
 public static function table():string {global $wpdb;return $wpdb->prefix.'digiforge_local_business_order_versions';}
 public static function statement():string {
  global $wpdb;$table=self::table();$charset=$wpdb->get_charset_collate();
  return "CREATE TABLE {$table} (
 entity_id bigint unsigned NOT NULL AUTO_INCREMENT,
 stream_key char(64) NOT NULL,
 local_order_id bigint unsigned NOT NULL,
 version_number smallint unsigned NOT NULL,
 parent_version_hash char(64) NOT NULL,
 reviewed_association_hash char(64) NOT NULL,
 record_payload longtext NOT NULL,
 record_hash char(64) NOT NULL,
 record_seal char(64) NOT NULL,
 head_marker tinyint unsigned NULL,
 confirmation_state varchar(16) NOT NULL,
 confirmation_seal char(64) NOT NULL,
 created_by bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY (entity_id),
 UNIQUE KEY stream_version (stream_key,version_number),
 UNIQUE KEY stream_head (stream_key,head_marker),
 UNIQUE KEY local_order_head (local_order_id,head_marker),
 UNIQUE KEY record_hash (record_hash)
) ENGINE=InnoDB {$charset}";
 }
}
