<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingReleasePackageEvidenceAvailabilityContractTest extends TestCase{
 public function testDraftPackageLookupSeparatesUnavailableEvidenceFromMissingPackage():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/ReleaseEvidence.php');
  self::assertStringContainsString("\$wpdb->last_error='';\$package=\$wpdb->get_row",$s);
  self::assertStringContainsString("'digiforge_draft_package_evidence_unavailable'",$s);
  self::assertStringContainsString("'status'=>503",$s);
  self::assertStringContainsString("'digiforge_draft_package_missing'",$s);
  self::assertStringContainsString("'status'=>404",$s);
  self::assertLessThan(strpos($s,"'digiforge_draft_package_missing'"),strpos($s,"'digiforge_draft_package_evidence_unavailable'"));
 }
 public function testPrepublishPreparationPropagatesReleaseEvidenceFailure():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPrepublishPreparation.php');
  self::assertStringContainsString("\$release=(new ReleaseEvidence())->verify(\$packageId);if(is_wp_error(\$release))return \$release;",$s);
  self::assertStringContainsString("'publish_authorized'=>false",$s);
 }
}
