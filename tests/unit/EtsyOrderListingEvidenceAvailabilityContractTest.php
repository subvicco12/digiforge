<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOrderListingEvidenceAvailabilityContractTest extends TestCase{
 public function testResolverSeparatesDatabaseUnavailabilityFromNoUniqueMapping():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderListingResolver.php');
  self::assertStringContainsString('array|WP_Error|null',$s);
  self::assertStringContainsString("\$wpdb->last_error=''",$s);
  self::assertStringContainsString("'digiforge_etsy_order_listing_evidence_unavailable'",$s);
  self::assertStringContainsString('Confirmed Etsy listing resolution evidence could not be read.',$s);
  self::assertStringContainsString('count($rows)!==1)return null',$s);
 }
 public function testPaidLifecyclePropagatesUnavailableResolutionButReviewsGenuineMiss():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderWebhookLifecycle.php');
  self::assertStringContainsString('if($resolved instanceof WP_Error)return $resolved;',$s);
  self::assertStringContainsString('if(!is_array($resolved)){$reviewRequired=true;continue;}',$s);
  $resolvePos=strpos($s,'EtsyOrderListingResolver::resolve');
  $createPos=strpos($s,'$order=$this->orders->createOrder');
  self::assertNotFalse($resolvePos);
  self::assertNotFalse($createPos);
  self::assertLessThan($createPos,$resolvePos,'Failure-capable listing evidence must be resolved before durable order creation.');
  self::assertStringContainsString('$prepared[]=', $s);
  self::assertStringContainsString('foreach($prepared as $entry)', $s);
 }
}
