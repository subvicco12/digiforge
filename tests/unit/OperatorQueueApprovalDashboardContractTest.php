<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OperatorQueueApprovalDashboardContractTest extends TestCase {
 public function testQueueDrilldownIsBoundedAndNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Queue/OperatorQueueReadModel.php');
  foreach(["FAILED","BLOCKED","HUMAN_REVIEW","DEAD_LETTER","min(100,","['read_only']=true","['retry_permitted']=false","['external_execution_authorized']=false"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('payload',$c);
 }
 public function testPortalKeepsAggregationAndShopContextReadOnly():void {
  $p=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');$a=file_get_contents(__DIR__.'/../../includes/Portal/U3ApprovalInbox.php');
  self::assertStringContainsString('Dashboard counts remain authoritative global counts unless explicitly labeled shop-scoped.',$p);
  self::assertStringContainsString('<td>NO</td><td>NO</td>',$p);
  self::assertStringContainsString('NO INFERRED APPROVAL',$a);
  self::assertStringContainsString('Every decision must be made in its dedicated governed workflow',$a);
 }
}