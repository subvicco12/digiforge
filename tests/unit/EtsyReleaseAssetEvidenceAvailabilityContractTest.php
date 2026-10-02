<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyReleaseAssetEvidenceAvailabilityContractTest extends TestCase{
 public function testImageExecutionSeparatesUnavailableReleaseAndLineageEvidenceFromInvalidEvidence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  foreach(['Bound release-bundle evidence could not be read.','Approved release-bundle evidence could not be read.','Asset revision evidence could not be read.','Derived image source revision evidence could not be read.'] as $n)self::assertStringContainsString($n,$s);
  self::assertGreaterThanOrEqual(4,substr_count($s,"'digiforge_etsy_image_evidence_unavailable'"));
  self::assertStringContainsString("\$releaseBundleRaw=\$wpdb->get_var",$s);
  self::assertStringContainsString("is_numeric(\$releaseBundleRaw)?(int)\$releaseBundleRaw:0",$s);
  foreach(['digiforge_etsy_image_bundle','digiforge_etsy_image_asset','digiforge_etsy_image_lineage'] as $n)self::assertStringContainsString($n,$s);
 }
 public function testCustomerDownloadResolverSeparatesDatabaseFailureFromGenuineAbsence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyCustomerDownloadResolver.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertGreaterThanOrEqual(3,substr_count($s,"'evidence_unavailable'"));
  self::assertStringContainsString("int \$status=409",$s);
  foreach(["self::error('bundle'","self::error('spec'","self::error('revision'"] as $n)self::assertStringContainsString($n,$s);
 }
}
