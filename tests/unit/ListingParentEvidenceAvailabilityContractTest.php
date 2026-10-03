<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingParentEvidenceAvailabilityContractTest extends TestCase{
 public function testParentDatabaseFailuresAreDistinctFromInvalidParents():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString('private function existsEvidence(string $table,int $id): bool|WP_Error',$s);
  self::assertStringContainsString("'listing_parent_evidence_unavailable'",$s);
  self::assertStringContainsString("\$wpdb->last_error='';",$s);
  self::assertStringContainsString('if(!empty($wpdb->last_error)||!is_numeric($count))',$s);
  self::assertGreaterThanOrEqual(2,substr_count($s,'if($parent instanceof WP_Error)return $parent;'));
  self::assertGreaterThanOrEqual(3,substr_count($s,'if($listing instanceof WP_Error)return $listing;'));
  self::assertStringContainsString("'invalid_parent','Valid product_version_id is required.'",$s);
  self::assertStringContainsString("'invalid_parent','Valid listing_id is required.'",$s);
  self::assertLessThan(strpos($s,"'invalid_parent','Valid product_version_id is required.'"),strpos($s,'if($parent instanceof WP_Error)return $parent;'));
 }
}
