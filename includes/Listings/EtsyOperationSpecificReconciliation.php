<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/**
 * Pure fail-closed comparison boundary for Etsy mutation reconciliation.
 * It never performs network I/O and only confirms when persisted canonical
 * request evidence is exactly demonstrated by operation-specific provider data.
 */
final class EtsyOperationSpecificReconciliation
{
    /** @return array<string,mixed>|WP_Error */
    public static function compare(string $operationType,array $expected,array $provider,string $listingId):array|WP_Error
    {
        $operationType=strtoupper(trim($operationType));
        if($expected===[])return self::unknown('operation_evidence_missing',$listingId);
        return match($operationType){
            'UPDATE_DRAFT'=>self::draft($expected,$provider,$listingId),
            'UPDATE_INVENTORY'=>self::inventory($expected,$provider,$listingId),
            'ATTACH_IMAGE'=>self::image($expected,$provider,$listingId),
            default=>self::unknown('operation_specific_evidence_required',$listingId),
        };
    }

    private static function draft(array $expected,array $provider,string $listingId):array
    {
        foreach($expected as $key=>$value){
            if(!array_key_exists($key,$provider)||!self::same($value,$provider[$key]))return self::unknown('update_draft_evidence_mismatch',$listingId);
        }
        return self::success($listingId);
    }

    private static function inventory(array $expected,array $provider,string $listingId):array
    {
        if(!array_key_exists('products',$expected)||!array_key_exists('products',$provider)
            ||!self::same($expected['products'],$provider['products']))return self::unknown('inventory_evidence_mismatch',$listingId);
        return self::success($listingId);
    }

    private static function image(array $expected,array $provider,string $listingId):array
    {
        $images=is_array($provider['results']??null)?$provider['results']:(is_array($provider['images']??null)?$provider['images']:[]);
        if($images===[])return self::unknown('image_evidence_missing',$listingId);
        foreach($images as $image){
            if(!is_array($image))continue;
            $id=(string)($expected['listing_image_id']??'');
            $rank=(int)($expected['rank']??0);
            if($id!==''&&hash_equals($id,(string)($image['listing_image_id']??''))&&$rank>0&&$rank===(int)($image['rank']??0))return self::success($listingId);
            // Binary uploads have no pre-authorized Etsy image id. A content
            // hash is not exposed by Etsy's image read model, so remain UNKNOWN.
        }
        return self::unknown('image_evidence_mismatch',$listingId);
    }

    private static function same(mixed $a,mixed $b):bool
    {
        if(is_array($a)&&is_array($b)){
            $a=EtsyRequestFingerprint::canonicalize($a);
            $b=EtsyRequestFingerprint::canonicalize($b);
        }
        return $a===$b;
    }
    private static function success(string $id):array{return ['state'=>EtsyOperationLifecycle::CONFIRMED_SUCCESS,'external_reference'=>$id];}
    private static function unknown(string $code,string $id):array{return ['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>$code,'external_reference'=>$id];}
}
