<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Passive OFFLINE schema proposal. No installer, migration registration, version option or bootstrap hook. */
final class NativeHeldJournalSchema {
 public static function tables():array {global $wpdb;$prefix=$wpdb->prefix;if(!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]+$/D',$prefix))throw new \RuntimeException('Core table prefix unavailable.');return [$prefix.'digiforge_native_held_journals',$prefix.'digiforge_native_held_events'];}
 public static function statements():array {
  [$j,$e]=self::tables();return [
   "CREATE TABLE $j (operation_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, candidate_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, shop_key varchar(32) NOT NULL, actor_id bigint unsigned NOT NULL, revision int unsigned NOT NULL, head_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, snapshot longtext NOT NULL, PRIMARY KEY(shop_key,operation_id), UNIQUE KEY candidate_key(candidate_key)) ENGINE=InnoDB",
   "CREATE TABLE $e (shop_key varchar(32) NOT NULL, operation_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, revision int unsigned NOT NULL, event_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, payload longtext NOT NULL, PRIMARY KEY(shop_key,operation_id,revision), UNIQUE KEY event_hash(event_hash)) ENGINE=InnoDB",
  ];
 }
}
