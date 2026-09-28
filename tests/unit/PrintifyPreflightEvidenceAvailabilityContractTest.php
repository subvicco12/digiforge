<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyPreflightEvidenceAvailabilityContractTest extends TestCase {
 public function testPreflightDatabaseFailuresAreExplicitlyUnavailable():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/PrintifyProductionPreflight.php');
  self::assertStringContainsString('printify_preflight_evidence_unavailable',$c);
  self::assertGreaterThanOrEqual(4,substr_count($c,'Printify preflight evidence is unavailable; execution remains blocked.'));
  self::assertStringNotContainsString('(int)$wpdb->get_var',$c);
  self::assertStringContainsString('!empty($wpdb->last_error)||!is_numeric($areasRaw)',$c);
 }
 public function testUnavailablePreflightNeverAuthorizesExternalExecution():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/PrintifyProductionPreflight.php');
  self::assertGreaterThanOrEqual(4,substr_count($c,"'external_execution_authorized'=>false"));
  self::assertStringContainsString("'ready_for_external_execution'=>false",$c);
 }
}
