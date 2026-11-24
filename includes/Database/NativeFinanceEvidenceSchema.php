<?php
declare(strict_types=1);
namespace DigiForge\Database;

/** Explicit offline additive v1 proposal. Not registered with production activation/startup. */
final class NativeFinanceEvidenceSchema
{
    public static function records():string{global $wpdb;return $wpdb->prefix.'digiforge_native_finance_records';}
    public static function claims():string{global $wpdb;return $wpdb->prefix.'digiforge_native_finance_claims';}
    public static function offline():bool{return function_exists('wp_get_environment_type')&&wp_get_environment_type()==='local';}
    public static function ready():bool
    {
        if(!self::offline()||get_option('digiforge_native_finance_schema')!=='1')return false;
        return self::validStructure();
    }
    private static function validStructure():bool
    {
        global $wpdb;
        foreach([self::records(),self::claims(),Tables::audit_log()]as$table){
            $wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
            if(QueryEvidence::failed()||strtoupper((string)$engine)!=='INNODB')return false;
        }
        foreach([self::records()=>['id','record_hash','scope_hash','source_hash','ciphertext','created_at'],self::claims()=>['claim_hash','record_hash']]as$table=>$columns){
            $wpdb->last_error='';if($wpdb->query('SELECT '.implode(',',$columns).' FROM '.$table.' LIMIT 0')===false||QueryEvidence::failed())return false;
            $indexes=$wpdb->get_results('SHOW INDEX FROM '.$table,ARRAY_A);if(QueryEvidence::failed()||!is_array($indexes))return false;
            $unique=[];foreach($indexes as$index)if((int)$index['Non_unique']===0)$unique[$index['Key_name']][]=$index['Column_name'];
            if($table===self::records()&&(($unique['PRIMARY']??null)!==['id']||($unique['record_hash']??null)!==['record_hash']))return false;
            if($table===self::claims()&&($unique['PRIMARY']??null)!==['claim_hash'])return false;
        }
        return true;
    }
    public static function installOffline():bool
    {
        if(!self::offline())return false;
        if(get_option('digiforge_native_finance_schema')==='1')return self::ready();
        global $wpdb;$charset=$wpdb->get_charset_collate();
        foreach(self::statements($charset)as$sql){$wpdb->last_error='';$ok=$wpdb->query(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$sql));if($ok===false||QueryEvidence::failed())return false;}
        if(!self::validStructure())return false;
        update_option('digiforge_native_finance_schema','1',false);return self::ready();
    }
    /** @return list<string> */
    public static function statements(string $charset):array
    {
        return ["CREATE TABLE ".self::records()." (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 record_hash char(64) NOT NULL,
 scope_hash char(64) NOT NULL,
 source_hash char(64) NOT NULL,
 ciphertext longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY record_hash (record_hash),
 KEY scope_hash (scope_hash)
) ENGINE=InnoDB {$charset};",
"CREATE TABLE ".self::claims()." (
 claim_hash char(64) NOT NULL,
 record_hash char(64) NOT NULL,
 PRIMARY KEY  (claim_hash),
 KEY record_hash (record_hash)
) ENGINE=InnoDB {$charset};"];
    }
}
