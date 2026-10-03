<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductionBundleEvidenceContractTest extends TestCase {
 public function testBundleValidationFailsClosedOnReadAndWriteUncertainty():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Production/Repository.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"'bundle_evidence_unavailable'"));
  self::assertStringContainsString("'bundle_persistence_unavailable'",$s);
  self::assertStringContainsString('$qaTotalRaw===null',$s);
  self::assertStringContainsString('$qaBadRaw===null',$s);
  self::assertStringContainsString('$saved===false||!empty($wpdb->last_error)',$s);
 }
}