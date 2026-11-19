<?php
declare(strict_types=1);
namespace DigiForge\NativeAuthority\WordPress;

/** Explicit disposable/offline installation only. Never called by activation or capture. */
final class OfflineSchema
{
    public static function generationTable(\wpdb $db):string{return $db->prefix.'digiforge_reviewer_authority_generation';}
    public static function leasesTable(\wpdb $db):string{return $db->prefix.'digiforge_reviewer_authority_leases';}
    /** Precise public authority storage inventory, including both OLD and NEW on renames. */
    public static function triggers(\wpdb $db):array {
        $gen=self::generationTable($db);$role=$db->get_blog_prefix().'user_roles';$caps=$db->get_blog_prefix().'capabilities';$level=$db->get_blog_prefix().'user_level';
        $quote=static fn(string $v):string=>"'".esc_sql($v)."'";
        $option=static function(string $r)use($role,$quote):string {
            $exact=[$role,'home','siteurl','digiforge_portal_database_owner_authority','digiforge_portal_global_owner_bootstrap_claim','auth_key','secure_auth_key','logged_in_key','nonce_key','auth_salt','secure_auth_salt','logged_in_salt','nonce_salt','secret_key'];
            $p=[];$p[]="$r.option_name IN (".implode(',',array_map($quote,$exact)).')';
            foreach(['digiforge_portal_profile_','digiforge_portal_event_','digiforge_shop_access_state_','digiforge_shop_access_event_','digiforge_portal_global_owner_bootstrap_claim_','digiforge_portal_host_approval_'] as $prefix)$p[]='LEFT('.$r.'.option_name,'.strlen($prefix).')='.$quote($prefix);
            return '('.implode(' OR ',$p).')';
        };
        $audit=static fn(string $r):string=>"(($r.object_type='shop_access' AND $r.event_type IN ('shop_access_grant','shop_access_revoke')) OR ($r.object_type='portal_access_profile' AND $r.event_type='portal_profile_changed') OR ($r.object_type='portal_auth' AND $r.event_type='portal_global_owner_claimed'))";
        $predicates=[
            $db->users=>static fn(string $r):string=>'TRUE',
            $db->usermeta=>static fn(string $r):string=>"$r.meta_key IN (".$quote($caps).','.$quote($level).",'session_tokens')",
            $db->options=>$option,
            $db->prefix.'digiforge_audit_log'=>$audit,
        ];
        $out=[];
        foreach($predicates as $table=>$predicate)foreach(['INSERT','UPDATE','DELETE'] as $event){
            $condition=$event==='INSERT'?$predicate('NEW'):($event==='DELETE'?$predicate('OLD'):'('.$predicate('OLD').' OR '.$predicate('NEW').')');
            $name='df_rra_'.substr(hash('sha256',$gen.':'.$table.':'.$event),0,32);
            $statement="BEGIN IF $condition THEN UPDATE `$gen` SET generation=generation+1 WHERE singleton=1; IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retained authority generation unavailable'; END IF; END IF; END";
            $out[$name]=['table'=>$table,'timing'=>'AFTER','event'=>$event,'statement'=>$statement];
        }
        foreach(['INSERT','UPDATE','DELETE'] as $event){
            $name='df_rra_'.substr(hash('sha256',$gen.':guard:'.$event),0,32);
            $statement=$event==='UPDATE'?"BEGIN IF NEW.singleton<>OLD.singleton OR NEW.generation<>OLD.generation+1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retained authority generation must advance exactly once'; END IF; END":"BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retained authority generation cannot be reset'; END";
            $out[$name]=['table'=>$gen,'timing'=>'BEFORE','event'=>$event,'statement'=>$statement];
        }
        return $out;
    }
    public static function installForDisposableTests():true|\WP_Error {
        global $wpdb;
        if(wp_get_environment_type()!=='local'||!defined('WP_TESTS_DOMAIN'))return RetainedReviewerAuthority::error('offline_install_only');
        if(!Runtime::outsideTransaction($wpdb))return RetainedReviewerAuthority::error('caller_transaction');
        foreach([$wpdb->users,$wpdb->usermeta,$wpdb->options,$wpdb->prefix.'digiforge_audit_log'] as $t)if(strtoupper((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t)))!=='INNODB')return RetainedReviewerAuthority::error('storage');
        $g=self::generationTable($wpdb);$l=self::leasesTable($wpdb);
        foreach(["CREATE TABLE `$g` (singleton TINYINT UNSIGNED PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB","INSERT INTO `$g` VALUES(1,1)","CREATE TABLE `$l` (lease_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL,actor_id BIGINT UNSIGNED NOT NULL,reviewer_id BIGINT UNSIGNED NOT NULL,shop VARCHAR(64) NOT NULL,session_sha256 CHAR(64) NOT NULL,expires_at BIGINT UNSIGNED NOT NULL,state VARCHAR(16) NOT NULL,commit_marker CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL) ENGINE=InnoDB"] as $sql)if($wpdb->query($sql)===false)return RetainedReviewerAuthority::error('install');
        foreach(self::triggers($wpdb) as $name=>$spec)if($wpdb->query("CREATE TRIGGER `$name` {$spec['timing']} {$spec['event']} ON `{$spec['table']}` FOR EACH ROW {$spec['statement']}")===false)return RetainedReviewerAuthority::error('install');
        return self::verify($wpdb);
    }
    public static function verify(\wpdb $db):true|\WP_Error {
        foreach([self::generationTable($db),self::leasesTable($db),$db->users,$db->usermeta,$db->options,$db->prefix.'digiforge_audit_log'] as $t){$db->last_error='';$engine=$db->get_var($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t));if($db->last_error!==''||strtoupper((string)$engine)!=='INNODB')return RetainedReviewerAuthority::error('storage');}
        $expected=self::triggers($db);$marks=implode(',',array_fill(0,count($expected),'%s'));$db->last_error='';
        $rows=$db->get_results($db->prepare("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ($marks)",...array_keys($expected)),ARRAY_A);
        if($db->last_error!==''||!is_array($rows)||count($rows)!==count($expected))return RetainedReviewerAuthority::error('trigger_inventory');
        foreach($rows as $r){$e=$expected[$r['TRIGGER_NAME']]??null;if($e===null||$r['EVENT_OBJECT_TABLE']!==$e['table']||$r['ACTION_TIMING']!==$e['timing']||$r['EVENT_MANIPULATION']!==$e['event']||trim($r['ACTION_STATEMENT'])!==$e['statement'])return RetainedReviewerAuthority::error('trigger_integrity');}
        return true;
    }
    public static function uninstallForDisposableTests():void {
        global $wpdb;if(wp_get_environment_type()!=='local'||!defined('WP_TESTS_DOMAIN'))throw new \LogicException('Offline disposable tests only');
        foreach(self::triggers($wpdb) as $name=>$_)$wpdb->query("DROP TRIGGER IF EXISTS `$name`");
        $wpdb->query('DROP TABLE IF EXISTS `'.self::leasesTable($wpdb).'`');$wpdb->query('DROP TABLE IF EXISTS `'.self::generationTable($wpdb).'`');
    }
}
