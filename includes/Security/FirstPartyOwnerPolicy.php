<?php
declare(strict_types=1);
namespace DigiForge\Security;

/** Protected first-party account binding; never populated from options, meta or a request. */
final class FirstPartyOwnerPolicy
{
    public const CONFIG = 'DIGIFORGE_FIRST_PARTY_OWNER';
    public const EMAIL = 'subvicco12@gmail.com';
    public const MAX_LIFETIME = 31536000;

    public static function fingerprint(array $identity): string
    {
        $parts=[];
        foreach(['ID','user_login','user_email','user_registered','user_pass'] as $key){
            if(!isset($identity[$key])||(!is_string($identity[$key])&&!is_int($identity[$key])))return '';
            $parts[]=(string)$identity[$key];
        }
        return hash('sha256',(string)json_encode($parts,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    /** Pure evaluator. A caller-provided identity here never installs runtime authority. */
    public static function evaluate(mixed $config,array $identity,array $roles,array $userCaps,array $roleCaps,string $installation,int $now): array
    {
        $keys=['version','account_sha256','installation_sha256','email_verified_at','expires_at'];
        if(!is_array($config)||count($config)!==count($keys)||array_diff($keys,array_keys($config))!==[]
            ||$config['version']!==1||!is_int($config['email_verified_at'])||$config['email_verified_at']<=0||$config['email_verified_at']>$now
            ||!is_int($config['expires_at'])||$config['expires_at']<=$now||$config['expires_at']-$config['email_verified_at']>self::MAX_LIFETIME
            ||($identity['ID']??null)!==1||($identity['user_email']??null)!==self::EMAIL||!in_array('administrator',$roles,true))return [];
        foreach(['account_sha256','installation_sha256'] as $key){if(!is_string($config[$key])||preg_match('/^[a-f0-9]{64}$/D',$config[$key])!==1)return [];}
        if(!hash_equals($config['account_sha256'],self::fingerprint($identity))||!hash_equals($config['installation_sha256'],$installation))return [];
        $caps=\DigiForge\Core\Capabilities::ALL;
        if(array_diff($caps,$userCaps)!==[]||array_diff($caps,$roleCaps)!==[])return [];
        $approval=hash('sha256','digiforge-first-party-owner-v1:'.(string)json_encode(array_map(static fn(string $k):mixed=>$config[$k],$keys)));
        $records=[];
        foreach(ShopAccessPolicy::canonicalShops() as $shop)$records[]=['actor_id'=>1,'shop'=>$shop,'caps'=>$caps,'expires_at'=>$config['expires_at'],'source_approval_hash'=>$approval,'authority'=>'OWNER'];
        return $records;
    }

    public static function storedRoleCapabilities(mixed $raw): array
    {
        if(!is_string($raw)||$raw===''||strlen($raw)>1048576)return [];
        $roles=@unserialize($raw,['allowed_classes'=>false]);
        if(!is_array($roles)||!is_array($roles['administrator']??null)||!is_array($roles['administrator']['capabilities']??null))return [];
        $caps=[];foreach(\DigiForge\Core\Capabilities::ALL as $cap){if(($roles['administrator']['capabilities'][$cap]??null)===true)$caps[]=$cap;}
        return $caps;
    }

    public static function records(): array|\WP_Error
    {
        if(!defined(self::CONFIG))return [];
        global $wpdb;$wpdb->last_error='';
        // Bypass cached identity on every root revalidation, including grant consumption.
        $identity=$wpdb->get_row("SELECT ID,user_login,user_email,user_registered,user_pass FROM {$wpdb->users} WHERE ID=1",ARRAY_A);
        if($wpdb->last_error!=='')return new \WP_Error('digiforge_owner_identity_read','OWNER identity evidence unavailable.',['status'=>503]);
        if(!is_array($identity))return self::error();
        $identity['ID']=(int)$identity['ID'];
        $wpdb->last_error='';
        $rawRoles=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$wpdb->get_blog_prefix().'user_roles'));
        if($wpdb->last_error!=='')return new \WP_Error('digiforge_owner_role_read','OWNER role evidence unavailable.',['status'=>503]);
        $roleCaps=self::storedRoleCapabilities($rawRoles);
        clean_user_cache(1);wp_roles()->for_site();
        $user=new \WP_User(1);$userCaps=[];
        foreach(\DigiForge\Core\Capabilities::ALL as $cap){if($user->has_cap($cap))$userCaps[]=$cap;}
        $installation=hash('sha256',site_url('/').'|'.$wpdb->prefix);
        $records=self::evaluate(constant(self::CONFIG),$identity,$user->roles,$userCaps,$roleCaps,$installation,time());
        return $records===[]?self::error():$records;
    }
    private static function error(): \WP_Error
    {
        return new \WP_Error('digiforge_owner_identity_invalid','Protected OWNER account binding is missing, expired or changed.',['status'=>403]);
    }
}
