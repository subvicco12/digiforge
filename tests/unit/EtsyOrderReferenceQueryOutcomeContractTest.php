<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOrderReferenceQueryOutcomeContractTest extends TestCase {
 public function testOrderReferenceReadRejectsFalseQueryOutcomeAndStaleResults():void {
  $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/Repository.php');
  $start=strpos($source,'function findByExternalReference(');
  self::assertNotFalse($start);
  $end=strpos($source,'public function addLineItem(', $start);
  self::assertNotFalse($end);
  $method=substr($source,$start,$end-$start);
  self::assertStringContainsString('$wpdb->flush();',$method);
  self::assertStringContainsString('$queryResult===false',$method);
  self::assertStringContainsString('$wpdb->last_result[0]',$method);
  self::assertStringNotContainsString('$wpdb->get_row(',$method);
 }
}
