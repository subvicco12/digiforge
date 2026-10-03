<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingFactoryEvidenceAvailabilityContractTest extends TestCase{
 public function testGateEvidenceQueriesDistinguishUnavailableFromMissingOrUnapproved():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/ListingFactory.php');
  self::assertGreaterThanOrEqual(2,substr_count($s,"\$wpdb->last_error = '';"));
  self::assertStringContainsString('listing_factory_product_version_evidence_unavailable',$s);
  self::assertStringContainsString('Product version approval evidence could not be read.',$s);
  self::assertStringContainsString('listing_factory_release_evidence_unavailable',$s);
  self::assertStringContainsString('Approved release-bundle evidence could not be read.',$s);
  self::assertStringContainsString("'listing_factory_gate2'",$s);
  self::assertStringContainsString("'listing_factory_release'",$s);
  self::assertLessThan(strpos($s,"'listing_factory_gate2'"),strpos($s,"'listing_factory_product_version_evidence_unavailable'"));
  self::assertLessThan(strpos($s,"'listing_factory_release'"),strpos($s,"'listing_factory_release_evidence_unavailable'"));
 }
}
