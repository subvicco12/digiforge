<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Pure matching helper for trusted CORE observations. Never authenticates or applies a business transition. */
final class WebhookReconciler {
 private static function check(array $e,string $scope,string $type,string $id,int $observed,int $watermark):?string {
  $hash=$e['evidence_hash']??null;unset($e['evidence_hash']);if(!is_string($hash)||!hash_equals(MutationIntent::hash($e),$hash)||($e['schema']??null)!=='DF_WEBHOOK_EVIDENCE_1'||($e['signature_verified']??null)!==true||($e['scope_fingerprint']??null)!==$scope||($e['resource_type']??null)!==$type||($e['resource_id']??null)!==$id||!is_int($e['created_at']??null)||$observed<1||$observed>time()+30||$watermark<0||$watermark>time()+30)throw new \InvalidArgumentException('Exact core-trusted event and readback binding required.');
  if($e['created_at']<=$watermark)return 'OUT_OF_ORDER_REVIEW_REQUIRED';if($observed<$e['created_at'])return 'AUTHORITATIVE_READBACK_REQUIRED';return null;
 }
 private static function result(string $state,array $e,string $observation):array{return ['state'=>$state,'event_id'=>$e['event_id'],'event_payload_hash'=>$e['payload_hash'],'observation_hash'=>$observation,'manual_review_required'=>true,'core_receipt_verification_required'=>true,'business_transition_authorized'=>false,'retry_permitted'=>false,'external_execution_authorized'=>false];}
 public static function order(array $event,OrderReadModel $order,int $observedAt,int $watermark=0):array{$o=$order->jsonSerialize();$state=self::check($event,$o['scope_fingerprint'],'order',$o['order_id'],$observedAt,$watermark);return self::result($state??'ORDER_READBACK_MATCHED_REVIEW_REQUIRED',$event,$o['content_hash']);}
 public static function product(array $event,MutationIntent $publication,array $readback,int $observedAt,int $watermark=0):array {
  if($publication->operation!=='PRODUCT_PUBLISH')throw new \InvalidArgumentException('Publication observation required.');$p=$publication->prepared();$state=self::check($event,$publication->scope->fingerprint(),'product',$p['evidence']['current_product_id'],$observedAt,$watermark);$observation=PublicationContract::readback($p,$readback);return self::result($state??$observation['state'],$event,MutationIntent::hash($readback));
 }
}
