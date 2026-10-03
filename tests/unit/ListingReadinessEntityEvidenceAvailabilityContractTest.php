<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingReadinessEntityEvidenceAvailabilityContractTest extends TestCase{
 public function testNestedReadinessEntityReadsFailClosed():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString('bundleBelongsToProductEvidence',$s);
  self::assertGreaterThanOrEqual(4,substr_count($s,"'readiness_evidence_unavailable'"));
  self::assertStringContainsString('if($revision instanceof WP_Error)return $revision;',$s);
  self::assertStringContainsString('if($spec instanceof WP_Error)return $spec;',$s);
  self::assertStringContainsString('if($mapping instanceof WP_Error)return $mapping;',$s);
  self::assertStringContainsString('if($bundleValid instanceof WP_Error)return $bundleValid;',$s);
  self::assertStringContainsString('if($bundle instanceof WP_Error)return $bundle;',$s);
  self::assertStringContainsString('if($plan instanceof WP_Error)return $plan;',$s);
  self::assertStringContainsString('release readiness is blocked.',$s);
 }
}
