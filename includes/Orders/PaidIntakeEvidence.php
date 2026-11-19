<?php
declare(strict_types=1);
namespace DigiForge\Orders;

use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyStoredShopScope;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Stored local intake correlation only. The signed payload/order relationship was not persisted. */
final class PaidIntakeEvidence
{
 public function inspect(string $shop,int $orderId):array|WP_Error {
  try {
   $actor=get_current_user_id();$this->boundary();$this->admit($shop,$actor);if($orderId<1)throw new \RuntimeException('identity',409);
   $snapshot=$this->load($shop,$orderId,$actor);
   $this->admit($shop,$actor);$again=$this->load($shop,$orderId,$actor);
   if($snapshot!==$again)throw new \RuntimeException('changed',409);
   $order=$snapshot['order'];$receipt=$snapshot['receipt'];$dedup=$snapshot['dedup'];$receiptHash=$receipt['result_hash'];$dedupHash=$dedup['response_hash']??'';
   $state='UNCERTAIN';$resultHash=null;
   if($receipt['processing_status']==='PROCESSED'&&($dedup['status']??null)==='PROCESSED'&&$receiptHash!==''&&$dedupHash!==''&&hash_equals($receiptHash,$dedupHash)&&$order['state']!=='RECEIVED'){$state='COMPLETE';$resultHash=$receiptHash;}
   elseif($receipt['processing_status']==='FAILED'&&($dedup['status']??null)==='FAILED')$state='FAILED';
   $public=array_intersect_key($order,array_flip(['id','external_order_reference','state','channel','environment','shop_reference','currency']));$public['id']=(int)$public['id'];
   $view=['intake_state'=>$state,'shop_key'=>$shop,'actor_id'=>$actor,'order'=>$public,'event_id'=>$receipt['event_id'],'body_sha256'=>$receipt['body_sha256'],'receipt_status'=>$receipt['processing_status'],'dedup_status'=>$dedup['status']??null,'result_hash'=>$resultHash,'correlation_basis'=>'STORED_ORDER_KEY','payload_order_binding'=>'NOT_RECORDED','snapshot_hash'=>Validator::hash($snapshot)]+self::flags();
   // Keep admission after every private read and after construction of the returned metadata.
   $this->boundary();$this->admit($shop,$actor);return $view;
  }catch(\Throwable $error){$status=in_array($error->getCode(),[403,503],true)?$error->getCode():409;return new WP_Error('paid_intake_evidence_unavailable','Stored local paid-intake evidence is unavailable.',['status'=>$status]+self::flags());}
 }
 private function load(string $shop,int $orderId,int $actor):array {
  global $wpdb;
  $orders=$this->rows($wpdb->prepare("SELECT o.* FROM ".Tables::orders()." o WHERE o.id=%d AND BINARY o.channel=BINARY 'etsy' AND BINARY o.environment=BINARY 'production' AND ".EtsyStoredShopScope::orderPredicate($shop,'o').' LIMIT 2',$orderId));
  if(count($orders)!==1)throw new \RuntimeException('order',409);$order=$orders[0];
  if((string)($order['id']??'')!==(string)$orderId||($order['channel']??null)!=='etsy'||($order['environment']??null)!=='production'||!in_array($order['state']??null,['RECEIVED','VALIDATED','REVIEW_REQUIRED','APPROVED','ON_HOLD','REJECTED','SUPERSEDED','CLOSED'],true)||!is_string($order['currency']??null)||preg_match('/^[A-Z]{3}$/D',$order['currency'])!==1)throw new \RuntimeException('order identity',409);
  foreach(['external_order_reference','shop_reference']as $field)if(!is_string($order[$field]??null)||$order[$field]===''||strlen($order[$field])>191||trim($order[$field])!==$order[$field]||preg_match('/[\x00-\x1f\x7f]/',$order[$field]))throw new \RuntimeException('stored identity',409);
  $key=$order['idempotency_key']??null;$prefix='etsy_webhook:';
  if(!is_string($key)||!str_starts_with($key,$prefix)||strlen($key)>191)throw new \RuntimeException('origin key',409);
  $event=substr($key,strlen($prefix));if($event===''||trim($event)!==$event||preg_match('/[\x00-\x1f\x7f]/',$event))throw new \RuntimeException('event identity',409);
  // Receipt scope is applied in SQL; a foreign-shop receipt is never a fallback or an empty success.
  $receipts=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE provider=%s AND event_id=%s AND BINARY shop_reference=BINARY %s LIMIT 2','etsy',$event,$order['shop_reference']));
  if(count($receipts)!==1)throw new \RuntimeException('receipt',409);$receipt=$receipts[0];
  if(($receipt['provider']??null)!=='etsy'||($receipt['event_id']??null)!==$event||($receipt['shop_reference']??null)!==$order['shop_reference']||($receipt['event_type']??null)!=='order.paid'||($receipt['verification_status']??null)!=='VERIFIED'||!in_array($receipt['processing_status']??null,['RECEIVED','VERIFIED','PROCESSED','FAILED'],true)||!$this->hash($receipt['body_sha256']??null)||!$this->hash($receipt['result_hash']??null,true))throw new \RuntimeException('receipt identity',409);
  $dedupKey=$prefix.hash('sha256',$event);$rows=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::idempotency().' WHERE operation_key=%s LIMIT 2',$dedupKey));
  if(count($rows)>1)throw new \RuntimeException('ambiguous dedup',409);$dedup=$rows[0]??null;
  if($dedup!==null&&(($dedup['operation_key']??null)!==$dedupKey||($dedup['operation_type']??null)!=='ETSY_WEBHOOK'||!in_array($dedup['status']??null,['RECEIVED','PROCESSED','FAILED'],true)||!$this->hash($dedup['response_hash']??null,true)))throw new \RuntimeException('dedup identity',409);
  if($receipt['result_hash']!==''&&($dedup['response_hash']??'')!==''&&!hash_equals($receipt['result_hash'],$dedup['response_hash']))throw new \RuntimeException('result conflict',409);
  return ['actor_id'=>$actor,'shop_key'=>$shop,'order'=>$order,'receipt'=>$receipt,'dedup'=>$dedup];
 }
 private function admit(string $shop,int $actor):void {
  if($shop!=='personalized_pod'||$actor<1||!is_user_logged_in()||get_current_user_id()!==$actor)throw new \RuntimeException('actor',403);
  $caps=['manage_digiforge_orders','manage_digiforge_pod','manage_digiforge'];foreach($caps as $cap){if(!current_user_can($cap))throw new \RuntimeException('capability',403);$access=ShopAccess::authorize($shop,$cap);if(is_wp_error($access))throw new \RuntimeException('signed scope',($access->get_error_data()['status']??403)===503?503:403);}
  // The signed helpers may read SQL. Finish with current native checks and no later source query.
  if(get_current_user_id()!==$actor||!is_user_logged_in())throw new \RuntimeException('actor changed',403);foreach($caps as $cap)if(!current_user_can($cap))throw new \RuntimeException('capability changed',403);
  if(get_current_user_id()!==$actor||!is_user_logged_in())throw new \RuntimeException('actor changed',403);
 }
 private function boundary():void {$state=$this->rows('SELECT @@session.in_transaction AS active,@@session.autocommit AS autocommit');if(count($state)!==1||(string)($state[0]['active']??'')!=='0'||(string)($state[0]['autocommit']??'')!=='1')throw new \RuntimeException('current read boundary',409);}
 private function rows(string $sql):array {global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);if(!is_array($rows)||$wpdb->last_error!=='')throw new \RuntimeException('query unavailable',503);return $rows;}
 private function hash(mixed $value,bool $empty=false):bool {return is_string($value)&&(($empty&&$value==='')||preg_match('/^[a-f0-9]{64}$/D',$value)===1);}
 private static function flags():array {return ['read_only'=>true,'buyer_identity_admission'=>'OWNER_VERIFICATION_REQUIRED','buyer_access_grant_permitted'=>false,'retry_permitted'=>false,'production_ready'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false];}
}
