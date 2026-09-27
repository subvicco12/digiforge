<?php
declare(strict_types=1);
namespace DigiForge\AI;

final class ShopAiPlan {
 public const STAGES=['research','shortlist','develop','listing_prepare','render','qa'];
 /** @return array<string,mixed> */
 public static function evaluate(array $policy,array $actual=[]):array {
  $shop=sanitize_key((string)($policy['shop_key']??''));if($shop==='')throw new \InvalidArgumentException('shop_key is required');
  $currency=strtoupper(trim((string)($policy['currency']??'INR')));if(!preg_match('/^[A-Z]{3}$/',$currency))throw new \InvalidArgumentException('currency is invalid');
  $monthly=max(0,(float)($policy['monthly_budget']??0));$estimated=0.0;$stages=[];$blocked=false;
  foreach(self::STAGES as $stage){$p=(array)($policy['stages'][$stage]??[]);$limit=max(0,(int)($p['limit']??0));$unit=max(0,(float)($p['estimated_unit_cost']??0));$used=max(0,(int)($actual[$stage]['count']??0));$cost=max(0,(float)($actual[$stage]['cost']??0));$estimated+=$limit*$unit;$stageBlocked=$limit>0&&$used>=$limit;$blocked=$blocked||$stageBlocked;$stages[$stage]=['limit'=>$limit,'used'=>$used,'remaining'=>max(0,$limit-$used),'estimated_unit_cost'=>$unit,'actual_cost'=>$cost,'quantity_ceiling_reached'=>$stageBlocked];}
  $actualCost=array_sum(array_column($stages,'actual_cost'));$budgetReached=$monthly>0&&$actualCost>=$monthly;
  return ['shop_key'=>$shop,'currency'=>$currency,'monthly_budget'=>$monthly,'estimated_plan_cost'=>round($estimated,6),'actual_cost'=>round($actualCost,6),'budget_remaining'=>$monthly>0?max(0,round($monthly-$actualCost,6)):null,'budget_ceiling_reached'=>$budgetReached,'quantity_ceiling_reached'=>$blocked,'execution_allowed'=>!$blocked&&!$budgetReached,'stages'=>$stages];
 }
}