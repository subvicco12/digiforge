<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinanceListEvidenceAvailabilityContractTest extends TestCase{
 public function testListQueriesExposeUnavailableEvidenceInsteadOfFalseEmptyState():void{
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/Finance/Repository.php');
  self::assertStringContainsString("['items'=>null,'pagination'=>null,'query_state'=>'UNAVAILABLE']",$r);
  self::assertGreaterThanOrEqual(2,substr_count($r,"\$wpdb->last_error=''"));
  self::assertStringContainsString("!is_array(\$items)||!empty(\$wpdb->last_error)",$r);
  self::assertStringContainsString("!is_numeric(\$total)||!empty(\$wpdb->last_error)",$r);
  self::assertStringContainsString("'query_state'=>'AVAILABLE'",$r);
 }
 public function testRestListReturnsServiceUnavailableRatherThanEmptyCollection():void{
  $c=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/FinanceController.php');
  self::assertStringContainsString("'finance_evidence_unavailable'",$c);
  self::assertStringContainsString("],503)",$c);
 }
}
