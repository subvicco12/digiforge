<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\AI\CostKpiReadModel;
use DigiForge\Database\Tables;
/** Read-only portal projection for shop-scoped AI policy/budget and provider fulfillment evidence. */
final class OperationalDepthReadModel {
 public function aiBudget(string $shop):array {
  global $wpdb;$shop=ShopOperationsReadModel::normalize($shop);$where=$shop===ShopOperationsReadModel::ALL?'':$wpdb->prepare(' WHERE shop_key=%s',$shop);
  $policies=$wpdb->get_results('SELECT shop_key,environment,currency,state,policy_hash,updated_at FROM '.Tables::shop_ai_policies().$where.' ORDER BY shop_key,environment',ARRAY_A)?:[];
  return ['shop'=>$shop,'policies'=>$policies,'cost'=>(new CostKpiReadModel())->snapshot($shop),'read_only'=>true,'external_execution_authorized'=>false];
 }
 public function fulfillmentProviders(int $limit=50):array {
  global $wpdb;$limit=max(1,min(100,$limit));
  $plans=$wpdb->get_results($wpdb->prepare('SELECT id,order_id,plan_version,provider,payload_hash,readiness_hash,state,approved_by,approved_at,environment,updated_at FROM '.Tables::fulfillment_plans().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A)?:[];
  return array_map(static function(array $r):array{$r['read_only']=true;$r['provider_execution_authorized']=false;$r['production_authorized']=false;$r['retry_permitted']=false;return $r;},$plans);
 }
}