<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OrderAlertReconciliationDrilldownContractTest extends TestCase {
 public function testOrderDiscrepanciesAreBoundedEvidenceOnly():void {
  $c=file_get_contents(__DIR__.'/../../includes/Orders/ReconciliationReadModel.php');
  foreach(['min(100,','validation_status','provider_mapping_id',"'read_only']=true","'fulfillment_authorized']=false","'retry_permitted']=false","'external_execution_authorized']=false"] as $v) self::assertStringContainsString($v,$c);
 }
 public function testAlertsNavigateOnlyToInternalGovernedViews():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['alertWorkflowUrl',"'listings'","'pod_personalized'","'orders'","'attention'"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringContainsString('discrepancies ',$c);
  self::assertStringContainsString('recentAttention(50)',$c);
 }
}