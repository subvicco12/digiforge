<?php
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
final class Gate2LockedExecutionTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],DigiForge\Core\Capabilities::ALL);}
 public function testLockedPrintifyAndEtsyBoundariesUseNoSenderOrCredentials():void{
  $calls=0;$http=0;$reads=0;$sender=static function()use(&$calls){++$calls;return [];};
  $deny=static function()use(&$http){++$http;return new WP_Error('no_http');};$query=static function(string $sql)use(&$reads){if(str_contains($sql,DigiForge\Database\Tables::integration_secrets())&&str_contains($sql,'SELECT'))++$reads;return $sql;};
  add_filter('pre_http_request',$deny);add_filter('query',$query);
  try{foreach(['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'] as $action){
   // Synthetic attack permit/payload only, no provider account/order or approval record.
   $permit=['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>true,'external_execution_performed'=>false,'action'=>$action];
   $payload=['integration_id'=>991,'shop_id'=>992,'connection_scope'=>['shop_key'=>'personalized_pod'],'order_id'=>'synthetic_order',
    'order'=>['external_id'=>'synthetic_only','line_items'=>[['sku'=>'not_a_real_sku','quantity'=>1]],'address_to'=>[]]];
   $request=DigiForge\POD\PrintifyMutationRequest::prepare($permit,$payload);self::assertIsArray($request);$permit['request_fingerprint']=$request['request_fingerprint'];
   $r=(new DigiForge\POD\PrintifyExecutionAdapter(new DigiForge\POD\PrintifyControlledTransport($sender)))->execute($permit,$payload);
   self::assertWPError($r);self::assertSame('digiforge_printify_interlock_locked',$r->get_error_code());
  }
  $prepared=['state'=>'ETSY_CONTROLLED_TRANSPORT_PREPARED','network_request_permitted'=>false,'network_request_attempted'=>false,'external_execution_performed'=>false,'operation_id'=>991,'integration_id'=>992,'request_plan'=>['method'=>'PATCH','endpoint'=>'/application/shops/991/listings/992']];
  $draft=DigiForge\Listings\EtsyLiveTransportInterlock::authorize($prepared);self::assertWPError($draft);self::assertSame('digiforge_etsy_live_transport_locked',$draft->get_error_code());
  $pub=DigiForge\Listings\EtsyPublishLiveTransportInterlock::authorize($prepared);self::assertWPError($pub);self::assertSame('digiforge_etsy_publish_interlock_locked',$pub->get_error_code());
  foreach(['READ','DRAFT','PUBLISH'] as $op)self::assertFalse(DigiForge\Listings\EtsyExecutionPolicy::evaluate($op,true)['allowed']);
  self::assertSame(0,$calls);self::assertSame(0,$http);self::assertSame(0,$reads);
  }finally{remove_filter('query',$query);remove_filter('pre_http_request',$deny);}
  self::assertTrue(DigiForge\Core\Settings::get('stop_all'));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed'));
 }
}
