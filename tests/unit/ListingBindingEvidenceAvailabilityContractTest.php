<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingBindingEvidenceAvailabilityContractTest extends TestCase{
 public function testMediaAndPodBindingEvidenceFailuresAreDistinctFromInvalidRelationships():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString("'listing_binding_evidence_unavailable'",$s);
  self::assertGreaterThanOrEqual(4,substr_count($s,'if($'.'revision instanceof WP_Error)return $'.'revision;')+substr_count($s,'if($'.'listing instanceof WP_Error)return $'.'listing;')+substr_count($s,'if($'.'mapping instanceof WP_Error)return $'.'mapping;'));
  self::assertStringContainsString('if($spec instanceof WP_Error)return $spec;',$s);
  self::assertStringContainsString('if($schema instanceof WP_Error)return $schema;',$s);
  self::assertStringContainsString('if($bundleValid instanceof WP_Error)return $bundleValid;',$s);
  self::assertStringContainsString("'invalid_media','Asset revision must exist and be approved.'",$s);
  self::assertStringContainsString("'invalid_relationship','Listing and POD mapping must share a product version.'",$s);
  self::assertStringContainsString("'personalization_not_ready'",$s);
 }
}
