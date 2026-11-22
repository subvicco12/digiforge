<?php
declare(strict_types=1);
namespace DigiForge\Portal;

/** Bounded browser transport; allocation policy and independent review belong to the service. */
final class FinanceAllocationReviewInput
{
    public const ACTION='digiforge_finance_allocation_review';
    public const MAX_JSON_BYTES=32768;
    public const MAX_REQUEST_BYTES=65536;
    public const MAX_REFERENCE_JSON_BYTES=1024;

    public static function normalize(array $post,string $selectedShop):array
    {
        $operation=$post['operation']??null;
        if(!is_string($operation)||!in_array($operation,['view','propose','localreview','reject','revoke'],true))self::invalid();
        $required=['_wpnonce','action','operation','shop_key','environment','business_id','store_id','source_hash'];
        if($operation==='propose')$required=array_merge($required,['lines_json','effective_at','expires_at','request_id','expected_revision']);
        elseif($operation!=='view')$required=array_merge($required,['proposal_hash','request_id','expected_revision']);
        if($operation==='propose'&&array_key_exists('original_review_reference_json',$post))$required[]='original_review_reference_json';
        $keys=array_keys($post);sort($keys);sort($required);if($keys!==$required)self::invalid();
        $bytes=0;foreach($post as $value){if(!is_string($value))self::invalid();$bytes+=strlen($value);}
        if($bytes>self::MAX_REQUEST_BYTES||$post['action']!==self::ACTION||$post['shop_key']!==$selectedShop
            ||!in_array($selectedShop,['digital','personalized_pod','standard_pod','jewelry'],true)
            ||!in_array($post['environment'],['test','sandbox','production'],true)||$post['_wpnonce']===''||strlen($post['_wpnonce'])>256)self::invalid();
        self::hash($post['source_hash']);
        $scope=['shop_key'=>$selectedShop,'business_id'=>self::integer($post['business_id'],1),'store_id'=>self::integer($post['store_id'],1),'environment'=>$post['environment']];
        $result=['operation'=>$operation,'scope'=>$scope,'source_hash'=>$post['source_hash']];
        if($operation==='view')return $result;
        if(preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$post['request_id'])!==1)self::invalid();
        $result['request_id']=$post['request_id'];$result['expected_revision']=self::integer($post['expected_revision'],$operation==='propose'?0:1);
        if($operation!=='propose'){
            self::hash($post['proposal_hash']);$result['proposal_hash']=$post['proposal_hash'];
            $result['decision']=['localreview'=>'APPROVED','reject'=>'REJECTED','revoke'=>'REVOKED'][$operation];return $result;
        }
        $result['effective_at']=self::integer($post['effective_at'],1);$result['expires_at']=self::integer($post['expires_at'],1);
        if($result['expires_at']<=$result['effective_at']||strlen($post['lines_json'])>self::MAX_JSON_BYTES)self::invalid();
        try{$lines=json_decode($post['lines_json'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){self::invalid();}
        if(!is_array($lines)||!array_is_list($lines)||count($lines)<1||count($lines)>100)self::invalid();
        $result['lines']=$lines;
        if(array_key_exists('original_review_reference_json',$post)){
            $json=$post['original_review_reference_json'];if(strlen($json)>self::MAX_REFERENCE_JSON_BYTES)self::invalid();
            if(trim($json)!==''){
                try{$reference=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){self::invalid();}
                if(!is_array($reference))self::invalid();$keys=array_keys($reference);sort($keys);
                if($keys!==['proposal_hash','revision','source_hash','state_hash']||!is_int($reference['revision'])||$reference['revision']<1)self::invalid();
                foreach(['source_hash','state_hash','proposal_hash'] as $key){if(!is_string($reference[$key]))self::invalid();self::hash($reference[$key]);}
                $result['original_review_reference']=$reference;
            }
        }
        return $result;
    }
    private static function integer(string $value,int $minimum):int
    {
        if(preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$value)!==1||(string)(int)$value!==$value||(int)$value<$minimum)self::invalid();return (int)$value;
    }
    private static function hash(string $value):void {if(preg_match('/^[a-f0-9]{64}$/D',$value)!==1)self::invalid();}
    private static function invalid():never {throw new \InvalidArgumentException('An exact bounded local allocation review request is required.');}
}
