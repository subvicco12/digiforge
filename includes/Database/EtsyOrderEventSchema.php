<?php
declare(strict_types=1);
namespace DigiForge\Database;

/** Additive v25: immutable normalized signed shipment/delivery receipt evidence. */
final class EtsyOrderEventSchema
{
    public const VERSION=25;
    public static function migrateIfNeeded():bool
    {
        global $wpdb;$table=Tables::etsy_order_event_evidence();
        $exists=static function()use($wpdb,$table):bool{$prior=$wpdb->suppress_errors(true);$ok=$wpdb->query('SELECT 1 FROM `'.esc_sql($table).'` LIMIT 0')!==false;$wpdb->suppress_errors($prior);$wpdb->last_error='';return $ok;};
        if((int)get_option('digiforge_db_schema_version',0)>=self::VERSION&&$exists())return true;
        $sql='CREATE TABLE IF NOT EXISTS '.$table.' (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 event_id varchar(191) NOT NULL,
 order_id bigint(20) unsigned NOT NULL,
 external_order_reference varchar(191) NOT NULL,
 shop_reference varchar(100) NOT NULL,
 environment varchar(20) NOT NULL,
 event_type varchar(32) NOT NULL,
 body_sha256 char(64) NOT NULL,
 result_hash char(64) NOT NULL,
 canonical_evidence longtext NOT NULL,
 evidence_hash char(64) NOT NULL,
 received_at datetime NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY event_id (event_id),
 KEY shop_order (shop_reference,order_id),
 KEY order_received (order_id,received_at)
) '.$wpdb->get_charset_collate().';';
        $wpdb->last_error='';$created=$wpdb->query($sql);$error=$wpdb->last_error;
        if($created===false||!$exists()){update_option('digiforge_last_migration_failure',['error_code'=>'ETSY_ORDER_EVENT_SCHEMA_UPDATE_FAILED','database_error'=>$error,'occurred_at'=>current_time('mysql',true)],false);return false;}
        $target=max(self::VERSION,(int)get_option('digiforge_db_schema_version',0));update_option('digiforge_db_schema_version',$target,false);
        if((int)get_option('digiforge_db_schema_version',0)!==$target){update_option('digiforge_last_migration_failure',['error_code'=>'ETSY_ORDER_EVENT_SCHEMA_VERSION_FAILED','occurred_at'=>current_time('mysql',true)],false);return false;}
        update_option('digiforge_db_version',(string)$target,false);return true;
    }
}
