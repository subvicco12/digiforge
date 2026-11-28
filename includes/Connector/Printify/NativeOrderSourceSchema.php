<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Passive, unregistered OFFLINE schema. No migration, installer, cron or activation hook. */
final class NativeOrderSourceSchema {
 public static function table():string{global $wpdb;if(!is_string($wpdb->prefix)||preg_match('/^[A-Za-z0-9_]+$/D',$wpdb->prefix)!==1)throw new \RuntimeException('Exact table prefix required.');return $wpdb->prefix.'digiforge_native_order_sources';}
 public static function statement():string{$t=self::table();return "CREATE TABLE $t (scope_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, order_identity char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, revision int unsigned NOT NULL, source_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, event_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, payload longtext NOT NULL, PRIMARY KEY(scope_key,order_identity,revision), UNIQUE KEY source_identity(scope_key,source_hash), UNIQUE KEY event_hash(event_hash)) ENGINE=InnoDB";}
}
