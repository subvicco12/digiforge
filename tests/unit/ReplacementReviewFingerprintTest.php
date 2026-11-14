<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ReplacementReviewFingerprintTest extends TestCase {
 public function testCaseFingerprintFreezesClosedOrderAndExactListingProductProviderLines():void {
  require_once dirname(__DIR__,2).'/includes/Orders/ReplacementReviewRepository.php';
  $order=['id'=>5,'shop_reference'=>'digital','environment'=>'sandbox','state'=>'CLOSED'];$lines=[['id'=>8,'product_version_id'=>9,'listing_id'=>10,'provider_mapping_id'=>11,'quantity'=>1]];$h=DigiForge\Orders\ReplacementReviewRepository::fingerprint($order,$lines);
  foreach(['state'=>'APPROVED','shop_reference'=>'personalized_pod','environment'=>'production'] as $k=>$v){$o=$order;$o[$k]=$v;self::assertNotSame($h,DigiForge\Orders\ReplacementReviewRepository::fingerprint($o,$lines));}
  foreach(['product_version_id','listing_id','provider_mapping_id','quantity'] as $field){$l=$lines;$l[0][$field]++;self::assertNotSame($h,DigiForge\Orders\ReplacementReviewRepository::fingerprint($order,$l));}
 }
}
