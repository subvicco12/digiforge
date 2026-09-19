<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Normalizes already-observed Etsy shop identity without network access. */
final class EtsyShopIdentity
{
    /** @return array<string,mixed>|WP_Error */
    public static function normalize(array $shop,string $environment):array|WP_Error
    {
        try{$environment=Validator::environment(sanitize_key($environment));}
        catch(\InvalidArgumentException $e){return new WP_Error('digiforge_etsy_environment',$e->getMessage(),['status'=>400]);}
        $shopId=trim((string)($shop['shop_id']??''));
        $shopName=sanitize_text_field((string)($shop['shop_name']??$shop['title']??''));
        if($shopId===''||preg_match('/^[0-9]{1,32}$/',$shopId)!==1)return new WP_Error('digiforge_etsy_shop_id','Valid Etsy shop identity is required.',['status'=>502]);
        return ['provider'=>'etsy','environment'=>$environment,'shop_id'=>$shopId,'shop_name'=>$shopName,'observed_at'=>sanitize_text_field((string)($shop['observed_at']??'')),'external_execution_performed'=>false];
    }
}
