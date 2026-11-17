<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Inert asynchronous publish request. Claims require core approval/evidence resolution. */
final class PublicationContract {
 public static function prepare(ShopBinding $s,array $input,array $e):array {
  $keys=['shop_key','integration_id','provider_shop_id','sales_channel','connected','eligible','channel_receipt','product_receipt','approval_receipt','authorization_revision','product_version','expected_product_version','current_product_id','approver_role'];
  if(array_diff(array_keys($e),$keys)||array_diff($keys,array_keys($e))||array_diff(array_keys($input),['product_id','flags']))self::bad();
  foreach(['shop_key'=>$s->shopKey,'integration_id'=>$s->integrationId,'provider_shop_id'=>$s->providerShopId,'sales_channel'=>'etsy','connected'=>true,'eligible'=>true,'authorization_revision'=>$s->revision,'approver_role'=>'GLOBAL_PRIMARY_OWNER']as $k=>$v)if($e[$k]!==$v)self::bad();
  if($s->shopKey!=='personalized_pod'||!is_string($input['product_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$input['product_id'])||$e['current_product_id']!==$input['product_id']||!is_int($e['product_version'])||$e['product_version']<1||$e['expected_product_version']!==$e['product_version'])self::bad();
  foreach(['channel_receipt','product_receipt','approval_receipt']as $k)if(!is_string($e[$k])||!preg_match('/^[a-f0-9]{64}$/D',$e[$k]))self::bad();
  $flags=$input['flags']??null;$allowed=['title','description','images','variants','tags','keyFeatures','shipping_template'];if(!is_array($flags)||array_diff(array_keys($flags),$allowed)||array_diff($allowed,array_keys($flags))||!in_array(true,$flags,true))self::bad();foreach($flags as $v)if(!is_bool($v))self::bad();
  $r=['request'=>['method'=>'POST','path'=>'/v1/shops/'.$s->providerShopId.'/products/'.$input['product_id'].'/publish.json','body'=>$flags],'scope_fingerprint'=>$s->fingerprint(),'evidence'=>$e,'network_request_permitted'=>false,'external_execution_authorized'=>false];return $r+['fingerprint'=>MutationIntent::hash($r)];
 }
 private static function intact(array $r):void{$hash=$r['fingerprint']??null;unset($r['fingerprint']);if(!is_string($hash)||!hash_equals(MutationIntent::hash($r),$hash)||($r['external_execution_authorized']??null)!==false)self::bad();}
 public static function reconcile(array $request,array $body,int $status):array{self::intact($request);if($status===200&&$body!==[])self::bad();return ['state'=>$status===200?'PUBLISH_PENDING':'UNKNOWN','publication_verified'=>false,'manual_review_required'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];}
 public static function readback(array $r,array $p):array{self::intact($r);if(($p['id']??null)!==$r['evidence']['current_product_id']||!is_int($p['shop_id']??null)||(string)$p['shop_id']!==$r['evidence']['provider_shop_id']||!is_bool($p['is_locked']??null))self::bad();return ['state'=>$p['is_locked']?'PUBLISH_PENDING':'PUBLICATION_READBACK_REVIEW_REQUIRED','publication_verified'=>false,'manual_review_required'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];}
 private static function bad():never{throw new \InvalidArgumentException('Exact approved Etsy product/revision and publication fields required.');}
}
