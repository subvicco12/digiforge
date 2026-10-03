<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodProductionEvidenceAvailabilityContractTest extends TestCase
{
 public function testPreflightClearsStaleErrorsBeforeIndependentReads():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/PrintifyProductionPreflight.php');
  self::assertGreaterThanOrEqual(4,substr_count($s,"\$wpdb->last_error=''"));
  self::assertStringContainsString('if(is_wp_error($ownership))return $ownership;',$s);
  self::assertStringContainsString("OWNERSHIP_MAPPING_STALE",$s);
 }
 public function testAuthorizationClearsStaleErrorsBeforeEvidenceAndConfirmationReads():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/ProductionAuthorizationRepository.php');
  self::assertGreaterThanOrEqual(4,substr_count($s,"\$wpdb->last_error=''"));
  foreach(['authorization_evidence_unavailable','authorization_package_confirmation_unavailable','authorization_review_confirmation_unavailable',"'retry_permitted'=>false"] as $n)self::assertStringContainsString($n,$s);
 }
}
