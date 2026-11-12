<?php
declare(strict_types=1);
require_once __DIR__.'/SyntheticShopAuthority.php';
require_once __DIR__.'/ShopAccessFixture.php';

/** Explicit synthetic delegated shop ACL only. No catalog grant, exception, credential or HTTP. */
final class PrintifyResearchAccessFixture
{
    public static function reset(): void
    {
        ShopAccessFixture::reset();
    }
    private static function write(array $records): void { SyntheticShopAuthority::write($records); }
    public static function assign(int $actor,string $shop='personalized_pod',array $caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections']): void
    {
        $role='df_printify_test_'.substr(hash('sha256',implode('|',$caps)),0,12);
        if(!get_role($role))add_role($role,'Synthetic Printify research scope',array_fill_keys($caps,true));
        $user=get_userdata($actor);foreach($caps as $cap)if(!user_can($actor,$cap))throw new RuntimeException('Fixture cannot escalate subject.');$user->add_role($role);
        $owner=wp_insert_user(['user_login'=>'df_printify_owner_'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>'administrator']);
        if(is_wp_error($owner))throw new RuntimeException('Synthetic owner unavailable.');
        $ownerUser=get_userdata($owner);foreach(array_merge($caps,['manage_digiforge_settings']) as $cap)$ownerUser->add_cap($cap);
        self::write([['actor_id'=>$owner,'shop'=>$shop,'caps'=>$caps,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','Synthetic Printify test authority '.$owner),'authority'=>'DELEGATE']]);
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
