<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ReconciliationOperatorDrilldownContractTest extends TestCase {
 public function testEtsyProjectionIsBoundedReadOnlyAndNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Listings/EtsyReconciliationOperatorReadModel.php');
  foreach(["min(100,","UNKNOWN","RECONCILIATION_REQUIRED","RECONCILE_BEFORE_ANY_RETRY","['read_only']=true","['retry_permitted']=false","['external_execution_authorized']=false"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('reconciliation_evidence',$c);
 }
 public function testAuditSurfaceOnlyNavigatesToGovernedWorkflows():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Audit / Reconciliation','Open Listings & Etsy','Open POD workflow','This view cannot retry, publish, produce, fulfill, refund, change tax state, or move money.','reconciliation OK'] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('Retry operation',$c);
 }
}