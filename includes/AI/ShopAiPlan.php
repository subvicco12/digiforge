<?php
declare(strict_types=1);
namespace DigiForge\AI;

final class ShopAiPlan {
 public const STAGES=['research','shortlist','develop','listing_prepare','render','qa'];
 /** @return array<string,mixed> */
 public static function evaluate(array $policy,array $actual=[]):array {
  $shop=sanitize_key((string)($policy['shop_key']??''));if($shop==='')throw new \InvalidArgumentException('shop_key is required');
  $currency=strtoupper(trim((string)($policy['currency']??'INR')));if(!preg_match('/^[A-Z]{3}$/',$currency))throw new \InvalidArgumentException('currency is invalid');
  $budgets=(array)($policy['budgets']??[]);
  $run=max(0,(float)($budgets['run']??$policy['run_budget']??0));$daily=max(0,(float)($budgets['day']??$policy['daily_budget']??0));$monthly=max(0,(float)($budgets['month']??$policy['monthly_budget']??0));
  $estimated=0.0;$stages=[];$blocked=false;
  foreach(self::STAGES as $stage){$p=(array)($policy['stages'][$stage]??[]);$stageBudget=$p['monthly_budget']??null;if($stageBudget!==null&&(!is_string($stageBudget)||!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,6})?$/D',$stageBudget)))throw new \InvalidArgumentException('Stage monthly_budget must be an exact nonnegative decimal string.');if(array_key_exists('task_binding',$p))StageTaskBinding::validate($p['task_binding']);$limit=max(0,(int)($p['limit']??0));$unit=max(0,(float)($p['estimated_unit_cost']??0));$used=max(0,(int)($actual['month'][$stage]['count']??$actual[$stage]['count']??0));$cost=max(0,(float)($actual['month'][$stage]['cost']??$actual[$stage]['cost']??0));$estimated+=$limit*$unit;$stageBlocked=$limit>0&&$used>=$limit;$blocked=$blocked||$stageBlocked;$stages[$stage]=['limit'=>$limit,'used'=>$used,'remaining'=>max(0,$limit-$used),'estimated_unit_cost'=>$unit,'actual_cost'=>$cost,'quantity_ceiling_reached'=>$stageBlocked,'monthly_budget'=>$stageBudget]+(array_key_exists('task_binding',$p)?['task_binding'=>$p['task_binding']]:[]);}
  $costs=['run'=>max(0,(float)($actual['costs']['run']??0)),'day'=>max(0,(float)($actual['costs']['day']??0)),'month'=>max(0,(float)($actual['costs']['month']??array_sum(array_column($stages,'actual_cost'))))];
  $limits=['run'=>$run,'day'=>$daily,'month'=>$monthly];$reached=[];$remaining=[];
  $admissionProjection=['budgets'=>$limits,'stages'=>$stages];foreach(self::STAGES as $stage)$stages[$stage]['monetary_admission_state']=self::monetaryAdmissionState($admissionProjection,$stage);
  foreach($limits as $period=>$limit){$reached[$period]=$limit>0&&$costs[$period]>=$limit;$remaining[$period]=$limit>0?max(0,round($limit-$costs[$period],6)):null;}
  $budgetReached=in_array(true,$reached,true);
  // Quantity exhaustion is stage-local; preflight checks the requested stage.
  return ['shop_key'=>$shop,'currency'=>$currency,'budgets'=>$limits,'monthly_budget'=>$monthly,'estimated_plan_cost'=>round($estimated,6),'actual_cost'=>round($costs['month'],6),'actual_costs'=>$costs,'budget_remaining'=>$remaining['month'],'budget_remaining_by_period'=>$remaining,'budget_ceiling_reached'=>$budgetReached,'budget_ceiling_reached_by_period'=>$reached,'quantity_ceiling_reached'=>$blocked,'execution_allowed'=>!$budgetReached,'stages'=>$stages];
 }
 public static function requiresMonetaryEvidence(array $projection,string $stage):bool {
  if(($projection['stages'][$stage]['monthly_budget']??null)!==null)return true;
  foreach((array)($projection['budgets']??[]) as $budget)if((float)$budget>0)return true;
  return false;
 }
 /** Admission metadata is separate from quantity planning and never grants provider authority. */
 public static function monetaryAdmissionState(array $projection,string $stage):string {
  $budget=$projection['stages'][$stage]['monthly_budget']??null;
  if(is_string($budget)&&preg_match('/^0(?:\.0{1,6})?$/D',$budget))return 'EXPLICIT_ZERO_CEILING';
  return self::requiresMonetaryEvidence($projection,$stage)?'CEILING_CONFIGURED_QUOTE_REQUIRED':'MISSING_CEILING';
 }
 /** @return array<string,mixed> */
 public static function preflight(array $projection,string $stage,int $proposedQuantity,float $proposedCost):array {
  $stage=sanitize_key($stage);$quantity=max(0,$proposedQuantity);$cost=max(0,$proposedCost);$reasons=[];
  if(!in_array($stage,self::STAGES,true))$reasons[]='invalid_stage';
  $s=(array)($projection['stages'][$stage]??[]);if($stage!==''&&isset($s['remaining'])&&$quantity>(int)$s['remaining'])$reasons[]='stage_quantity_ceiling';
  foreach(['run','day','month'] as $period){$remaining=$projection['budget_remaining_by_period'][$period]??null;if($remaining!==null&&$cost>(float)$remaining)$reasons[]=$period.'_budget_ceiling';}
  if(empty($projection['execution_allowed']))$reasons[]='existing_ceiling_reached';
  $reasons=array_values(array_unique($reasons));
  return ['stage'=>$stage,'monetary_admission_state'=>self::monetaryAdmissionState($projection,$stage),'proposed_quantity'=>$quantity,'proposed_cost'=>$cost,'approval_required'=>$reasons!==[],'execution_allowed'=>$reasons===[],'reasons'=>$reasons,'external_execution_performed'=>false];
 }

}
