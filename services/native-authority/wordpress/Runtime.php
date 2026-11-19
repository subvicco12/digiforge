<?php
declare(strict_types=1);
namespace DigiForge\NativeAuthority\WordPress;
use DigiForge\Security\{ShopAccess,ShopAccessRepository,ShopAccessPolicy};
use DigiForge\PortalAccess\{Store,Session,GlobalOwnerAuthority,ServerOwnerApproval};
use DigiForge\Connector\Printify\NativeAuthorityAdapter;

/** Transport/cache support for the bounded native DB backend; never duplicates ACL policy. */
final class Runtime
{
    public static function outsideTransaction(\wpdb $db):bool {
        $db->last_error='';$row=$db->get_row('SELECT @@session.autocommit AS ac,@@session.in_transaction AS tx',ARRAY_A);
        return $db->last_error===''&&is_array($row)&&(string)$row['ac']==='1'&&(string)$row['tx']==='0';
    }
    public static function handle(\wpdb $db):\mysqli|false {
        try{$p=new \ReflectionProperty(\wpdb::class,'dbh');$h=$p->getValue($db);return $h instanceof \mysqli?$h:false;}catch(\Throwable){return false;}
    }
    public static function physical(\wpdb $db,\mysqli $handle,int $thread):bool {
        try{return self::handle($db)===$handle&&$handle->thread_id===$thread&&$thread>0;}catch(\Throwable){return false;}
    }
    public static function backend():true|\WP_Error {
        global $wpdb,$wp_user_roles,$wp_object_cache;
        if(!is_object($wp_object_cache)||get_class($wp_object_cache)!==\WP_Object_Cache::class||realpath((string)(new \ReflectionClass($wp_object_cache))->getFileName())!==realpath(ABSPATH.WPINC.'/class-wp-object-cache.php'))return RetainedReviewerAuthority::error('unsupported_cache_backend');
        if(get_class(wp_roles())!==\WP_Roles::class||realpath((string)(new \ReflectionClass(wp_roles()))->getFileName())!==realpath(ABSPATH.WPINC.'/class-wp-roles.php'))return RetainedReviewerAuthority::error('unsupported_roles_backend');
        if(get_class($wpdb)!==\wpdb::class||is_multisite()||wp_using_ext_object_cache()||!empty($wp_user_roles)||!wp_roles()->use_db)return RetainedReviewerAuthority::error('unsupported_native_storage');
        foreach(['all','wp_roles_init','clean_user_cache','set_current_user','pre_wp_load_alloptions','pre_cache_alloptions','alloptions','update_user_metadata_cache','default_user_metadata','auth_cookie','user_has_cap','map_meta_cap','role_has_cap','session_token_manager','get_user_metadata','get_user_metadata_by_mid','pre_option','pre_site_option','salt','home_url','site_url','option_home','option_siteurl','pre_option_home','pre_option_siteurl','option_'.wp_roles()->role_key,'pre_option_'.wp_roles()->role_key,'default_option_'.wp_roles()->role_key,'default_option_home','default_option_siteurl'] as $hook)if(!self::stockHook($hook))return RetainedReviewerAuthority::error('unsupported_hook_'.sanitize_key($hook));
        foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $key)if(!defined($key)||!is_string(constant($key))||strlen(constant($key))<16||constant($key)==='put your unique phrase here')return RetainedReviewerAuthority::error('unsupported_salt_backend');
        $f=new \ReflectionFunction('wp_validate_auth_cookie');if(realpath((string)$f->getFileName())!==realpath(ABSPATH.WPINC.'/pluggable.php'))return RetainedReviewerAuthority::error('unsupported_auth_backend');
        return true;
    }
    private static function stockHook(string $hook):bool {
        global $wp_filter;
        if(has_filter($hook)===false)return true;
        if($hook==='all'){
            $guards=[\DigiForge\Security\ShopAccessAdminGuard::class=>['priority'=>0,'file'=>'includes/Security/ShopAccessAdminGuard.php'],\DigiForge\PortalAccess\Guard::class=>['priority'=>-100,'file'=>'includes/PortalAccess/Guard.php']];
            foreach($wp_filter[$hook]->callbacks as $priority=>$callbacks)foreach($callbacks as $entry){
                $f=$entry['function']??null;$spec=is_array($f)&&is_object($f[0]??null)?($guards[get_class($f[0])]??null):null;
                if($spec===null||($f[1]??null)!=='action'||$priority!==$spec['priority']||($entry['accepted_args']??null)!==1)return false;
                if(realpath((string)(new \ReflectionMethod($f[0],'action'))->getFileName())!==realpath(dirname(__DIR__,3).'/'.$spec['file']))return false;
            }
            return true;
        }
        $specs=[
            'user_has_cap'=>['file'=>'capabilities.php','priority'=>1,'functions'=>['wp_maybe_grant_install_languages_cap'=>1,'wp_maybe_grant_resume_extensions_caps'=>1,'wp_maybe_grant_site_health_caps'=>4]],
            'option_home'=>['file'=>'functions.php','priority'=>10,'functions'=>['_config_wp_home'=>1]],
            'option_siteurl'=>['file'=>'functions.php','priority'=>10,'functions'=>['_config_wp_siteurl'=>1]],
            'set_current_user'=>['file'=>'kses.php','priority'=>10,'functions'=>['kses_init'=>1,'_wp_footnotes_kses_init'=>1,'wp_custom_css_kses_init'=>1]],
        ];
        $spec=$specs[$hook]??null;if($spec===null||!isset($wp_filter[$hook]))return false;
        foreach($wp_filter[$hook]->callbacks as $priority=>$callbacks)foreach($callbacks as $entry){
            $f=$entry['function']??null;
            if($priority!==$spec['priority']||!is_string($f)||!isset($spec['functions'][$f])||($entry['accepted_args']??null)!==$spec['functions'][$f])return false;
            $reflection=new \ReflectionFunction($f);
            if(realpath((string)$reflection->getFileName())!==realpath(ABSPATH.WPINC.'/'.match($f){'_wp_footnotes_kses_init'=>'blocks.php','wp_custom_css_kses_init'=>'block-supports/custom-css.php',default=>$spec['file']}))return false;
        }
        return true;
    }
    public static function cookies():string {
        $a=$_COOKIE[LOGGED_IN_COOKIE]??null;$p=$_COOKIE[Session::name()]??null;
        return is_string($a)&&is_string($p)?hash('sha256',$a."\0".$p):'';
    }
    public static function pureActor(int $actor,string $cookie):bool {
        // No SQL or authorization-repair after the last signed read.
        return get_current_user_id()===$actor&&is_user_logged_in()&&$cookie!==''&&hash_equals($cookie,self::cookies())&&current_user_can('manage_digiforge_orders')&&current_user_can('manage_digiforge_pod')&&get_current_user_id()===$actor&&is_user_logged_in()&&hash_equals($cookie,self::cookies());
    }
    /** Actual public authorization on a fresh connection. Foreign reviewer is never made current. */
    public static function authorize(\wpdb $aux,int $actor,int $reviewer,string $shop,string $cookie):array|\WP_Error {
        global $wpdb;
        $original=$wpdb;
        if(!self::pureActor($actor,$cookie))return RetainedReviewerAuthority::error('actor_changed');
        $wpdb=$aux;
        try{
            $backend=self::backend();if(is_wp_error($backend))return $backend;
            // This backend has no persistent cache. Clear native cached rows through public APIs.
            foreach(array_unique([1,$actor,$reviewer]) as $id)clean_user_cache($id);
            wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
            foreach([wp_roles()->role_key,'home','siteurl'] as $key)wp_cache_delete($key,'options');
            wp_roles()->for_site();
            if(get_current_user_id()!==$actor)return RetainedReviewerAuthority::error('actor_changed');
            $native=NativeAuthorityAdapter::session($actor);if(is_wp_error($native))return RetainedReviewerAuthority::error('session');
            // wp_set_current_user reloads the SAME authenticated decision actor, never reviewer.
            wp_set_current_user(0);wp_set_current_user($actor);
            if(get_class(\WP_Session_Tokens::get_instance($actor))!==\WP_User_Meta_Session_Tokens::class)return RetainedReviewerAuthority::error('unsupported_session_backend');
            $owner=Store::read(1);$databaseOwner=ServerOwnerApproval::read();
            if(!is_array($owner)||!is_array($databaseOwner)||!GlobalOwnerAuthority::active($owner))return RetainedReviewerAuthority::error('db_gpo_required');
            // Discriminate transport only for the exact retained subjects. Public ShopAccess
            // filters grants by subject; unrelated delegates cannot authorize these subjects.
            $state=(new ShopAccessRepository())->read($shop);if(is_wp_error($state))return RetainedReviewerAuthority::error('authority_read');
            $expiry=min(time()+30,$native['expires_at'],$databaseOwner['expires_at']);
            foreach($state['grants'] as $grant){if(!in_array($grant['actor_id'],[$actor,$reviewer],true))continue;if($grant['grantor_id']!==1)return RetainedReviewerAuthority::error('file_delegate_unsupported');$expiry=min($expiry,$grant['revoked']?PHP_INT_MAX:$grant['expires_at']);}
            foreach([$actor,$reviewer] as $id)foreach(['manage_digiforge_orders','manage_digiforge_pod'] as $cap)if(ShopAccess::authorize($shop,$cap,$id)!==true)return RetainedReviewerAuthority::error('authorization');
            $again=NativeAuthorityAdapter::session($actor);
            if(is_wp_error(self::backend())||is_wp_error($again)||$again!==$native||!self::pureActor($actor,$cookie))return RetainedReviewerAuthority::error('actor_changed');
            return ['expires_at'=>$expiry,'session_sha256'=>$native['fingerprint']];
        }catch(\Throwable){return RetainedReviewerAuthority::error('authorization_unavailable');}
        finally{$wpdb=$original;}
    }
    /** Private witness SQL must not leak lease material through wpdb diagnostics. */
    public static function privateQuery(\wpdb $db,string $sql):int|bool {
        $prior=$db->suppress_errors(true);try{return $db->query($sql);}finally{$db->suppress_errors($prior);}
    }
    public static function privateVar(\wpdb $db,string $sql):mixed {
        $prior=$db->suppress_errors(true);try{return $db->get_var($sql);}finally{$db->suppress_errors($prior);}
    }
    public static function generation(\wpdb $db,bool $lock=false):string|\WP_Error {
        $db->last_error='';$v=$db->get_var('SELECT generation FROM `'.OfflineSchema::generationTable($db).'` WHERE singleton=1'.($lock?' FOR UPDATE':''));
        return $db->last_error===''&&is_string($v)&&preg_match('/^[1-9][0-9]{0,19}$/D',$v)===1?$v:RetainedReviewerAuthority::error('generation_unavailable');
    }
}
