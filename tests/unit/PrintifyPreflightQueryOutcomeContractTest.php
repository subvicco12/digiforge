<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyPreflightQueryOutcomeContractTest extends TestCase {
 public function testPackageMappingAndTemplateReadsRejectFalseQueryOutcomes():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyProductionPreflight.php');
  self::assertSame(3,substr_count($s,'$queryResult===false'));
  self::assertSame(3,substr_count($s,'$wpdb->flush();'));
  self::assertSame(3,substr_count($s,'isset($wpdb->last_result[0])'));
  self::assertStringNotContainsString('$wpdb->get_row(',$s);
  self::assertStringContainsString("'ready_for_external_execution'=>false",$s);
 }
}
