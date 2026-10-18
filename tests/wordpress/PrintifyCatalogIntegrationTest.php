<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';
use DigiForge\POD\PrintifyCatalogReadRepository as Reads;
use DigiForge\Database\Tables;
final class PrintifyCatalogIntegrationTest extends WP_UnitTestCase {
 private array $input;private int $owner;private array $relationship;
 private function setupFixture():void {
  parent::setUp();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());DigiForge\Core\Activator::activate();$this->owner=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($this->owner);ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($this->owner,['personalized_pod'],['manage_digiforge_connections','manage_digiforge_pod']);global $wpdb;$now=gmdate('Y-m-d H:i:s');DigiForge\Core\Settings::protectProduction();$suffix=wp_rand(10000,99999);$businessKey='read-business-'.$suffix;$storeKey='read-store-'.$suffix;
  $wpdb->insert(Tables::businesses(),['business_key'=>$businessKey,'display_name'=>'Read fixture','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::stores(),['business_id'=>$business,'store_key'=>$storeKey,'display_name'=>'Read store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);
  $scope=['business_id'=>$businessKey,'store_id'=>$storeKey,'product_program'=>'PERSONALIZED_POD'];$repo=new DigiForge\Integrations\Repository();$integration=$repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'catalog-fixture-'.$suffix,'status'=>'CONFIGURED','config'=>['shop_key'=>'personalized_pod','catalog_read_scope'=>$scope]]);self::assertIsArray($integration);$id=$integration['id'];self::assertTrue($repo->storeSecret($id,'personal_access_token','offline-catalog-fixture-credential'));self::assertIsArray($repo->setEnabled($id,true));$this->relationship=ScopedProviderConnectionFixture::approve($id,$this->owner);
  $this->input=['capability'=>'printify.catalog.read','integration_id'=>$id,'account_id'=>$this->owner]+$scope+['expires_at'=>gmdate('c',time()+1800),'max_requests'=>2,'requests'=>[['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202]]];
 }
 public function setUp():void {
  // Setup above models local plugin activation before a new WordPress boot.
  $this->setupFixture();
  $plugin=(new ReflectionClass(DigiForge\Core\Plugin::class))->newInstanceWithoutConstructor();$plugin->boot();
 }
 private function rest(string $method,string $path,?array $body=null,string $key=''):WP_REST_Response {
  $r=new WP_REST_Request($method,'/digiforge/v1/printify/catalog-read'.$path);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($key!=='')$r->set_header('Idempotency-Key',$key);
  if($body!==null){if($method==='GET')$r->set_query_params($body);else{$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}}
  return rest_do_request($r);
 }
 private function restGrant(?array $requests=null):string {
  $this->input['requests']=$requests??[['kind'=>'variants','blueprint_id'=>101,'provider_id'=>2]];$this->input['max_requests']=count($this->input['requests']);
  $r=$this->rest('POST','/grants',$this->input,'rest-grant-'.wp_generate_password(10,false));self::assertSame(200,$r->get_status());return $r->get_data()['sha256'];
 }
 private function restException(string $sha):void {
  $r=$this->rest('POST','/'.$sha.'/safety-exception',['capability'=>'printify.catalog.read','expires_at'=>gmdate('c',time()+600),'acknowledge_catalog_get_only'=>true,'acknowledge_no_mutations'=>true,'acknowledge_locks_remain_on'=>true],'rest-exception-'.wp_generate_password(10,false));self::assertSame(200,$r->get_status());
 }
 public function testBootRegisteredRoutesComposeGrantExceptionReadEvidenceAndRevocation():void {
  // Deliberately do not call controller::register(): this exercises inherited Plugin boot wiring.
  $routes=rest_get_server()->get_routes();self::assertArrayHasKey('/digiforge/v1/printify/catalog-read/grants',$routes);
  $calls=0;$f=static function($pre,$args,$url)use(&$calls){if(!str_starts_with($url,'https://api.printify.com/'))return $pre;++$calls;self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);self::assertSame(10,$args['timeout']);return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"variants":[{"id":202,"title":"synthetic"}]}'];};add_filter('pre_http_request',$f,1,3);
  try{
   $sha=$this->restGrant();self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'before-exception')->get_status());self::assertSame(0,$calls);
   $bad=new WP_REST_Request('GET','/digiforge/v1/printify/catalog-read/'.$sha);$bad->set_query_params(['request_index'=>0]);self::assertSame(403,rest_do_request($bad)->get_status());
   $this->restException($sha);self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::is_enabled('printify'));self::assertSame(true,DigiForge\Core\Settings::get('stop_all'));self::assertSame(false,DigiForge\Core\Settings::get('automation_armed'));
   $user=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($user);self::assertSame(403,$this->rest('GET','/'.$sha,['request_index'=>0],'foreign-account')->get_status());wp_set_current_user($this->owner);self::assertSame(0,$calls);
   self::assertSame(400,$this->rest('GET','/'.$sha,['request_index'=>0,'unexpected'=>'field'],'invalid-query')->get_status());
   $r=$this->rest('GET','/'.$sha,['request_index'=>0],'registered-read');self::assertSame(200,$r->get_status());$e=$r->get_data();self::assertSame('DRAFT_UNVERIFIED',$e['state']);self::assertFalse($e['supplier_certified']);self::assertFalse($e['mutation_authorized']);self::assertArrayNotHasKey('response_body',$e);self::assertSame(1,$calls);
   self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'registered-read')->get_status());self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'new-key-over-quota')->get_status());
   self::assertSame(200,$this->rest('GET','/'.$sha.'/evidence/'.hash('sha256','registered-read'))->get_status());self::assertSame(1,$calls);
   self::assertSame(200,$this->rest('POST','/'.$sha.'/safety-exception/revoke')->get_status());self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'after-revoke')->get_status());self::assertSame(1,$calls);
  }finally{remove_filter('pre_http_request',$f,1);}
 }
 public function testRegisteredReadRejectsMalformedDraftAndConsumesRequest():void {
  $calls=0;$f=static function($pre,$args,$url)use(&$calls){if(!str_starts_with($url,'https://api.printify.com/'))return $pre;++$calls;return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"unexpected":"synthetic"}'];};add_filter('pre_http_request',$f,1,3);
  try{$sha=$this->restGrant();$this->restException($sha);self::assertSame(502,$this->rest('GET','/'.$sha,['request_index'=>0],'bad-envelope-rest')->get_status());self::assertGreaterThanOrEqual(400,$this->rest('GET','/'.$sha.'/evidence/'.hash('sha256','bad-envelope-rest'))->get_status());self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'bad-envelope-rest')->get_status());self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$f,1);}
 }
 public function testRegisteredReadDiscardsInFlightShopDrift():void {
  $calls=0;$id=$this->input['integration_id'];$f=static function($pre,$args,$url)use(&$calls,$id){if(!str_starts_with($url,'https://api.printify.com/'))return $pre;++$calls;(new DigiForge\Integrations\Repository())->update($id,['config'=>['catalog_read_scope'=>['business_id'=>'foreign','store_id'=>'other','product_program'=>'PERSONALIZED_POD']]]);return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"variants":[{"id":202}]}'];};add_filter('pre_http_request',$f,1,3);
  try{$sha=$this->restGrant();$this->restException($sha);self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'inflight-shop-rest')->get_status());self::assertGreaterThanOrEqual(400,$this->rest('GET','/'.$sha.'/evidence/'.hash('sha256','inflight-shop-rest'))->get_status());self::assertSame(1,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());}finally{remove_filter('pre_http_request',$f,1);}
 }

 public function testScopedCatalogGuardDeniesForeignMissingAndRevokedShopEvidence():void {
  $sha=$this->restGrant();$this->restException($sha);
  $repo=new DigiForge\Integrations\Repository();
  $current=$repo->find($this->input['integration_id']);
  $config=$current['config'];$config['shop_key']='standard_pod';
  self::assertIsArray($repo->update($this->input['integration_id'],['config'=>$config]));
  self::assertSame(403,$this->rest('GET','/'.$sha,['request_index'=>0],'foreign-shop-key')->get_status());
  unset($config['shop_key']);self::assertIsArray($repo->update($this->input['integration_id'],['config'=>$config]));
  self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'missing-shop-key')->get_status());
  $config['shop_key']='personalized_pod';self::assertIsArray($repo->update($this->input['integration_id'],['config'=>$config]));
  // Removing the independently signed authority invalidates assignments immediately.
  ShopAccessFixture::provision([]);
  self::assertSame(403,$this->rest('GET','/'.$sha,['request_index'=>0],'revoked-shop-key')->get_status());
  self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }

 public function testCatalogRequiresBothAssignedCapabilitiesNotOnlyWordPressAdminRole():void {
  ShopAccessFixture::reset();
  ShopAccessFixture::authorizeActor($this->owner,['personalized_pod'],['manage_digiforge_connections']);
  self::assertTrue(current_user_can('manage_digiforge_pod'));
  self::assertSame(403,$this->rest('POST','/grants',$this->input,'connection-only-grant')->get_status());
 }

 public function testShopAssignmentRevocationDuringTransportDiscardsResponse():void {
  $sha=$this->restGrant();$this->restException($sha);$calls=0;
  $filter=static function($pre,$args,$url)use(&$calls){
   if(!str_starts_with($url,'https://api.printify.com/'))return $pre;
   ++$calls;ShopAccessFixture::provision([]);
   return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"variants":[{"id":202}]}'];
  };
  add_filter('pre_http_request',$filter,1,3);
  try{self::assertSame(403,$this->rest('GET','/'.$sha,['request_index'=>0],'revoked-inflight')->get_status());self::assertSame(1,$calls);}
  finally{remove_filter('pre_http_request',$filter,1);}
 }
 public function testShippingDiscoveryAndMethodReadsAreSeparatelyScopedDraftEvidence():void {
  $this->input['requests']=[['kind'=>'shipping','blueprint_id'=>1220,'provider_id'=>105],['kind'=>'shipping_method','blueprint_id'=>1220,'provider_id'=>105,'method'=>'standard']];
  $methods=file_get_contents(__DIR__.'/../fixtures/printify/shipping-v2-methods.json');$rates=file_get_contents(__DIR__.'/../fixtures/printify/shipping-v2-standard.json');$calls=[];
  $f=static function($pre,$args,$url)use(&$calls,$methods,$rates){if(!str_starts_with($url,'https://api.printify.com/'))return $pre;$calls[]=$url;self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);self::assertSame(10,$args['timeout']);self::assertSame(1048577,$args['limit_response_size']);$path='https://api.printify.com/v2/catalog/blueprints/1220/print_providers/105/shipping';self::assertContains($url,[$path.'.json',$path.'/standard.json']);return ['response'=>['code'=>200],'headers'=>[],'body'=>$url===$path.'.json'?$methods:$rates];};
  add_filter('pre_http_request',$f,1,3);
  try{
   $sha=$this->restGrant($this->input['requests']);self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'shipping-locked')->get_status());self::assertSame([],$calls);$this->restException($sha);
   self::assertSame(400,$this->rest('GET','/'.$sha,['request_index'=>0,'method'=>'express'],'shipping-scope-override')->get_status());
   // A discovery grant cannot authorize another path, even with a permissive legacy callback.
   $client=new DigiForge\POD\PrintifyCatalogClient(null,null,static fn()=>true);
   $denied=$client->scopedRead('offline-catalog-fixture-credential',['kind'=>'shipping_method','blueprint_id'=>1220,'provider_id'=>105,'method'=>'express'],['grant_sha256'=>$sha,'request_index'=>0,'request_sha256'=>hash('sha256','shipping-read-0')]);self::assertWPError($denied);self::assertSame('catalog_read_request_scope',$denied->get_error_code());self::assertSame([],$calls);
   foreach([0=>$methods,1=>$rates]as $index=>$body){$r=$this->rest('GET','/'.$sha,['request_index'=>$index],'shipping-read-'.$index);self::assertSame(200,$r->get_status());$e=$r->get_data();self::assertSame('DRAFT_UNVERIFIED',$e['state']);self::assertFalse($e['supplier_certified']);self::assertFalse($e['mutation_authorized']);self::assertSame(hash('sha256',$body),$e['response_sha256']);self::assertSame(json_decode($body,true),$e['data']);self::assertMatchesRegularExpression('/[+-][0-9]{2}:[0-9]{2}$/',$e['observed_at']);self::assertArrayNotHasKey('response_body',$e);self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>$index],'shipping-read-'.$index)->get_status());}
   self::assertCount(2,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::is_enabled('printify'));self::assertSame(false,DigiForge\Core\Settings::get('automation_armed'));
  }finally{remove_filter('pre_http_request',$f,1);}
 }
 public function testMalformedShippingDiscoveryAndRatesConsumeIntentWithoutEvidence():void {
  foreach(['shipping','shipping_method']as $kind){
   $this->input['requests']=[['kind'=>$kind,'blueprint_id'=>1220,'provider_id'=>105]+($kind==='shipping_method'?['method'=>'standard']:[])];
   $calls=0;$f=static function($pre,$args,$url)use(&$calls){if(!str_starts_with($url,'https://api.printify.com/'))return $pre;++$calls;return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"profiles":[{"countries":["US"]}]}'];};add_filter('pre_http_request',$f,1,3);
   try{$sha=$this->restGrant($this->input['requests']);$this->restException($sha);self::assertSame(502,$this->rest('GET','/'.$sha,['request_index'=>0],'malformed-shipping')->get_status());self::assertGreaterThanOrEqual(400,$this->rest('GET','/'.$sha.'/evidence/'.hash('sha256','malformed-shipping'))->get_status());self::assertSame(409,$this->rest('GET','/'.$sha,['request_index'=>0],'malformed-shipping')->get_status());self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$f,1);}
  }
 }


 public function testOwnershipRevocationDuringTransportDiscardsEvidence():void {
  $sha=$this->restGrant();$this->restException($sha);$calls=0;$revoked=false;
  $filter=function($pre,$args,string $url)use(&$calls,&$revoked){
   if(!str_starts_with($url,'https://api.printify.com/'))return $pre;
   ++$calls;$prior=get_current_user_id();wp_set_current_user($this->relationship['reviewer']);$a=$this->relationship['approved'];
   try{$result=(new DigiForge\POD\ScopedProviderConnection())->decide('personalized_pod',$this->input['integration_id'],'printify.catalog.read',$a['revision'],$a['proposal_hash'],'REVOKED','inflight-revoke-'.wp_generate_uuid4());$revoked=!is_wp_error($result);}finally{wp_set_current_user($prior);}
   return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"variants":[{"id":202}]}'];
  };
  add_filter('pre_http_request',$filter,1,3);
  try{$response=$this->rest('GET','/'.$sha,['request_index'=>0],'ownership-revoked-inflight');self::assertSame(409,$response->get_status());self::assertTrue($revoked);self::assertSame(1,$calls);self::assertSame('provider_connection_revoked',$response->get_data()['code']);}
  finally{remove_filter('pre_http_request',$filter,1);}
 }
}
