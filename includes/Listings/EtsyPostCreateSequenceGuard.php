<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;
/** Fail-closed guard for media mutations that must follow an authoritative CREATE_DRAFT result. */
final class EtsyPostCreateSequenceGuard
{
    public static function authorize(array $createOperation,array $nextOperation): array|WP_Error
    {
        if(strtoupper((string)($createOperation['operation_type']??''))!=='CREATE_DRAFT')return self::error('create_type','Parent operation must be CREATE_DRAFT.');
        if((string)($createOperation['state']??'')!==EtsyOperationLifecycle::CONFIRMED_SUCCESS)return self::error('create_state','Parent CREATE_DRAFT must be confirmed successful before media attachment.');
        $listingId=trim((string)($createOperation['external_reference']??''));
        if(!ctype_digit($listingId)||(int)$listingId<1)return self::error('listing_id','Confirmed CREATE_DRAFT requires an authoritative positive Etsy listing ID.');
        if((int)($createOperation['intent_id']??0)!==(int)($nextOperation['intent_id']??0)||(int)($createOperation['draft_package_id']??0)!==(int)($nextOperation['draft_package_id']??0))return self::error('scope','Post-create operation must remain in the exact approved intent/package scope.');
        if(!hash_equals((string)($createOperation['shop_reference']??''),(string)($nextOperation['shop_reference']??'')))return self::error('shop','Post-create operation must remain in the exact verified shop scope.');
        $type=strtoupper((string)($nextOperation['operation_type']??''));
        if(!in_array($type,['ATTACH_IMAGE','UPLOAD_FILE'],true))return self::error('type','Only governed media operations may follow CREATE_DRAFT through this guard.');
        $resource=trim((string)($nextOperation['resource_reference']??''));
        if($resource===''||!hash_equals($listingId,$resource))return self::error('resource','Post-create operation must target the authoritative CREATE_DRAFT listing ID.');
        return ['state'=>'ETSY_POST_CREATE_SEQUENCE_AUTHORIZED','listing_id'=>(int)$listingId,'operation_type'=>$type,'publish_permitted'=>false,'external_execution_performed'=>false];
    }
    private static function error(string $c,string $m): WP_Error{return new WP_Error('digiforge_etsy_sequence_'.$c,$m,['status'=>409]);}
}
