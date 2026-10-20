<?php
declare(strict_types=1);
namespace DigiForge\Portal;

/** Browser transport for the existing exact allocation-preview API, not a provider import schema. */
final class FinanceImportPreviewInput
{
    public const ACTION='digiforge_finance_allocation_preview';
    public const MAX_JSON_BYTES=65536;
    public const MAX_REQUEST_BYTES=262144;

    public static function normalize(array $post,string $selectedShop):array
    {
        if(!array_key_exists('original_packet_json',$post))$post['original_packet_json']='';
        $keys=array_keys($post);sort($keys);
        if($keys!==['_wpnonce','action','business_id','environment','lines_json','original_packet_json','shop_key','source_hash','store_id'])self::invalid();
        $bytes=0;foreach($post as $value){if(!is_string($value))self::invalid();$bytes+=strlen($value);}
        if($bytes>self::MAX_REQUEST_BYTES||strlen($post['lines_json'])+strlen($post['original_packet_json'])>self::MAX_JSON_BYTES)self::invalid();
        if($post['action']!==self::ACTION||$post['shop_key']!==$selectedShop||!in_array($selectedShop,['digital','personalized_pod','standard_pod','jewelry'],true)
            ||!in_array($post['environment'],['sandbox','test','production'],true)||strlen($post['_wpnonce'])>256||$post['_wpnonce']===''
            ||preg_match('/^[a-f0-9]{64}$/D',$post['source_hash'])!==1)self::invalid();
        $scope=['shop_key'=>$selectedShop];
        foreach(['business_id','store_id'] as $key){
            if(preg_match('/^[1-9][0-9]{0,18}$/D',$post[$key])!==1||(string)(int)$post[$key]!==$post[$key])self::invalid();
            $scope[$key]=(int)$post[$key];
        }
        $scope['environment']=$post['environment'];
        try{
            $lines=json_decode($post['lines_json'],true,32,JSON_THROW_ON_ERROR);
            $original=$post['original_packet_json']===''?null:json_decode($post['original_packet_json'],true,32,JSON_THROW_ON_ERROR);
        }catch(\JsonException){self::invalid();}
        if(!is_array($lines)||!array_is_list($lines)||count($lines)<1||count($lines)>200
            ||($original!==null&&(!is_array($original)||array_is_list($original))))self::invalid();
        return ['source_hash'=>$post['source_hash'],'scope'=>$scope,'lines'=>$lines,'original_packet'=>$original];
    }

    private static function invalid():never {throw new \InvalidArgumentException('An exact bounded local allocation-preview request is required.');}
}
