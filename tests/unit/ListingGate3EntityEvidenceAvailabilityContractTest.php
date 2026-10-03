<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingGate3EntityEvidenceAvailabilityContractTest extends TestCase{
 public function testGate3SeparatesUnavailableEntityEvidenceAndCommittedReadback():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString("'gate3_listing_evidence_unavailable'",$s);
  self::assertStringContainsString("'gate3_review_evidence_unavailable'",$s);
  self::assertStringContainsString("'gate3_decision_readback_evidence_unavailable'",$s);
  self::assertStringContainsString('if($review instanceof WP_Error)return $review;',$s);
  self::assertGreaterThanOrEqual(2,substr_count($s,'if($listing instanceof WP_Error)return $listing;'));
  self::assertStringContainsString('if($decided instanceof WP_Error)return $decided;',$s);
  self::assertStringNotContainsString("return \$this->find(Tables::listing_readiness_reviews(),\$reviewId)?:[];",$s);
  self::assertLessThan(strpos($s,"'review_not_found','Listing readiness review not found.'"),strpos($s,"'gate3_review_evidence_unavailable'"));
  self::assertLessThan(strpos($s,'return $decided;'),strpos($s,"'gate3_decision_readback_evidence_unavailable'"));
 }
}
