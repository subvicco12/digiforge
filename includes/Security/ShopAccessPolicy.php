<?php
declare(strict_types=1);
namespace DigiForge\Security;

/** Pure assignment evaluator; ordinary WP permission must also pass independently. */
final class ShopAccessPolicy
{
    public static function canonicalShops(): array{return ['digital','personalized_pod','standard_pod','jewelry'];}
    public static function validShop(string $shop): bool{return in_array($shop,self::canonicalShops(),true);}
    public static function validRecord(mixed $record): bool
    {
        if(!is_array($record)||!is_int($record['actor_id']??null)||$record['actor_id']<1||!is_string($record['shop']??null)||!self::validShop($record['shop'])||!is_int($record['expires_at']??null)||!is_array($record['caps']??null)||!array_is_list($record['caps'])||count($record['caps'])<1||count($record['caps'])>32||count(array_unique($record['caps'],SORT_REGULAR))!==count($record['caps'])||!is_string($record['source_approval_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$record['source_approval_hash']))return false;
        foreach($record['caps'] as $cap){if(!is_string($cap)||!in_array($cap,\DigiForge\Core\Capabilities::ALL,true))return false;}
        return true;
    }
    /** Explicit enrollment consumes independent OWNER authority; WP administration is insufficient. */
    public static function canEnrollOwner(array $authority,int $actor,string $shop,array $caps,int $expiry,string $approval,int $now): bool
    {
        return self::validRecord($authority)&&($authority['authority']??null)==='OWNER'
            &&in_array($shop,['digital','personalized_pod'],true)&&$authority['actor_id']===$actor&&$authority['shop']===$shop
            &&$expiry>$now&&$authority['expires_at']>=$expiry&&$caps!==[]&&array_is_list($caps)
            &&count(array_unique($caps,SORT_REGULAR))===count($caps)&&array_diff($caps,$authority['caps'])===[]
            &&preg_match('/^[a-f0-9]{64}$/D',$approval)===1&&hash_equals($authority['source_approval_hash'],$approval);
    }
    public static function permits(array $record,int $actor,string $shop,string $cap,int $now,bool $ordinaryCapability): bool
    {
        return self::validRecord($record)&&$ordinaryCapability&&$record['actor_id']===$actor&&$record['shop']===$shop&&$record['expires_at']>$now&&($record['revoked']??false)===false&&in_array($cap,$record['caps'],true);
    }
}
