<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\AI\ShopAiGovernanceRepository;
use DigiForge\AI\ShopAiPlan;
use DigiForge\Core\Settings;
/** Fail-closed visibility of global ceilings, shop AI policy and planning evidence. */
final class HierarchicalPolicyReadModel {
 public function snapshot(string $shop):array {
  $shop=ShopOperationsReadModel::normalize($shop);$global=['stop_all'=>(bool)Settings::get('stop_all',true),'activation_authorized'=>(bool)Settings::get('activation_authorized',false),'automation_armed'=>(bool)Settings::get('automation_armed',false)];
  $caps=[];foreach(['research','ai','product_development','etsy_draft','printify','gelato','etsy_publish','order_automation','gst_automation'] as $cap)$caps[$cap]=Settings::is_enabled($cap);
  $policy=null;if($shop!==ShopOperationsReadModel::ALL){$p=(new ShopAiGovernanceRepository())->evaluate($shop);if(!is_wp_error($p))$policy=$p;}
  return ['shop'=>$shop,'global'=>$global,'effective_capabilities'=>$caps,'shop_ai_policy'=>$policy,'global_disable_wins'=>true,'narrower_scope_cannot_override_parent'=>true,'read_only'=>true,'external_execution_authorized'=>false];
 }
 public function plan(string $shop,string $stage,int $quantity,float $estimatedCost):array {
  $shop=ShopOperationsReadModel::normalize($shop);if($shop===ShopOperationsReadModel::ALL)return ['status'=>'SHOP_SCOPE_REQUIRED','execution_allowed'=>false,'planning_only'=>true,'external_execution_authorized'=>false];
  $projection=(new ShopAiGovernanceRepository())->evaluate($shop);if(is_wp_error($projection))return ['status'=>'POLICY_UNAVAILABLE','execution_allowed'=>false,'planning_only'=>true,'external_execution_authorized'=>false];
  $plan=ShopAiPlan::preflight($projection,$stage,$quantity,$estimatedCost);$plan['status']='PLAN_EVALUATED';$plan['planning_only']=true;$plan['external_execution_authorized']=false;return $plan;
 }
}