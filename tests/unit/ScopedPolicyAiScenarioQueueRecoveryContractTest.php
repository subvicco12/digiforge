<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ScopedPolicyAiScenarioQueueRecoveryContractTest extends TestCase {
 public function testScopedPolicyRequiresEveryParentScope():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/ScopedCapabilityPolicy.php');
  foreach(['$global&&$shop&&$workflow',"'global_disable_wins'=>true","'shop_disable_wins'=>true","'narrower_scope_cannot_override_parent'=>true","'external_execution_authorized'=>false"] as $v)self::assertStringContainsString($v,$c);
 }
 public function testAiScenariosAreBoundedPlanningOnly():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/HierarchicalPolicyReadModel.php');
  self::assertStringContainsString('foreach(ShopAiPlan::STAGES',$c);self::assertStringContainsString('foreach([1,5,10]',$c);self::assertStringContainsString("'planning_only'=>true",$c);
 }
 public function testIdempotencyDrilldownRedactsRawKeysAndDeniesReplay():void {
  $c=file_get_contents(__DIR__.'/../../includes/Queue/OperatorQueueReadModel.php');
  foreach(['min(100,',"hash('sha256'","unset($r['operation_key'])","'replay_permitted']=false","'retry_permitted']=false"] as $v)self::assertStringContainsString($v,$c);
 }
}