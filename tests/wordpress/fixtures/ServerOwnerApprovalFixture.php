<?php
/** Private, synthetic host approval for isolated WordPress tests only. */
final class ServerOwnerApprovalFixture {
 private static array $issuedApprovals=[];
 public static function ownsApproval(string $hash):bool{return isset(self::$issuedApprovals[$hash]);}
 public static function path():string {if(!defined('DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE'))define('DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE',sys_get_temp_dir().'/df-server-owner-'.getmypid().'.json');return DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE;}
 public static function clear():void {@unlink(self::path());}
 public static function write(array $changes=[]):array {$a=array_replace(['version'=>1,'actor_id'=>1,'email'=>\DigiForge\PortalAccess\Owner::EMAIL,'site'=>home_url('/'),'scope'=>'GLOBAL_PRIMARY_OWNER','purpose'=>'ENROLL','approval_id'=>bin2hex(random_bytes(32)),'expires_at'=>time()+3600,'authority_expires_at'=>time()+86400],$changes);self::$issuedApprovals[$a['approval_id']]=true;file_put_contents(self::path(),wp_json_encode($a));chmod(self::path(),0600);clearstatcache(true,self::path());return $a;}
 public static function storeRecords(array $records):void {global $wpdb;$body=['version'=>1,'site'=>home_url('/'),'records'=>$records,'claim_hash'=>hash('sha256','synthetic-claim')];$raw=wp_json_encode(['body'=>$body,'mac'=>hash_hmac('sha256',wp_json_encode($body),wp_salt('auth'))]);$wpdb->replace($wpdb->options,['option_name'=>'digiforge_portal_database_owner_authority','option_value'=>$raw,'autoload'=>'no']);}
}
