<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinancePeriodEvidenceAvailabilityContractTest extends TestCase{
 public function testPeriodCalculationFailsClosedBeforeMetricsPersistenceWhenLedgerEvidenceIsUnavailable():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Finance/Repository.php');
  self::assertStringContainsString("\$wpdb->last_error='';\$rows=\$wpdb->get_results",$s);
  self::assertStringContainsString("!is_array(\$rows)||!empty(\$wpdb->last_error)",$s);
  self::assertStringContainsString("'ledger_evidence_unavailable'",$s);
  self::assertStringContainsString("'Finance ledger evidence could not be read; period calculation is blocked.'",$s);
  $guard=strpos($s,"'ledger_evidence_unavailable'");
  $persist=strpos($s,"return \$this->insert(Tables::finance_periods()");
  self::assertIsInt($guard);self::assertIsInt($persist);self::assertLessThan($persist,$guard);
 }
 public function testSuccessfulEmptyLedgerRemainsAValidZeroPeriodInput():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Finance/Repository.php');
  self::assertStringNotContainsString("get_results(\$wpdb->prepare('SELECT entry_type, amount, base_amount, base_currency, currency, reconciliation_state FROM '.Tables::finance_ledger().' WHERE environment=%s AND effective_date BETWEEN %s AND %s',\$environment,\$start,\$end),ARRAY_A)?:[]",$s);
  self::assertStringContainsString("foreach(\$rows as\$row)",$s);
 }
}
