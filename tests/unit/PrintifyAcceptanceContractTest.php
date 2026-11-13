<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyAcceptanceContract as C;
if(is_file(__DIR__.'/../../includes/POD/PrintifyAcceptanceContract.php'))require_once __DIR__.'/../../includes/POD/PrintifyAcceptanceContract.php';
final class PrintifyAcceptanceContractTest extends TestCase {
 public function testFixedGetAllowlistRejectsMutationsAndHostInjection():void {
  self::assertTrue(class_exists(C::class),'Acceptance contract must exist');
  foreach(['POST','PUT','PATCH','DELETE'] as $m){try{C::inspect(['operation'=>'SHOPS_READ','method'=>$m,'endpoint'=>'/v1/shops.json','body'=>[]],'12');self::fail('Mutation admitted');}catch(\InvalidArgumentException){self::assertTrue(true);}}
  foreach(['https://evil.test/v1/shops.json','//evil.test/v1/shops.json','/v1/shops.json#x','/v1/shops.json?url=https://evil.test','/v1/shops/13/orders.json?page=1&limit=10'] as $p){try{C::inspect(['operation'=>'SHOPS_READ','method'=>'GET','endpoint'=>$p,'body'=>[]],'12');self::fail('Endpoint admitted');}catch(\InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testBoundedPagingAndNoRawEvidence():void {
  self::assertTrue(class_exists(C::class),'Acceptance contract must exist');
  $r=C::inspect(['operation'=>'ORDERS_READ','method'=>'GET','endpoint'=>'/v1/shops/12/orders.json?page=3&limit=10','body'=>[]],'12');self::assertSame(3,$r['page']);
  $e=C::observations(['id'=>'private-id','address_to'=>['email'=>'private@example.test'],'data'=>[['token'=>'secret']],'status'=>'private detail']);self::assertStringNotContainsString('private',json_encode($e));self::assertStringNotContainsString('secret',json_encode($e));
  $this->expectException(\InvalidArgumentException::class);C::inspect(['operation'=>'ORDERS_READ','method'=>'GET','endpoint'=>'/v1/shops/12/orders.json?page=4&limit=10','body'=>[]],'12');
 }
 /** @dataProvider allowedReads */
 public function testRequiredGetTemplates(string $op,string $path):void {self::assertSame($op,C::inspect(['operation'=>$op,'method'=>'GET','endpoint'=>$path,'body'=>[]],'12')['operation']);}
 public static function allowedReads():array{return [
 ['SHOPS_READ','/v1/shops.json'],['BLUEPRINTS','/v1/catalog/blueprints.json'],['BLUEPRINT','/v1/catalog/blueprints/3.json'],['BLUEPRINT_PROVIDERS','/v1/catalog/blueprints/3/print_providers.json'],['BLUEPRINT_PROVIDER','/v1/catalog/blueprints/3/print_providers/4.json'],['VARIANTS','/v1/catalog/blueprints/3/print_providers/4/variants.json'],['SIZE_GUIDE','/v1/catalog/blueprints/3/size_guide.json'],['PROVIDERS_READ','/v1/catalog/print_providers.json'],['PROVIDER_READ','/v1/catalog/print_providers/4.json'],['SHIPPING_METHODS','/v2/catalog/blueprints/3/print_providers/4/shipping.json'],['REGIONAL_SHIPPING','/v2/catalog/blueprints/3/print_providers/4/shipping/standard.json'],['PRODUCT_READ','/v1/shops/12/products/p1.json'],['PRODUCT_GPSR_READ','/v1/shops/12/products/p1/gpsr.json'],['PRODUCTS_READ','/v1/shops/12/products.json?page=1&limit=10'],['IMAGE_READ','/v1/uploads/i1.json'],['UPLOADS_READ','/v1/uploads.json?page=1&limit=10'],['PERSONALIZATION_OPTIONS','/v1/shops/12/products/p1/personalization_options.json'],['PREVIEW_READ','/v1/shops/12/products/p1/personalization_previews/t1.json'],['ORDERS_READ','/v1/shops/12/orders.json?page=1&limit=10'],['ORDER_READ','/v1/shops/12/orders/o1.json'],['WEBHOOKS_READ','/v1/shops/12/webhooks.json'],['SUPPORT_LIST','/v1/shops/12/orders/o1/support-requests'],['SUPPORT_DETAIL','/v1/shops/12/orders/o1/support-requests/r1']];}
 public function testHeaderBodyAndUnknownFieldInjectionDenied():void {foreach([['headers'=>['Authorization'=>'Bearer forged']],['credentials'=>'forged'],['body'=>['url'=>'https://evil.test']]] as $extra){try{C::inspect(array_replace(['operation'=>'SHOPS_READ','method'=>'GET','endpoint'=>'/v1/shops.json','body'=>[]],$extra),'12');self::fail('Unsafe input admitted');}catch(\InvalidArgumentException){self::assertTrue(true);}}}

}
