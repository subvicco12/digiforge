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
  $policy=null;$policyState=$shop===ShopOperationsReadModel::ALL?'SHOP_SCOPE_REQUIRED':'UNAVAILABLE';$policyError='';if($shop!==ShopOperationsReadModel::ALL){$p=(new ShopAiGovernanceRepository())->evaluate($shop);if(is_wp_error($p)){$policyError=(string)$p->get_error_code();}else{$policy=$p;$policyState='AVAILABLE';}}
  return ['shop'=>$shop,'global'=>$global,'effective_capabilities'=>$caps,'shop_ai_policy'=>$policy,'shop_ai_policy_query_state'=>$policyState,'shop_ai_policy_error'=>$policyError,'policy_evidence_available'=>$policyState==='AVAILABLE','global_disable_wins'=>true,'narrower_scope_cannot_override_parent'=>true,'read_only'=>true,'external_execution_authorized'=>false];
 }
 public function plan(string $shop,string $stage,int $quantity,float $estimatedCost):array {
  $shop=ShopOperationsReadModel::normalize($shop);if($shop===ShopOperationsReadModel::ALL)return ['status'=>'SHOP_SCOPE_REQUIRED','execution_allowed'=>false,'planning_only'=>true,'external_execution_authorized'=>false];
  $projection=(new ShopAiGovernanceRepository())->evaluate($shop);if(is_wp_error($projection))return ['status'=>'POLICY_UNAVAILABLE','policy_error'=>(string)$projection->get_error_code(),'execution_allowed'=>false,'planning_only'=>true,'external_execution_authorized'=>false];
  $plan=ShopAiPlan::preflight($projection,$stage,$quantity,$estimatedCost);$plan['status']='PLAN_EVALUATED';$plan['planning_only']=true;$plan['external_execution_authorized']=false;return $plan;
 }
 public function scenarios(string $shop):array {
  $shop=ShopOperationsReadModel::normalize($shop);$rows=[];foreach(ShopAiPlan::STAGES as $stage){foreach([1,5,10] as $quantity){$rows[]=$this->plan($shop,$stage,$quantity,0.0)+['scenario_quantity'=>$quantity];}}
  $unavailable=count(array_filter($rows,static fn(array $row):bool=>(string)($row['status']??'')!=='PLAN_EVALUATED'));
  return ['shop'=>$shop,'query_state'=>$unavailable===0?'AVAILABLE':'PARTIAL_UNAVAILABLE','unavailable_scenarios'=>$unavailable,'scenarios'=>$rows,'planning_only'=>true,'external_execution_authorized'=>false];
 }
}