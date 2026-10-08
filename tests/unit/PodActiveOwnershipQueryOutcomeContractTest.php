<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodActiveOwnershipQueryOutcomeContractTest extends TestCase {
 public function testActiveOwnershipReadRejectsFalseQueryOutcomeAndKeepsPositiveRow():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/BusinessScopeRepository.php');
  $start=strpos($s,'public function assertActiveOwnershipForMapping(');
  $end=strpos($s,'public function approveMapping(', $start);
  self::assertNotFalse($start);self::assertNotFalse($end);
  $method=substr($s,$start,$end-$start);
  self::assertStringContainsString('$wpdb->flush();',$method);
  self::assertStringContainsString('$queryResult===false',$method);
  self::assertStringContainsString('isset($wpdb->last_result[0])',$method);
  self::assertStringNotContainsString('$wpdb->get_row(',$method);
  self::assertStringContainsString('BusinessScope::PERSONALIZED_POD',$method);
 }
}
