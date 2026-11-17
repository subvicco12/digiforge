<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Official X-Pfy-Signature HMAC over RAW bytes. Only core custody may supply the per-shop secret. */
final class WebhookContract {
 public static function verify(ShopBinding $s,string $raw,string $signature,string $secret,int $now):array {
  if($raw===''||strlen($raw)>1048576||strlen($secret)<32||!preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)||!hash_equals('sha256='.hash_hmac('sha256',$raw,$secret),$signature))self::bad();
  try{$e=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){self::bad();}
  if(!is_array($e)||array_diff(array_keys($e),['id','type','created_at','resource'])||!is_string($e['id']??null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$e['id']))self::bad();
  $topics=['product:created','product:updated','product:deleted','product:publish:started','order:created','order:updated','order:line-item:canceled','order:sent-to-production','order:shipment:created','order:shipment:delivered'];if(!in_array($e['type']??null,$topics,true)||!is_string($e['created_at']??null))self::bad();
  $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP',$e['created_at']);if(!$date||$date->format('Y-m-d H:i:sP')!==$e['created_at']||$date->getTimestamp()>$now+60||$date->getTimestamp()<$now-86400)self::bad();
  $r=$e['resource']??null;$type=explode(':',$e['type'])[0];if(!is_array($r)||array_diff(array_keys($r),['id','type','data'])||($r['type']??null)!==$type||!is_string($r['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$r['id'])||!is_array($r['data']??null)||!is_int($r['data']['shop_id']??null)||(string)$r['data']['shop_id']!==$s->providerShopId)self::bad();
  // Topic-specific private payloads are not exported or applied as authoritative business state.
  $v=['schema'=>'DF_WEBHOOK_EVIDENCE_1','scope_fingerprint'=>$s->fingerprint(),'event_id'=>strtolower($e['id']),'topic'=>$e['type'],'resource_type'=>$type,'resource_id'=>$r['id'],'created_at'=>$date->getTimestamp(),'payload_hash'=>hash('sha256',$raw),'dedupe_key'=>MutationIntent::hash([$s->shopKey,$s->integrationId,$s->providerShopId,$s->environment,strtolower($e['id'])]),'resource_key'=>MutationIntent::hash([$s->shopKey,$s->integrationId,$s->providerShopId,$s->environment,$type,$r['id']]),'signature_verified'=>true,'durable_core_claim_required'=>true,'authoritative_readback_required'=>true,'business_transition_authorized'=>false,'external_execution_authorized'=>false];return $v+['evidence_hash'=>MutationIntent::hash($v)];
 }
 private static function bad():never{throw new \InvalidArgumentException('Authentic bounded shop-scoped webhook evidence required.');}
}
