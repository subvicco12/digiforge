<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class FinancePeriodOrderDenominatorContractTest extends TestCase
{
 public function testPeriodUsesDistinctOrderBoundRevenueEvidenceForOrderCountAndAov():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Finance/Repository.php');
  self::assertStringContainsString('SELECT source_type, source_id, entry_type, amount, base_amount, base_currency, currency, reconciliation_state',$s);
  self::assertStringContainsString("\$row['entry_type']??''", $s);
  self::assertStringContainsString("==='REVENUE'", $s);
  self::assertStringContainsString("\$row['source_type']??''", $s);
  self::assertStringContainsString("==='order'", $s);
  self::assertStringContainsString("\$revenueOrderIds[(int)\$row['source_id']]=true", $s);
  self::assertStringContainsString("\$metrics['order_count']=count(\$revenueOrderIds)", $s);
  self::assertStringContainsString("\$metrics['average_order_value']=\$metrics['order_count']>0?round(\$metrics['gross_revenue']/\$metrics['order_count'],4):0.0", $s);
 }
 public function testPeriodDoesNotCountManualOrNonRevenueLedgerRowsAsOrders():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Finance/Repository.php');
  $guard=strpos($s,"(string)(\$row['entry_type']??'')==='REVENUE'&&(string)(\$row['source_type']??'')==='order'&&(int)(\$row['source_id']??0)>0");
  $count=strpos($s,"\$metrics['order_count']=count(\$revenueOrderIds)");
  self::assertIsInt($guard);self::assertIsInt($count);self::assertLessThan($count,$guard);
 }
}
