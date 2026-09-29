<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingGate3PortalDecisionContractTest extends TestCase {
 public function testListingsPortalExposesGovernedHumanDecisionWithoutPublish():void {
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString("LISTING_REVIEW_ACTION = 'digiforge_portal_listing_review'",$p);
  self::assertStringContainsString("check_admin_referer(self::LISTING_REVIEW_ACTION)",$p);
  self::assertStringContainsString("guard('manage_digiforge_listings')",$p);
  self::assertStringContainsString("decideReadinessReview(\$reviewId, \$decision)",$p);
  self::assertStringContainsString("WHERE r.decision='PENDING'",$p);
  self::assertStringContainsString("hash_equals((string)\$row['readiness_hash'],hash('sha256',\$canonical))",$p);
  self::assertStringContainsString("\$evidenceValid=\$hashValid&&\$prereqs",$p);
  self::assertStringContainsString('Decision controls are withheld.',$p);
  self::assertStringContainsString('Approve Listing',$p);
  self::assertStringContainsString('Reject / Revise',$p);
  self::assertStringContainsString('No Etsy publish was performed.',$p);
  self::assertStringNotContainsString("wp_remote_post",$p);
 }
}
