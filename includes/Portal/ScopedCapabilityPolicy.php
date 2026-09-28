<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Core\Settings;
/** Pure fail-closed evaluator for Global -> Shop -> Workflow capability policy. Evidence only. */
final class ScopedCapabilityPolicy {
 public static function evaluate(string $capability,array $shopPolicy=[],array $workflowPolicy=[]):array {
  $cap=sanitize_key($capability);$global=Settings::is_enabled($cap);
  $shop=array_key_exists($cap,$shopPolicy)?$shopPolicy[$cap]===true:false;
  $workflow=array_key_exists($cap,$workflowPolicy)?$workflowPolicy[$cap]===true:false;
  $effective=$global&&$shop&&$workflow;
  return ['capability'=>$cap,'global_enabled'=>$global,'shop_enabled'=>$shop,'workflow_enabled'=>$workflow,'effective_enabled'=>$effective,'global_disable_wins'=>true,'shop_disable_wins'=>true,'narrower_scope_cannot_override_parent'=>true,'read_only'=>true,'external_execution_authorized'=>false];
 }
}