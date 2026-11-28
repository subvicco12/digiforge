<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyLifecycleRequest;
use DigiForge\POD\PrintifyLifecycleEvidence;
require_once __DIR__.'/../../includes/POD/PrintifyOrderEvidence.php';
foreach(['PrintifyLifecycleRequest','PrintifyLifecycleEvidence'] as $c){$f=__DIR__.'/../../includes/POD/'.$c.'.php';if(is_file($f))require_once $f;}
final class PrintifyLifecycleContractTest extends TestCase
{
 private function order():array{return ['external_id'=>'fixture-1','line_items'=>[['product_id'=>'product_1','variant_id'=>7,'quantity'=>1]],'shipping_method'=>1,'is_printify_express'=>false,'is_economy_shipping'=>false,'send_shipping_notification'=>false,'address_to'=>['first_name'=>'Fixture','last_name'=>'Only','email'=>'fixture@example.invalid','phone'=>'','country'=>'US','region'=>'CA','address1'=>'Fixture street','address2'=>'','city'=>'Fixture','zip'=>'00000']];}
 public function testShippingQuoteUsesPostAndNeverInventsCurrency():void{
  self::assertTrue(class_exists(PrintifyLifecycleRequest::class),'Lifecycle request boundary missing');
  $r=PrintifyLifecycleRequest::prepare('SHIPPING_QUOTE','12',['order'=>$this->order()]);self::assertSame('POST',$r['method']);self::assertSame('/v1/shops/12/orders/shipping.json',$r['endpoint']);self::assertSame(['line_items','address_to'],array_keys($r['body']));self::assertFalse($r['external_execution_authorized']);
 }
 public function testCancellationIsExactAndRequiresLaterReceiptAdmission():void{
  self::assertTrue(class_exists(PrintifyLifecycleRequest::class));$r=PrintifyLifecycleRequest::prepare('ORDER_CANCEL','12',['order_id'=>'order_1']);self::assertSame('/v1/shops/12/orders/order_1/cancel.json',$r['endpoint']);self::assertSame('orders.write',$r['required_scope']);
 }
 public function testQuotePreservesUnknownFieldsAndMapsTransitionalShippingNames():void{
  self::assertTrue(class_exists(PrintifyLifecycleEvidence::class));$e=PrintifyLifecycleEvidence::project(['operation'=>'SHIPPING_QUOTE','body'=>[]],['standard'=>1000,'express'=>5000,'priority'=>5000,'printify_express'=>799]);self::assertNull($e['currency']);self::assertSame(1000,$e['rates_minor']['standard']);self::assertSame(5000,$e['rates_minor']['priority']);self::assertNull($e['rates_minor']['economy']);self::assertFalse($e['production_certified']);
 }
 public function testAmbiguousQuoteAliasesRemainRejected():void{
  self::assertTrue(class_exists(PrintifyLifecycleEvidence::class));$this->expectException(\InvalidArgumentException::class);PrintifyLifecycleEvidence::project(['operation'=>'SHIPPING_QUOTE'],['express'=>100,'priority'=>200]);
 }
 public function testCanceledReadbackRequiresMatchingIdAndTerminalStatus():void{
  self::assertTrue(class_exists(PrintifyLifecycleEvidence::class));$r=PrintifyLifecycleRequest::prepare('ORDER_CANCEL','12',['order_id'=>'order_1']);$e=PrintifyLifecycleEvidence::project($r,['id'=>'order_1','status'=>'canceled','line_items'=>[]]);self::assertSame('canceled',$e['status']);$this->expectException(\InvalidArgumentException::class);PrintifyLifecycleEvidence::project($r,['id'=>'other','status'=>'canceled','line_items'=>[]]);
 }
 public function testPublishDeleteAndUnpublishNotificationAreNotAuthority():void{
  require_once __DIR__.'/../../includes/POD/PrintifyProductRequest.php';
  foreach(['PRODUCT_PUBLISH'=>['product_id'=>'p1','publish_fields'=>['title'=>true]],'PRODUCT_DELETE'=>['product_id'=>'p1'],'PRODUCT_UNPUBLISH_NOTIFY'=>['product_id'=>'p1']] as $op=>$input){$r=\DigiForge\POD\PrintifyProductRequest::prepare($op,'12',$input);self::assertFalse($r['external_execution_authorized']);self::assertTrue(\DigiForge\POD\PrintifyProductRequest::intact($r));}
 }
 public function testCustomChannelPublishCallbacksAreExplicitRequestsNotCertification():void{
  require_once __DIR__.'/../../includes/POD/PrintifyProductRequest.php';
  $r=\DigiForge\POD\PrintifyProductRequest::prepare('PRODUCT_PUBLISH_SUCCEEDED','12',['product_id'=>'p1','external'=>['id'=>'channel_1','handle'=>'https://example.invalid/listing/1']]);self::assertSame('/v1/shops/12/products/p1/publishing_succeeded.json',$r['endpoint']);self::assertFalse($r['external_execution_authorized']);
  $r=\DigiForge\POD\PrintifyProductRequest::prepare('PRODUCT_PUBLISH_FAILED','12',['product_id'=>'p1','reason'=>'CHANNEL_TIMEOUT']);self::assertSame(['reason'=>'CHANNEL_TIMEOUT'],$r['body']);
 }
 public function testPublishSuccessCannotCarryAnUnverifiedRedirectHandle():void{
  require_once __DIR__.'/../../includes/POD/PrintifyProductRequest.php';$this->expectException(\InvalidArgumentException::class);\DigiForge\POD\PrintifyProductRequest::prepare('PRODUCT_PUBLISH_SUCCEEDED','12',['product_id'=>'p1','external'=>['id'=>'channel_1','handle'=>'javascript:alert(1)']]);
 }
 public function testUnknownDestinationCountryCannotReachQuoteAdmission():void{
  $order=$this->order();$order['address_to']['country']='ZZ';$this->expectException(\InvalidArgumentException::class);PrintifyLifecycleRequest::prepare('SHIPPING_QUOTE','12',['order'=>$order]);
 }
}
