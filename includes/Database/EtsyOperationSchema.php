<?php
declare(strict_types=1);

namespace DigiForge\Database;

/** Additive schema installer for the locked Etsy operation ledger. */
final class EtsyOperationSchema
{
    public const VERSION = 3;
    private const OPTION = 'digiforge_etsy_operation_schema_version';

    public static function migrateIfNeeded(): bool
    {
        global $wpdb;
        if ((int)get_option(self::OPTION,0) >= self::VERSION) return true;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $wpdb->last_error='';
        dbDelta(self::statement($wpdb->get_charset_collate()));
        if ($wpdb->last_error !== '') {
            update_option('digiforge_last_migration_failure',[
                'error_code'=>'ETSY_OPERATION_SCHEMA_UPDATE_FAILED',
                'occurred_at'=>current_time('mysql',true),
            ],false);
            return false;
        }
        update_option(self::OPTION,self::VERSION,false);
        return true;
    }

    public static function statement(string $charset): string
    {
        global $wpdb;
        $table=$wpdb->prefix.'digiforge_etsy_operations';
        return "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shop_reference varchar(191) NOT NULL,
  intent_id bigint(20) unsigned NOT NULL,
  draft_package_id bigint(20) unsigned NOT NULL,
  operation_type varchar(64) NOT NULL,
  state varchar(32) NOT NULL DEFAULT 'NOT_SENT',
  idempotency_key varchar(191) NOT NULL,
  request_fingerprint char(64) NOT NULL,
  authorization_hash char(64) NOT NULL,
  evidence_hash char(64) NOT NULL,
  external_reference varchar(191) NOT NULL DEFAULT '',
  reconciliation_reference varchar(191) NOT NULL DEFAULT '',
  reconciliation_evidence longtext NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY shop_idempotency (shop_reference,idempotency_key),
  KEY intent_state (intent_id,state),
  KEY package_state (draft_package_id,state),
  KEY operation_state (operation_type,state)
) $charset;";
    }
}
