<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\{Bridge,ExternalOperationIntent,NativeHost,ReadBudget,ReadConnector,HostInterface,ShopBinding,CredentialReference,ProviderResult};
/** Actual WordPress hooks/users/HTTP API and synthetic-only scope fixtures. */
final class PrintifyConnectorIntegrationTest extends WP_UnitTestCase {
 public function testBothPluginsLoadWithoutRoutesCronOrNetwork():void {
  $before=rest_get_server()->get_routes();$cron=_get_cron_array();$calls=0;$filter=static function($pre)use(&$calls){$calls++;return new WP_Error('no_network');};add_filter('pre_http_request',$filter,1);
  require_once dirname(__DIR__,2).'/companion/digiforge-printify-connector/digiforge-printify-connector.php';
  DigiForgePrintifyConnector\Plugin::register();DigiForgePrintifyConnector\Plugin::boot();DigiForgePrintifyConnector\Plugin::boot();
  self::assertSame('INTERFACE_AVAILABLE_AUTHORITY_UNVERIFIED',DigiForgePrintifyConnector\Plugin::status()['state']);self::assertInstanceOf(DigiForge\Connector\Printify\ConnectorInterface::class,DigiForgePrintifyConnector\Plugin::connector());self::assertSame($before,rest_get_server()->get_routes());self::assertSame($cron,_get_cron_array());self::assertSame(0,$calls);remove_filter('pre_http_request',$filter,1);
 }
 public function testPreparationRemainsInertUnderWordPressSafetyLocks():void {
  require_once dirname(__DIR__,2).'/companion/digiforge-printify-connector/digiforge-printify-connector.php';
  $before=_get_cron_array();$routes=rest_get_server()->get_routes();$calls=0;
  $filter=static function($pre)use(&$calls){$calls++;return new WP_Error('no_network');};add_filter('pre_http_request',$filter,1);
  try{$service=DigiForgePrintifyConnector\Plugin::preparation();self::assertFalse($service->status()['live_dispatch_available']);self::assertFalse($service->status()['credentials_owned']);self::assertSame($before,_get_cron_array());self::assertSame($routes,rest_get_server()->get_routes());self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));}finally{remove_filter('pre_http_request',$filter,1);}
 }
 public function testRealNativeHostPreservesStopAndNeverReadsVault():void {
  $actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);$http=0;$filter=static function($pre)use(&$http){$http++;return new WP_Error('no_network');};add_filter('pre_http_request',$filter,1);
  $service=Bridge::connect('1.0.0');self::assertNotWPError($service);self::assertWPError($service->read(new ExternalOperationIntent($actor,'personalized_pod',3,'SHOPS_READ',[],bin2hex(random_bytes(16)))));self::assertSame(0,$http);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));remove_filter('pre_http_request',$filter,1);
 }
 public function testWordPressHttpApiUsesExistingSharedTransportAndMinimizedOrderProjection():void {
  $actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);
  $host=new class($actor) implements HostInterface {
   public function __construct(private int $actor){}
   public function authorize(ExternalOperationIntent $i):ShopBinding|WP_Error{if(get_current_user_id()!==$this->actor||$i->actorId!==$this->actor||$i->shopKey!=='personalized_pod'||$i->integrationId!==3)return new WP_Error('scope');return new ShopBinding(2,'personalized_pod',3,'28930593','production',1,new CredentialReference(3),hash('sha256','reviewed-synthetic'),time()+300);}
   public function reserve(ExternalOperationIntent $i,ShopBinding $b):true|WP_Error{return true;}
   public function consume(CredentialReference $r,callable $f):mixed{return $f('synthetic-wp-http-fixture');}
   public function record(array $r):bool{return true;}
  };
  $seen=[];$filter=static function($pre,$args,$url)use(&$seen){$seen[]=[$url,$args['method'],$args['redirection'],$args['sslverify'],$args['limit_response_size']];return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"id":"order1","status":"pending","address_to":{"email":"private@example.test","address1":"DO NOT EXPOSE"}}'];};add_filter('pre_http_request',$filter,1,3);
  $service=new ReadConnector($host);$result=$service->read(new ExternalOperationIntent($actor,'personalized_pod',3,'ORDER_READ',['order_id'=>'order1'],bin2hex(random_bytes(16))));self::assertInstanceOf(ProviderResult::class,$result);self::assertSame('order1',$result->data['items'][0]['id']);self::assertStringNotContainsString('private@',wp_json_encode($result));self::assertStringNotContainsString('DO NOT EXPOSE',wp_json_encode($result));self::assertSame([['https://api.printify.com/v1/shops/28930593/orders/order1.json','GET',0,true,1048577]],$seen);
  self::assertWPError($service->read(new ExternalOperationIntent($actor,'standard_pod',3,'SHOPS_READ',[],bin2hex(random_bytes(16)))));self::assertCount(1,$seen);remove_filter('pre_http_request',$filter,1);
 }
 public function testInnoDbBudgetRejectsReplayAndPersistsSharedLimits():void {
  global $wpdb;
  // Budget requires its own durable transaction. Use only this disposable DB.
  $wpdb->query('ROLLBACK');$wpdb->query('SET autocommit=1');
  $actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);$session=WP_Session_Tokens::get_instance($actor)->create(time()+300);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($actor,time()+300,'logged_in',$session);
  $integration=random_int(100000,999999);$budget=new ReadBudget();$intents=[];
  try{
   for($i=0;$i<3;$i++){$intent=new ExternalOperationIntent($actor,'personalized_pod',$integration,'SHOPS_READ',[],bin2hex(random_bytes(16)));$intents[]=$intent;self::assertTrue($budget->reserve($intent));}
   self::assertWPError((new ReadBudget())->reserve($intents[0]));$extra=new ExternalOperationIntent($actor,'personalized_pod',$integration,'SHOPS_READ',[],bin2hex(random_bytes(16)));self::assertWPError((new ReadBudget())->reserve($extra));
  }finally{foreach($intents as $i)$wpdb->delete($wpdb->options,['option_name'=>'df_printify_read_intent_'.hash('sha256',$i->intentId)]);$wpdb->delete($wpdb->options,['option_name'=>'df_printify_read_window_'.hash('sha256','printify|'.$integration)]);unset($_COOKIE[LOGGED_IN_COOKIE]);WP_Session_Tokens::get_instance($actor)->destroy_all();$wpdb->query('START TRANSACTION');}
 }
}
