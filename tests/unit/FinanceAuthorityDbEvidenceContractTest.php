<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinanceAuthorityDbEvidenceContractTest extends TestCase {
 public function testFinanceAuthorityReadsAndTransitionsFailClosed():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Finance/Repository.php');
  foreach(['array|WP_Error|null','finance_evidence_unavailable','transition_failed',"\$wpdb->last_error=''"] as $n)self::assertStringContainsString($n,$s);
  self::assertGreaterThanOrEqual(4,substr_count($s,'if(is_wp_error($row))return $row;')+substr_count($s,'if(is_wp_error($tax))return $tax;'));
 }
}