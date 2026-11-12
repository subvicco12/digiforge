<?php
declare(strict_types=1);
namespace DigiForge\Orders;
use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyStoredShopScope;
use DigiForge\Security\Logger;
use WP_Error;
/** Append-only local support cases. A case decision never authorizes replacement production. */
final class ReplacementReviewRepository {
 public const REQUESTED='order_replacement_review_requested';
 public const DECIDED='order_replacement_review_decided';
 public static function fingerprint(array $order,array $lines):string {return hash('sha256',Validator::canonicalJson(['order'=>$order,'lines'=>$lines]));}
 public function request(int $orderId,string $shop,string $key):array|WP_Error {
  if(!$this->authorized())return $this->error('access','Order operator access required.');
  if(!preg_match('/^[A-Za-z0-9._:-]{1,160}$/D',$key))return $this->error('key','Bounded local request identity required.');
  return $this->locked($orderId,function()use($orderId,$shop,$key){
   $source=$this->source($orderId,$shop);if(is_wp_error($source))return $source;$hash=$source['source_hash'];global $wpdb;$wpdb->last_error='';
   $rows=$wpdb->get_results($wpdb->prepare('SELECT id,context,actor_id FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s ORDER BY id DESC',self::REQUESTED,'order',(string)$orderId),ARRAY_A);if(!is_array($rows)||!empty($wpdb->last_error))return $this->error('unavailable','Support request evidence unavailable.');
   $keyHash=hash('sha256',$key);foreach($rows as $row){$context=json_decode((string)$row['context'],true);if(is_array($context)&&($context['key_hash']??null)===$keyHash){if((int)($row['actor_id']??0)<1)return $this->error('integrity','Stored support request lacks human actor evidence.');if(($context['source_hash']??null)!==$hash)return $this->error('replay_conflict','Support key binds different order/line evidence.');return ['case_id'=>(int)$row['id'],'order_id'=>$orderId,'source_hash'=>$hash,'idempotent_replay'=>true,'external_execution_authorized'=>false];}}
   if(!Logger::write(self::REQUESTED,['schema'=>'replacement-review-v1','order_id'=>$orderId,'shop_reference'=>$source['order']['shop_reference'],'environment'=>$source['order']['environment'],'source_hash'=>$hash,'key_hash'=>$keyHash,'line_count'=>count($source['lines']),'order_state'=>$source['order']['state'],'external_execution_authorized'=>false,'refund_authorized'=>false],'order',(string)$orderId))return $this->error('persistence','Support case could not be persisted.');
   $wpdb->last_error='';$confirmed=$wpdb->get_row($wpdb->prepare('SELECT id,context,actor_id FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s ORDER BY id DESC LIMIT 1',self::REQUESTED,'order',(string)$orderId),ARRAY_A);$context=is_array($confirmed)?json_decode((string)$confirmed['context'],true):null;
   if(!empty($wpdb->last_error)||!is_array($confirmed)||(int)($confirmed['actor_id']??0)<1||!is_array($context)||($context['key_hash']??null)!==$keyHash||($context['source_hash']??null)!==$hash)return $this->error('confirmation','Support case write confirmation unavailable; no automatic retry.');
   return ['case_id'=>(int)$confirmed['id'],'order_id'=>$orderId,'source_hash'=>$hash,'external_execution_authorized'=>false];
  });
 }
 public function decide(int $caseId,string $shop,string $decision):array|WP_Error {
  if(!$this->authorized())return $this->error('access','Order operator access required.');
  if(!in_array($decision,['APPROVED_LOCAL_REVIEW','REJECTED_LOCAL_REVIEW'],true))return $this->error('decision','Valid local evidence decision required.');
  $case=$this->readCase($caseId);if(is_wp_error($case))return $case;$orderId=(int)$case['order_id'];
  return $this->locked($orderId,function()use($caseId,$case,$shop,$decision,$orderId){
   $source=$this->source($orderId,$shop);if(is_wp_error($source))return $source;if(!hash_equals((string)$case['source_hash'],$source['source_hash']))return $this->error('stale','Order/line evidence changed; request a new exact support case.');
   $prior=$this->decision($caseId,$orderId);if(is_wp_error($prior))return $prior;if($prior!==null){if(($prior['decision']??'')!==$decision)return $this->error('immutable','Support case already has an immutable human decision.');return $prior+['idempotent_replay'=>true,'external_execution_authorized'=>false];}
   if(!Logger::write(self::DECIDED,['schema'=>'replacement-review-v1','case_id'=>$caseId,'order_id'=>$orderId,'source_hash'=>$source['source_hash'],'decision'=>$decision,'external_execution_authorized'=>false,'refund_authorized'=>false],'order',(string)$orderId))return $this->error('persistence','Support decision could not be persisted.');
   $confirmed=$this->decision($caseId,$orderId);if(is_wp_error($confirmed))return $confirmed;if(!is_array($confirmed)||($confirmed['decision']??null)!==$decision||($confirmed['source_hash']??null)!==$source['source_hash'])return $this->error('confirmation','Support decision write confirmation unavailable; no automatic retry.');
   return ['case_id'=>$caseId,'order_id'=>$orderId,'source_hash'=>$source['source_hash'],'decision'=>$decision,'external_execution_authorized'=>false];
  });
 }
 public function snapshot(?string $shop=null,int $limit=50):array {
  $base=['query_state'=>'UNAVAILABLE','items'=>[],'external_execution_authorized'=>false,'read_only'=>true];if(!$this->authorized())return array_replace($base,['query_state'=>'ACCESS_DENIED']);global $wpdb;$where=$shop===null?'':' AND '.EtsyStoredShopScope::orderPredicate($shop,'o');$wpdb->last_error='';
  $rows=$wpdb->get_results($wpdb->prepare('SELECT a.id,a.context,a.actor_id,a.created_at,o.id AS order_id,o.shop_reference,o.environment FROM '.Tables::audit_log().' a INNER JOIN '.Tables::orders().' o ON BINARY a.object_id=BINARY CAST(o.id AS CHAR) WHERE a.event_type=%s AND a.object_type=%s'.$where.' ORDER BY a.id DESC LIMIT %d',self::REQUESTED,'order',max(1,min(100,$limit))),ARRAY_A);
  if(!is_array($rows)||!empty($wpdb->last_error))return $base;$items=[];foreach($rows as $row){$case=json_decode((string)$row['context'],true);if((int)($row['actor_id']??0)<1||!$this->validCase($case,(int)$row['order_id']))continue;$decision=$this->decision((int)$row['id'],(int)$row['order_id']);if(is_wp_error($decision))return $base;$source=$this->source((int)$row['order_id'],$shop??'all');$state=is_wp_error($source)?'SOURCE_UNAVAILABLE':(!hash_equals($case['source_hash'],$source['source_hash'])?'SOURCE_CHANGED':($decision['decision']??'REQUESTED'));$items[]=['case_id'=>(int)$row['id'],'order_id'=>(int)$row['order_id'],'shop_reference'=>$row['shop_reference'],'environment'=>$row['environment'],'source_hash'=>$case['source_hash'],'line_count'=>(int)$case['line_count'],'source_order_state'=>$case['order_state'],'state'=>$state,'external_execution_authorized'=>false];}
  return array_replace($base,['query_state'=>'AVAILABLE','items'=>$items]);
 }
 private function source(int $orderId,string $shop):array|WP_Error {
  if(!in_array($shop,['all','digital','personalized_pod','standard_pod','jewelry'],true))return $this->error('scope','Explicit existing shop scope required.');global $wpdb;$where=$shop==='all'?'':' AND '.EtsyStoredShopScope::orderPredicate($shop,'o');$wpdb->last_error='';
  $order=$wpdb->get_row($wpdb->prepare('SELECT o.id,o.shop_reference,o.environment,o.channel,o.state,o.approved_by,o.approved_at FROM '.Tables::orders().' o WHERE o.id=%d'.$where.' LIMIT 1',$orderId),ARRAY_A);if(!empty($wpdb->last_error))return $this->error('source','Order source evidence unavailable.');if(!is_array($order))return $this->error('scope','Order is unavailable in this selected shop.');
  $wpdb->last_error='';$lines=$wpdb->get_results($wpdb->prepare('SELECT id,product_version_id,listing_id,provider_mapping_id,validation_status,quantity FROM '.Tables::order_line_items().' WHERE order_id=%d ORDER BY id',$orderId),ARRAY_A);if(!is_array($lines)||!empty($wpdb->last_error)||$lines===[])return $this->error('source','Exact persisted order line evidence required.');return ['order'=>$order,'lines'=>$lines,'source_hash'=>self::fingerprint($order,$lines)];
 }
 private function readCase(int $id):array|WP_Error {global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT context,object_id,actor_id FROM '.Tables::audit_log().' WHERE id=%d AND event_type=%s AND object_type=%s LIMIT 1',$id,self::REQUESTED,'order'),ARRAY_A);if(!empty($wpdb->last_error)||!is_array($row)||(int)($row['actor_id']??0)<1)return $this->error('case','Support case evidence unavailable.');$case=json_decode((string)$row['context'],true);return $this->validCase($case,(int)$row['object_id'])?$case:$this->error('case','Support case evidence invalid.');}
 private function validCase(mixed $case,int $orderId):bool {return is_array($case)&&($case['schema']??null)==='replacement-review-v1'&&(int)($case['order_id']??0)===$orderId&&preg_match('/^[a-f0-9]{64}$/D',(string)($case['source_hash']??''))===1&&isset($case['line_count'],$case['order_state']);}
 private function decision(int $caseId,int $orderId):array|WP_Error|null {global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT context,actor_id FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s ORDER BY id DESC',self::DECIDED,'order',(string)$orderId),ARRAY_A);if(!is_array($rows)||!empty($wpdb->last_error))return $this->error('decision','Support decision evidence unavailable.');foreach($rows as $row){$c=json_decode((string)$row['context'],true);if(is_array($c)&&($c['schema']??'')==='replacement-review-v1'&&(int)($c['case_id']??0)===$caseId){if((int)($row['actor_id']??0)<1||(int)($c['order_id']??0)!==$orderId||!in_array($c['decision']??'', ['APPROVED_LOCAL_REVIEW','REJECTED_LOCAL_REVIEW'],true)||preg_match('/^[a-f0-9]{64}$/D',(string)($c['source_hash']??''))!==1)return $this->error('decision','Persisted support decision integrity invalid.');return $c;}}return null;}
 private function locked(int $orderId,callable $work):array|WP_Error {global $wpdb;$lock='digiforge-support-'.$orderId;$wpdb->last_error='';$ok=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));if(!empty($wpdb->last_error)||(string)$ok!=='1')return $this->error('busy','Support case accounting busy.');try{$result=$work();}catch(\Throwable){$result=$this->error('uncertain','Support persistence uncertain; no automatic retry.');}finally{$wpdb->last_error='';$release=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$failed=!empty($wpdb->last_error)||(string)$release!=='1';}return $failed?$this->error('uncertain','Support lock release uncertain; no automatic retry.'):$result;}
 private function authorized():bool {return is_user_logged_in()&&current_user_can('manage_digiforge_orders');}
 private function error(string $code,string $message):WP_Error {return new WP_Error('replacement_review_'.$code,$message,['status'=>409,'external_execution_authorized'=>false,'refund_authorized'=>false,'retry_permitted'=>false]);}
}
