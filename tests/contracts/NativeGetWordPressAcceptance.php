<?php
declare(strict_types=1);
/** Explicit actual-core conformance. Never runs against the legacy installed-lineage control. */
use DigiForge\POD\PrintifyApiReadWorkflow;
use DigiForge\Connector\Printify\{ExternalOperationIntent,NativeBridge,ProviderResult};
use DigiForgePrintifyConnector\Preparation\{NativePortAdapter,NativePortSchema};
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
final class NativeGetWordPressAcceptance extends WP_UnitTestCase {
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
 private function grant(string $operation,array $input=[],?array $planInput=null):array {
  $w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,$operation,$planInput??$input));$a=$this->fixture->approve($w,$p,'printify-api-read');$i=new ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,$operation,$input,bin2hex(random_bytes(16)));self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));return [$w,$i];
 }
 /** @dataProvider operations */
 public function testSevenActualCoreJoinsAndOneUse(string $operation,array $input,array $planInput,array $body,string $endpoint):void {
  [$w,$i]=$this->grant($operation,$input,$planInput);$p=NativeBridge::connect('1.0.0');self::assertNotWPError($p);$calls=0;
  $fake=static function($pre,$args,$url)use(&$calls,$endpoint,$body){++$calls;self::assertSame('https://api.printify.com'.$endpoint,$url);self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);return PrintifyApiApprovalFixture::response($body);};add_filter('pre_http_request',$fake,100,3);
  try{$r=$p->read($i);self::assertInstanceOf(ProviderResult::class,$r);self::assertTrue(NativePortSchema::valid('get_response',$r->jsonSerialize()));self::assertSame($operation,$r->receipt['operation']);self::assertSame($i->intentId,$r->receipt['intent_id']);self::assertSame(hash('sha256',json_encode($r->data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),$r->receipt['response_sha256']);self::assertFalse($r->jsonSerialize()['mutation_authorized']);self::assertStringNotContainsString('private@example.invalid',json_encode($r->jsonSerialize()));self::assertWPError($p->read($i));self::assertSame(1,$calls);
   // Aggregate capability deliberately absent: even an explicit reviewed test pin cannot grant it.
   self::assertSame(['PANEL'],$p->contractManifest()['capabilities']);self::assertSame(getenv('DF_NATIVE_TEST_SHA'),$p->contractManifest()['core_source_sha']);self::assertWPError((new NativePortAdapter($p,getenv('DF_NATIVE_TEST_SHA')))->read($i));self::assertSame(1,$calls);
  }finally{remove_filter('pre_http_request',$fake,100);}
 }
 public function testSafeReadRetryAndConsumedGrant():void {[$w,$i]=$this->grant('SHOPS_READ');$calls=0;$fake=static function()use(&$calls){return ++$calls===1?PrintifyApiApprovalFixture::response([],500):PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Synthetic','sales_channel'=>'etsy']]);};add_filter('pre_http_request',$fake,100);try{$p=NativeBridge::connect('1.0.0');self::assertInstanceOf(ProviderResult::class,$p->read($i));self::assertSame(2,$calls);self::assertWPError($p->read($i));self::assertSame(2,$calls);}finally{remove_filter('pre_http_request',$fake,100);}}
 public function testMalformedResponseIsUnknownAndNoReplay():void {[$w,$i]=$this->grant('SHOPS_READ');$calls=0;$fake=static function()use(&$calls){++$calls;return PrintifyApiApprovalFixture::response(['invalid'=>'shape']);};add_filter('pre_http_request',$fake,100);try{$p=NativeBridge::connect('1.0.0');$r=$p->read($i);self::assertWPError($r);self::assertSame('UNKNOWN',$r->get_error_data()['outcome']);self::assertTrue($r->get_error_data()['network_request_attempted']);self::assertFalse($r->get_error_data()['retry_permitted']);self::assertWPError($p->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}}
 public function testCrossShopAndIntegrationCannotBorrowGrant():void {[$w,$i]=$this->grant('SHOPS_READ');$p=NativeBridge::connect('1.0.0');foreach([['standard_pod',$i->integrationId],['personalized_pod',$i->integrationId+1000]]as [$shop,$integration]){$other=new ExternalOperationIntent(1,$shop,$integration,$i->operation,[],$i->intentId);self::assertWPError($p->read($other));}self::assertNotWPError($w->resolveNative($i));}
 public static function denials():array{return array_map(static fn($v)=>[$v],['stop','external_lock','integration','credential','account','approval','session']);}
 /** @dataProvider denials */
 public function testCurrentAuthorityChangesDenyBeforeSyntheticSend(string $kind):void {
  [$w,$i]=$this->grant('SHOPS_READ');global $wpdb;
  if($kind==='stop')$this->fixture->setting('stop_all',true);
  elseif($kind==='external_lock')$this->fixture->setting('activation_authorized',false);
  elseif($kind==='integration')PrintifyApiApprovalFixture::checked((new DigiForge\Integrations\Repository())->setEnabled($i->integrationId,false));
  elseif($kind==='credential')$wpdb->delete(DigiForge\Database\Tables::integration_secrets(),['integration_id'=>$i->integrationId]);
  elseif($kind==='account'){$table=DigiForge\Database\Tables::integrations();$row=$wpdb->get_row($wpdb->prepare('SELECT config FROM '.$table.' WHERE id=%d',$i->integrationId),ARRAY_A);$config=json_decode($row['config'],true);$config['account_reference']='different-synthetic-account';$wpdb->update($table,['config'=>wp_json_encode($config)],['id'=>$i->integrationId]);}
  elseif($kind==='approval')wp_get_current_user()->add_cap('manage_digiforge_connections',false);
  else WP_Session_Tokens::get_instance(1)->destroy(wp_get_session_token());
  $calls=0;$fake=static function()use(&$calls){++$calls;return PrintifyApiApprovalFixture::response([]);};add_filter('pre_http_request',$fake,100);try{self::assertWPError(NativeBridge::connect('1.0.0')->read($i));self::assertSame(0,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
 }
 public function testRateLimitNeverRetriesOrRestoresSlot():void {[$w,$i]=$this->grant('SHOPS_READ');$calls=0;$fake=static function()use(&$calls){++$calls;$r=PrintifyApiApprovalFixture::response([],429);$r['headers']=['retry-after'=>'30'];return $r;};add_filter('pre_http_request',$fake,100);try{$p=NativeBridge::connect('1.0.0');$r=$p->read($i);self::assertWPError($r);self::assertSame('UNKNOWN',$r->get_error_data()['outcome']);self::assertFalse($r->get_error_data()['retry_permitted']);self::assertWPError($p->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}}

}
