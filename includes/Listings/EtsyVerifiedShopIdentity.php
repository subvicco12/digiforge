<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;
use WP_Error;

final class EtsyVerifiedShopIdentity
{
    public static function resolve(int $integrationId,string $listingShopReference,int $shopId): array|WP_Error
    {
        if($integrationId<1||$shopId<1||trim($listingShopReference)==='') return self::error('identity','Integration, listing shop reference and numeric Etsy shop id are required.');
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT provider,status,config FROM '.Tables::integrations().' WHERE id=%d LIMIT 1',$integrationId),ARRAY_A);
        if(!is_array($row)||($row['provider']??'')!=='etsy'||($row['status']??'')!=='CONFIGURED') return self::error('integration','A configured Etsy integration is required.');
        $config=json_decode((string)($row['config']??'{}'),true);
        $test=is_array($config['_connection_test']??null)?$config['_connection_test']:[];
        $details=is_array($test['details']??null)?$test['details']:[];
        $verifiedId=(int)($details['shop_id']??0);
        $verifiedName=trim((string)($details['shop_name']??''));
        if(($test['ok']??false)!==true||$verifiedId<1||$verifiedName==='') return self::error('unverified','A successful Etsy identity preflight with shop identity is required.');
        if($verifiedId!==$shopId||!hash_equals($listingShopReference,$verifiedName)) return self::error('scope','Verified Etsy shop identity does not match the approved listing shop scope.');
        return ['integration_id'=>$integrationId,'shop_id'=>$verifiedId,'shop_name'=>$verifiedName];
    }
    public static function resolveAny(string $listingShopReference,int $shopId): array|WP_Error
    {
        global $wpdb;
        $ids=$wpdb->get_col("SELECT id FROM ".Tables::integrations()." WHERE provider='etsy' AND status='CONFIGURED' ORDER BY id ASC");
        foreach((array)$ids as $id){
            $resolved=self::resolve((int)$id,$listingShopReference,$shopId);
            if(!($resolved instanceof WP_Error)) return $resolved;
        }
        return self::error('scope','No verified Etsy integration matches the approved listing shop scope.');
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_verified_shop_'.$code,$message,['status'=>409]);
    }
}
