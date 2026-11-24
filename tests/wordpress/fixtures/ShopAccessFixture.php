<?php
declare(strict_types=1);

/** Opt-in ephemeral signing fixture, never an implicit administrator bypass. */
require_once __DIR__.'/SyntheticShopAuthority.php';
require_once __DIR__.'/PortalAccessFixture.php';
final class ShopAccessFixture
{
    private static array $authorities=[];
    /** Opt-in setup reset of synthetic ACL evidence only, never business/provider history. */
    public static function reset(): void
    {
        // Existing .17 fixture resets only synthetic native ACL/profile test evidence.
        PortalAccessFixture::reset();self::$authorities=[];
    }
    public static function provision(array $authorities): void
    {
        self::$authorities=$authorities;
        SyntheticShopAuthority::write($authorities);
    }
    public static function authority(int $owner,string $shop,array $caps,int $expiry=0,string $kind='DELEGATE'): array
    {
        return ['actor_id'=>$owner,'shop'=>$shop,'caps'=>$caps,'expires_at'=>$expiry?:time()+3600,'source_approval_hash'=>hash('sha256','Synthetic independent authority '.$owner.':'.$shop),'authority'=>$kind];
    }
    /** Explicit legacy-positive migration only; grants no capability the subject does not already hold. */
    public static function authorizeActor(int $actor,array $shops,array $caps): int
    {
        $subject=get_userdata($actor);if(!$subject||$shops===[]||$caps===[])throw new RuntimeException('Explicit actor, shops and capabilities required.');
        foreach($shops as $shop){if(!is_string($shop)||!DigiForge\Security\ShopAccessPolicy::validShop($shop))throw new RuntimeException('Canonical fixture shops required.');}
        foreach($caps as $cap){if(!is_string($cap)||!in_array($cap,DigiForge\Core\Capabilities::ALL,true)||!user_can($actor,$cap))throw new RuntimeException('Fixture may not escalate subject capabilities.');}
        $role=null;foreach($subject->roles as $name){$candidate=get_role($name);if(!$candidate)continue;$eligible=true;foreach($caps as $cap){if(!$candidate->has_cap($cap))$eligible=false;}if($eligible){$role=$name;break;}}
        if($role===null){
            $sorted=$caps;sort($sorted);$role='df_acl_fixture_'.substr(hash('sha256',implode('|',$sorted)),0,16);
            $existing=get_role($role);if($existing===null)add_role($role,'Explicit ACL fixture',array_fill_keys($caps,true));
            else{foreach($existing->capabilities as $cap=>$enabled){if($enabled&&!in_array($cap,$caps,true))throw new RuntimeException('Fixture role scope conflict.');}}
            $subject->add_role($role);
        }
        $id=wp_insert_user(['user_login'=>'df_acl_owner_'.str_replace('-','',wp_generate_uuid4()),'user_pass'=>wp_generate_password(32),'role'=>'administrator']);
        if($id instanceof WP_Error)throw new RuntimeException('Synthetic independent owner unavailable.');
        $records=self::$authorities;foreach(array_unique($shops) as $shop)$records[]=self::authority($id,$shop,$caps);
        self::provision($records);
        foreach(array_unique($shops) as $shop)self::grant($id,$actor,$shop,$caps,$role);
        // Real repository commits its ACL/audit atomically; restore WP test isolation
        // before the caller creates business fixtures. Never alter production transactions.
        global $wpdb;$wpdb->last_error='';
        if($wpdb->query('START TRANSACTION')===false||$wpdb->last_error!=='')throw new RuntimeException('WP fixture transaction restoration failed.');
        return $id;
    }
    /** Creates no owner assertion implicitly: caller supplies the separately signed synthetic owner. */
    public static function grant(int $owner,int $subject,string $shop,array $caps,string $role='administrator'): array
    {
        $prior=get_current_user_id();wp_set_current_user($owner);
        try{$repo=new DigiForge\Security\ShopAccessRepository();$state=$repo->read($shop);if($state instanceof WP_Error)throw new RuntimeException($state->get_error_message());$result=$repo->grant($subject,$shop,$role,$caps,time()+600,str_replace('-','_',wp_generate_uuid4()),$state['revision']);if($result instanceof WP_Error)throw new RuntimeException($result->get_error_code());return $result;}finally{wp_set_current_user($prior);}
    }
}
