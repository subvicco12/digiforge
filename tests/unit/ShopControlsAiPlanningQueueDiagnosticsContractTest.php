<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ShopControlsAiPlanningQueueDiagnosticsContractTest extends TestCase {
 public function testHierarchyFailsClosedAndPlanningNeverAuthorizesExternalExecution():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/HierarchicalPolicyReadModel.php');
  foreach(["'global_disable_wins'=>true","'narrower_scope_cannot_override_parent'=>true","'planning_only'=>true","'external_execution_authorized'=>false","'execution_allowed'=>false"] as $v)self::assertStringContainsString($v,$c);
  self::assertStringContainsString('ShopAiPlan::preflight',$c);
 }
 public function testQueueDiagnosticsAreReadOnlyAndNeverReplay():void {
  $c=file_get_contents(__DIR__.'/../../includes/Queue/OperatorQueueReadModel.php');
  foreach(["lease_expires_at<%s","DEAD_LETTER","rate limit","429","Tables::idempotency()","'replay_permitted'=>false","'retry_permitted'=>false","'external_execution_authorized'=>false"] as $v)self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('UPDATE ',strtoupper($c));
 }
 public function testPortalExplainsHierarchyAndReplayBoundary():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('Global disable always wins',$c);
  self::assertStringContainsString('narrower shop/workflow scope cannot override a disabled parent',$c);
  self::assertStringContainsString('Orphaned expired leases',$c);
  self::assertStringContainsString('Replay permitted',$c);
 }
}