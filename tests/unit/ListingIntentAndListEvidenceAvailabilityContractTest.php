<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingIntentAndListEvidenceAvailabilityContractTest extends TestCase{
 public function testEtsyIntentLookupSeparatesUnavailableEvidenceFromMissingIntent():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDraftPreparation.php');
  self::assertStringContainsString("\$wpdb->last_error='';\$intent=\$wpdb->get_row",$s);
  self::assertStringContainsString("'digiforge_etsy_intent_evidence_unavailable'",$s);
  self::assertStringContainsString("'status'=>503",$s);
  self::assertLessThan(strpos($s,"'digiforge_etsy_intent_missing'"),strpos($s,"'digiforge_etsy_intent_evidence_unavailable'"));
 }
 public function testListingListDoesNotCollapseUnavailableEvidenceToEmpty():void{
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString("['items'=>null,'pagination'=>null,'query_state'=>'UNAVAILABLE']",$r);
  self::assertStringContainsString("!is_array(\$items)||!empty(\$wpdb->last_error)",$r);
  self::assertStringContainsString("!is_numeric(\$total)||!empty(\$wpdb->last_error)",$r);
  self::assertStringContainsString("'query_state'=>'AVAILABLE'",$r);
  $c=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/ListingController.php');
  self::assertStringContainsString("'listing_evidence_unavailable'",$c);
  self::assertStringContainsString("],503)",$c);
 }
}
