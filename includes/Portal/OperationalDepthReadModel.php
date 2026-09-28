<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\AI\CostKpiReadModel;
use DigiForge\Database\Tables;
use DigiForge\Orders\Repository as OrderRepository;
/** Read-only portal projection for shop-scoped AI policy/budget and provider fulfillment evidence. */
final class OperationalDepthReadModel {
 public function aiBudget(string $shop):array {
  global $wpdb;$shop=ShopOperationsReadModel::normalize($shop);$where=$shop===ShopOperationsReadModel::ALL?'':$wpdb->prepare(' WHERE shop_key=%s',$shop);
  $policies=$wpdb->get_results('SELECT shop_key,environment,currency,state,policy_hash,updated_at FROM '.Tables::shop_ai_policies().$where.' ORDER BY shop_key,environment',ARRAY_A)?:[];
  return ['shop'=>$shop,'policies'=>$policies,'cost'=>(new CostKpiReadModel())->snapshot($shop),'read_only'=>true,'external_execution_authorized'=>false];
 }
 public function fulfillmentProviders(int $limit=50):array {
  global $wpdb;$limit=max(1,min(100,$limit));
  $plans=$wpdb->get_results($wpdb->prepare('SELECT fp.id,fp.order_id,fp.plan_version,fp.provider,fp.payload_hash,fp.readiness_hash,fp.state,fp.approved_by,fp.approved_at,fp.environment,fp.updated_at,o.environment AS order_environment FROM '.Tables::fulfillment_plans().' fp LEFT JOIN '.Tables::orders().' o ON o.id=fp.order_id ORDER BY fp.id DESC LIMIT %d',$limit),ARRAY_A)?:[];
  $repo=new OrderRepository();$currentByOrder=[];
  foreach($plans as &$r){
   $current=null;
   if((string)($r['state']??'')==='APPROVED'){
    $orderId=(int)($r['order_id']??0);
    if($orderId>0){if(!array_key_exists($orderId,$currentByOrder)){$result=$repo->readiness($orderId);$currentByOrder[$orderId]=is_wp_error($result)?null:$result;}$current=$currentByOrder[$orderId];}
   }
   $r['evidence_state']=self::planEvidenceState($r,$current);
   $r['read_only']=true;$r['provider_execution_authorized']=false;$r['production_authorized']=false;$r['retry_permitted']=false;
  }unset($r);
  return $plans;
 }
 /** Current-action evidence classification; never authorizes an external action. */
 public static function planEvidenceState(array $plan,?array $current):string {
  if((string)($plan['state']??'')!=='APPROVED')return 'HISTORICAL_OR_DRAFT';
  $hash=(string)($plan['readiness_hash']??'');$payload=(string)($plan['payload_hash']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$hash)||!preg_match('/^[a-f0-9]{64}$/',$payload)||(int)($plan['approved_by']??0)<1||empty($plan['approved_at'])||(int)($plan['order_id']??0)<1||empty($plan['order_environment'])||(string)($plan['order_environment']??'')!==(string)($plan['environment']??''))return 'EVIDENCE_INVALID_REVIEW';
  if($current===null)return 'CURRENT_READINESS_UNAVAILABLE';
  if((int)($current['order_id']??0)!==(int)$plan['order_id']||empty($current['ready'])||!preg_match('/^[a-f0-9]{64}$/',(string)($current['hash']??''))||!hash_equals($hash,(string)$current['hash']))return 'STALE_RECHECK';
  return 'CURRENT_EVIDENCE_MATCH';
 }
}
