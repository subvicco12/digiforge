<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Minimized trusted-core receipt schema. A hash detects drift; it does not authenticate its author. */
final class ProviderReceipt {
 public static function hash(array $receipt):string{unset($receipt['receipt_hash']);return MutationIntent::hash($receipt);}
 public static function validate(MutationIntent $i,array $r):array {
  $keys=['schema','operation_id','payload_fingerprint','intent_fingerprint','scope_fingerprint','shop_key','integration_id','credential_reference','provider_shop_id','approval_reference','authorization_revision','product_version','event_id','sequence','kind','fence_id','recovery_epoch','http_status','sent_at','received_at','body','receipt_hash'];
  if(array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r)))self::bad();
  foreach(['schema'=>'DF_PRINTIFY_RECEIPT_1','operation_id'=>$i->operationId,'payload_fingerprint'=>$i->payloadFingerprint,'intent_fingerprint'=>$i->intentFingerprint,'scope_fingerprint'=>$i->scope->fingerprint(),'shop_key'=>$i->scope->shopKey,'integration_id'=>$i->scope->integrationId,'credential_reference'=>$i->scope->credential->integrationId,'provider_shop_id'=>$i->scope->providerShopId,'approval_reference'=>$i->context['approval_reference'],'authorization_revision'=>$i->context['authorization_revision'],'product_version'=>$i->context['product_version']]as $key=>$value)if($r[$key]!==$value)self::bad();
  foreach(['event_id','fence_id','recovery_epoch','receipt_hash']as $k)if(!is_string($r[$k])||!preg_match('/^[a-f0-9]{64}$/D',$r[$k]))self::bad();
  if(!hash_equals(self::hash($r),$r['receipt_hash'])||!is_int($r['sequence'])||$r['sequence']<1||!is_int($r['sent_at'])||!is_int($r['received_at'])||$r['sent_at']<1||$r['received_at']<$r['sent_at']||$r['received_at']>time()+30||!is_array($r['body'])||strlen(json_encode($r['body'],JSON_THROW_ON_ERROR))>1048576)self::bad();
  if($r['kind']==='UNKNOWN'){
   if($r['body']!==[]||$r['http_status']!==0)self::bad();return ['state'=>'UNKNOWN','retry_permitted'=>false];
  }
  if($r['kind']!=='RESPONSE'||$r['http_status']!==200)self::bad();
  $allowed=$i->operation==='IMAGE_UPLOAD'?['id','file_name','width','height']:['id','shop_id','blueprint_id','print_provider_id','variants','visible','is_locked'];
  if(array_diff(array_keys($r['body']),$allowed))self::bad();
  if($i->operation!=='IMAGE_UPLOAD')foreach($r['body']['variants']??[]as $v)if(!is_array($v)||array_keys($v)!==['id'])self::bad();
  return $i->operation==='IMAGE_UPLOAD'?UploadContract::reconcile($i->prepared(),$r['body']):ProductDraftContract::reconcile($i->prepared(),$r['body']);
 }
 private static function bad():never{throw new \InvalidArgumentException('Exact scoped minimized core-attested receipt required.');}
}
