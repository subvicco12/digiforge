<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OrderListEvidenceAvailabilityContractTest extends TestCase{
 public function testOrderListDoesNotCollapseUnavailableEvidenceToEmpty():void{
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/Repository.php');
  self::assertStringContainsString("['items'=>null,'pagination'=>null,'query_state'=>'UNAVAILABLE']",$r);
  self::assertStringContainsString("!is_array(\$items)||!empty(\$wpdb->last_error)",$r);
  self::assertStringContainsString("!is_numeric(\$total)||!empty(\$wpdb->last_error)",$r);
  self::assertStringContainsString("'query_state'=>'AVAILABLE'",$r);
 }
 public function testOrderRestListReturnsServiceUnavailableForUnavailableEvidence():void{
  $c=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/OrderController.php');
  self::assertStringContainsString("'order_evidence_unavailable'",$c);
  self::assertStringContainsString("],503)",$c);
 }
}
