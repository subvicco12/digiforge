<?php
declare(strict_types=1);

/** Explicit synthetic shop ACL only. No catalog grant, exception, credential or HTTP. */
final class PrintifyResearchAccessFixture
{
    private static ?string $path=null;
    private static ?string $secret=null;
    public static function reset(): void
    {
        global $wpdb;
        // Fixture-created ACL commits must not leak between WordPress test transactions.
        $wpdb->delete(\DigiForge\Database\Tables::audit_log(),['object_type'=>'shop_access','event_type'=>'shop_access_grant']);
        foreach(['digiforge_shop_access_state_','digiforge_shop_access_event_'] as $prefix)$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like($prefix).'%'));
        if(self::$path!==null)self::write([]);
    }
    private static function write(array $records): void
    {
        if(self::$path===null){
            if(defined('DIGIFORGE_SHOP_ACCESS_TRUST_KEY')||defined('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE'))throw new RuntimeException('Refusing configured authority.');
            $keys=sodium_crypto_sign_keypair();self::$secret=sodium_crypto_sign_secretkey($keys);
            self::$path=tempnam(sys_get_temp_dir(),'df-printify-test-acl-');
            if(self::$path===false)throw new RuntimeException('Fixture file unavailable.');
            define('DIGIFORGE_SHOP_ACCESS_TRUST_KEY',base64_encode(sodium_crypto_sign_publickey($keys)));
            define('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE',self::$path);
            register_shutdown_function(static function():void {if(self::$path!==null)unlink(self::$path);if(self::$secret!==null)sodium_memzero(self::$secret);});
        }
        $manifest=wp_json_encode(['version'=>1,'authorities'=>$records]);
        file_put_contents(self::$path,wp_json_encode(['manifest'=>$manifest,'signature'=>base64_encode(sodium_crypto_sign_detached($manifest,self::$secret))]),LOCK_EX);
    }
    public static function assign(int $actor,string $shop='personalized_pod',array $caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections']): void
    {
        $role='df_printify_test_'.substr(hash('sha256',implode('|',$caps)),0,12);
        if(!get_role($role))add_role($role,'Synthetic Printify research scope',array_fill_keys($caps,true));
        $user=get_userdata($actor);foreach($caps as $cap)if(!user_can($actor,$cap))throw new RuntimeException('Fixture cannot escalate subject.');$user->add_role($role);
        $owner=wp_insert_user(['user_login'=>'df_printify_owner_'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>'administrator']);
        if(is_wp_error($owner))throw new RuntimeException('Synthetic owner unavailable.');
        $ownerUser=get_userdata($owner);foreach(array_merge($caps,['manage_digiforge_settings']) as $cap)$ownerUser->add_cap($cap);
        self::write([['actor_id'=>$owner,'shop'=>$shop,'caps'=>$caps,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','Synthetic Printify test authority '.$owner),'authority'=>'OWNER']]);
        $prior=get_current_user_id();wp_set_current_user($owner);
        try{
            $repo=new \DigiForge\Security\ShopAccessRepository();$state=$repo->read($shop);
            if(is_wp_error($state))throw new RuntimeException($state->get_error_code());
            $assigned=$repo->grant($actor,$shop,$role,$caps,time()+600,str_replace('-','_',wp_generate_uuid4()),$state['revision']);
            if(is_wp_error($assigned))throw new RuntimeException($assigned->get_error_code());
            global $wpdb;$wpdb->query('START TRANSACTION');
        }finally{wp_set_current_user($prior);}
    }
}
