<?php
declare(strict_types=1);
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
use DigiForge\POD\PrintifyApiReadWorkflow;
use DigiForge\Connector\Printify\{NativeBridge,NativeReadConnector,ExternalOperationIntent,ProviderResult};
final class NativeGetCompanionConformanceTest extends WP_UnitTestCase {
 private PrintifyApiApprovalFixture $fixture;
 protected function setUp():void{parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();}
 protected function tearDown():void{$this->fixture->restore();parent::tearDown();}
 public static function operations():array {
  $product=['id'=>'synthetic_product','shop_id'=>12,'blueprint_id'=>1,'print_provider_id'=>7,'variants'=>[['id'=>3,'price'=>1000,'is_enabled'=>true]],'print_areas'=>[],'images'=>[]];
  $order=['id'=>'synthetic_order','status'=>'pending','line_items'=>[],'address_to'=>['email'=>'private@example.invalid'],'total_price'=>1000];
  return [
   'shops'=>['SHOPS_READ',[],[],[['id'=>12,'title'=>'Synthetic shop','sales_channel'=>'etsy']],'/v1/shops.json'],
   'providers'=>['PROVIDERS_READ',[],[],[['id'=>7,'title'=>'Synthetic provider']],'/v1/catalog/print_providers.json'],
   'provider'=>['PROVIDER_READ',['provider_id'=>7],['provider_id'=>7],['id'=>7,'title'=>'Synthetic provider'],'/v1/catalog/print_providers/7.json'],
   'products'=>['PRODUCTS_READ',[],[],['data'=>[$product],'current_page'=>1,'per_page'=>10,'last_page'=>1],'/v1/shops/12/products.json?page=1&limit=10'],
   'product'=>['PRODUCT_READ',['product_id'=>'synthetic_product'],['product_id'=>'synthetic_product','blueprint_id'=>1,'provider_id'=>7,'variant_ids'=>[3]],$product,'/v1/shops/12/products/synthetic_product.json'],
   'orders'=>['ORDERS_READ',[],[],['data'=>[$order],'current_page'=>1,'per_page'=>10,'last_page'=>1],'/v1/shops/12/orders.json?page=1&limit=10'],
   'order'=>['ORDER_READ',['order_id'=>'synthetic_order'],['order_id'=>'synthetic_order'],$order,'/v1/shops/12/orders/synthetic_order.json'],
  ];
 }
 /** @dataProvider operations */
 public function testPreservedSevenGetOperationsThroughActualCompanionWhenBuilt(string $op,array $input,array $planInput,array $body,string $endpoint):void {
  $w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,$op,$planInput));$a=$this->fixture->approve($w,$p,'printify-api-read');$i=new ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,$op,$input,bin2hex(random_bytes(16)));self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));
  if(is_file(DIGIFORGE_PATH.'includes/Connector/Printify/native-build.json')){$port=NativeBridge::connect('1.0.0');$m=$port->contractManifest();self::assertContains('PANEL',$m['capabilities']);self::assertContains('GET_READ',$m['capabilities']);$reader=new DigiForgePrintifyConnector\Preparation\NativePortAdapter($port,$m['core_source_sha']);self::assertWPError((new DigiForgePrintifyConnector\Preparation\NativePortAdapter($port,str_repeat('0',40)))->read($i));}else{$reader=new NativeReadConnector();self::assertWPError(DigiForgePrintifyConnector\Preparation\NativePortAdapter::discover()->read($i));}
  $calls=0;$fake=static function($pre,$args,$url)use(&$calls,$body,$endpoint){++$calls;self::assertSame('https://api.printify.com'.$endpoint,$url);self::assertSame('GET',$args['method']);return PrintifyApiApprovalFixture::response($body);};add_filter('pre_http_request',$fake,100,3);try{$r=$reader->read($i);self::assertInstanceOf(ProviderResult::class,$r);self::assertSame($op,$r->receipt['operation']);self::assertIsArray($r->data['items']??null,'Canonical native GET requires ReadProjection shape');self::assertFalse($r->jsonSerialize()['mutation_authorized']);self::assertWPError($reader->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
 }
 public static function foreignOrders():array{return [['ORDER_READ'],['ORDERS_READ']];}
 /** @dataProvider foreignOrders */
 public function testContradictoryOrderScopeCannotBecomeObservedNativeReceipt(string $op):void {
  $input=$op==='ORDER_READ'?['order_id'=>'synthetic_order']:[];$w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,$op,$input));$a=$this->fixture->approve($w,$p,'printify-api-read');$i=new ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,$op,$input,bin2hex(random_bytes(16)));self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));$calls=0;$fake=static function()use(&$calls,$op){++$calls;$row=['id'=>'synthetic_order','shop_id'=>999,'status'=>'pending','line_items'=>[]];return PrintifyApiApprovalFixture::response($op==='ORDER_READ'?$row:['data'=>[$row],'current_page'=>1,'per_page'=>10,'last_page'=>1]);};add_filter('pre_http_request',$fake,100);
  try{$r=(new NativeReadConnector())->read($i);self::assertWPError($r);self::assertSame('UNKNOWN',$r->get_error_data()['outcome']);self::assertFalse($r->get_error_data()['retry_permitted']);self::assertWPError((new NativeReadConnector())->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
 }

}
