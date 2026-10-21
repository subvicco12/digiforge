<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';
use DigiForge\POD\PrintifyCatalogReadRepository as Reads;
use DigiForge\Database\Tables;
final class PrintifyCatalogScopedSmokeTest extends WP_UnitTestCase {
 private array $input;private int $owner;
 public function setUp():void {
  parent::setUp();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());DigiForge\Core\Activator::activate();$this->owner=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($this->owner);ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($this->owner,['personalized_pod'],['manage_digiforge_connections','manage_digiforge_pod']);global $wpdb;$now=gmdate('Y-m-d H:i:s');DigiForge\Core\Settings::protectProduction();$suffix=wp_generate_uuid4();$businessKey='read-business-'.$suffix;$storeKey='read-store-'.$suffix;
  $wpdb->insert(Tables::businesses(),['business_key'=>$businessKey,'display_name'=>'Read fixture','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::stores(),['business_id'=>$business,'store_key'=>$storeKey,'display_name'=>'Read store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);
  $scope=['business_id'=>$businessKey,'store_id'=>$storeKey,'product_program'=>'PERSONALIZED_POD'];$repo=new DigiForge\Integrations\Repository();$integration=$repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'catalog-fixture-'.$suffix,'status'=>'CONFIGURED','config'=>['shop_key'=>'personalized_pod','catalog_read_scope'=>$scope]]);self::assertIsArray($integration);$id=$integration['id'];self::assertTrue($repo->storeSecret($id,'personal_access_token','offline-catalog-fixture-credential'));self::assertIsArray($repo->setEnabled($id,true));ScopedProviderConnectionFixture::approve($id,$this->owner);
  $this->input=['capability'=>'printify.catalog.read','integration_id'=>$id,'account_id'=>$this->owner]+$scope+['expires_at'=>gmdate('c',time()+1800),'max_requests'=>2,'requests'=>[['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202]]];
 }
 private function unlocked():void {global $wpdb;foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false]as $key=>$v)$wpdb->query($wpdb->prepare('UPDATE '.Tables::settings().' SET setting_value=%s WHERE setting_key=%s',wp_json_encode($v),$key));}
 private function client(int &$calls):DigiForge\POD\PrintifyCatalogClient {return new DigiForge\POD\PrintifyCatalogClient(static function($url,$args)use(&$calls){++$calls;self::assertSame('https://api.printify.com/v1/catalog/blueprints/101/print_providers/2/variants.json',$url);self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);self::assertSame(10,$args['timeout']);return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202,"title":"synthetic only","placeholders":[]},{"id":203}]}'];});}

 private function grant(Reads $r):array {$this->input['requests']=[['kind'=>'variants','blueprint_id'=>101,'provider_id'=>2]]; $g=$r->approve($this->input,'scoped-fixture-'.wp_generate_password(12,false));self::assertIsArray($g);return $g;}
 private function geometryPreview(array $response):array|WP_Error {
  $calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls,$response){++$calls;return ['response'=>['code'=>200],'body'=>wp_json_encode($response)];});
  $r=new Reads($client);$g=$this->grant($r);$this->unlocked();$result=DigiForge\POD\PrintifyCatalogPreview::inspectScoped($g['sha256'],0,'geometry-'.wp_generate_uuid4(),$client);self::assertSame(1,$calls);return $result;
 }
 public function testTemplateFreeRectanglesRemainVariantSpecificAndUncertified():void {
  $response=['id'=>2,'variants'=>[
   ['id'=>202,'placeholders'=>[['position'=>'front','decoration_method'=>'dtg','width'=>3951,'height'=>4800]],'decoration_methods'=>['dtg']],
   ['id'=>203,'placeholders'=>[['position'=>'front','decoration_method'=>'dtg','width'=>3000,'height'=>4000]]],
  ]];
  $out=$this->geometryPreview($response);self::assertIsArray($out);
  $canonical=['{"decoration_methods":["dtg"],"id":202,"placeholders":[{"decoration_method":"dtg","height":4800,"position":"front","width":3951}]}','{"id":203,"placeholders":[{"decoration_method":"dtg","height":4000,"position":"front","width":3000}]}'];
  self::assertSame(hash('sha256',wp_json_encode($response)),$out['source_sha256']);self::assertSame('printify-catalog-rectangles-v1',$out['geometry_evidence_schema']);
  foreach($out['variants']as $i=>$variant){self::assertSame($response['variants'][$i]['placeholders'],$variant['catalog_placeholders']);self::assertSame('OBSERVED',$variant['placeholder_dimensions_status']);self::assertNull($variant['print_area']);self::assertSame('UNKNOWN',$variant['geometry_status']);self::assertSame('UNKNOWN',$variant['safe_zone_status']);self::assertSame('UNKNOWN',$variant['bleed_status']);self::assertSame('UNKNOWN',$variant['dpi_status']);self::assertSame('UNKNOWN',$variant['production_tolerance_status']);self::assertSame(hash('sha256',$canonical[$i]),$variant['variant_record_sha256']);}
  self::assertSame('UNKNOWN',$out['provider_routing_status']);self::assertSame('UNKNOWN',$out['cross_variant_scaling_status']);self::assertSame('NOT_CERTIFIED',$out['certification']);self::assertSame('NONE',$out['persistence']);
 }
 public function testAbsentTemplatesAndPlaceholdersDoNotClaimIncompatibility():void {
  $out=$this->geometryPreview(['variants'=>[['id'=>202],['id'=>203,'placeholders'=>[]]]]);self::assertIsArray($out);
  foreach($out['variants']as $v){self::assertSame([],$v['catalog_placeholders']);self::assertSame('UNKNOWN',$v['placeholder_dimensions_status']);self::assertSame('UNKNOWN',$v['geometry_status']);}
 }
 public function testMalformedOrConflictingGeometryIsNotDraftEvidence():void {
  $valid=['position'=>'front','decoration_method'=>'dtg','width'=>3951,'height'=>4800];$cases=[];
  foreach(['width'=>0,'height'=>4800.5,'position'=>'<script>','decoration_method'=>'','width_px'=>3951]as $key=>$value){$p=$valid;$p[$key]=$value;if($key==='width_px')unset($p['width']);$cases[]=['variants'=>[['id'=>202,'placeholders'=>[$p]]]];}
  $cases[]=['variants'=>[['id'=>202,'placeholders'=>[$valid,$valid]]]];
  $cases[]=['variants'=>[['id'=>202,'placeholders'=>['front'=>$valid]]]];
  $cases[]=['variants'=>[['id'=>202,'placeholders'=>[$valid],'decoration_methods'=>['embroidery']]]];
  $cases[]=['id'=>999,'variants'=>[['id'=>202,'placeholders'=>[$valid]]]];
  foreach($cases as $response)self::assertWPError($this->geometryPreview($response));
 }
 public function testScopedClientCannotUsePermissiveCallbackWithoutReservedGrant():void {
  $calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;return ['response'=>['code'=>200],'body'=>'{}'];},null,static fn()=>true);
  self::assertWPError($client->scopedRead('synthetic-token',['kind'=>'variants','blueprint_id'=>101,'provider_id'=>2]));self::assertSame(0,$calls);
 }
 public function testScopedPreviewUsesExistingGrantAndDoesNotPersistCatalogEvidence():void {
  $calls=0;$r=new Reads($this->client($calls));$g=$this->grant($r);$this->unlocked();global $wpdb;
  $before=$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::pod_catalog());
  $out=DigiForge\POD\PrintifyCatalogPreview::inspectScoped($g['sha256'],0,'preview-request',$this->client($calls));self::assertIsArray($out);self::assertSame('NONE',$out['persistence']);self::assertSame('UNKNOWN',$out['shipping_status']);self::assertNull($out['variants'][0]['print_area']);self::assertSame(1,$calls);
  self::assertSame($before,$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::pod_catalog()));
  self::assertNull($wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','df_catalog_read_evidence_'.$g['sha256'].'_'.hash('sha256','preview-request'))));
  self::assertWPError(DigiForge\POD\PrintifyCatalogPreview::inspectScoped($g['sha256'],0,'preview-request',$this->client($calls)));self::assertSame(1,$calls);
 }
 public function testScopedRateLimitNeverRetriesOrReusesConsumedReservation():void {
  $calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;return ['response'=>['code'=>429],'body'=>'private'];},static function(){throw new RuntimeException('must not retry');},static fn()=>true);
  $r=new Reads($client);$g=$this->grant($r);$this->unlocked();self::assertWPError($r->preview($g['sha256'],0,'rate-request'));self::assertWPError($r->preview($g['sha256'],0,'rate-request'));self::assertSame(1,$calls);
 }
 public function testInFlightLockRevocationAndConnectorDriftDiscardResults():void {
  foreach(['lock','revoke','connector','account']as $change){$calls=0;$grant=null;$r=null;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls,&$grant,&$r,$change){++$calls;
   if($change==='lock')DigiForge\Core\Settings::protectProduction();
   elseif($change==='revoke')$r->revoke($grant['sha256']);
   elseif($change==='connector')(new DigiForge\Integrations\Repository())->setEnabled($grant['grant']['integration_id'],false);
   else wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
   return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202}]}'];});
   $r=new Reads($client);$grant=$this->grant($r);$this->unlocked();self::assertWPError($r->preview($grant['sha256'],0,'changed-'.$change));self::assertSame(1,$calls);wp_set_current_user($this->owner);
   if($change==='connector'){(new DigiForge\Integrations\Repository())->setEnabled($this->input['integration_id'],true);ScopedProviderConnectionFixture::approve($this->input['integration_id'],$this->owner);}
  }
 }
 public function testCrossAccountAndExpiredGrantDeny():void {
  $calls=0;$r=new Reads($this->client($calls));$g=$this->grant($r);$this->unlocked();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));self::assertWPError($r->preview($g['sha256'],0,'cross-account'));wp_set_current_user($this->owner);
  $this->input['expires_at']=gmdate('c',time()+1);$g=$this->grant($r);usleep(1100000);self::assertWPError($r->preview($g['sha256'],0,'expired-request'));self::assertSame(0,$calls);
 }

 public function testBoundedMalformedAndProtectedResponsesAreRejectedWithoutRawLeak():void {
  $bodies=['not JSON','null',str_repeat('x',1048577),'{"variants":[{"id":202,"note":"\\u006fffline-catalog-fixture-credential"}]}','{"variants":[{"id":202,"access_token":"private"}]}','{"variants":[{"id":202},{"id":202}]}','{"variants":[{"id":202,"title":"<script>"}]}','{"variants":[{"id":"202"}]}'];
  $this->unlocked();foreach($bodies as $i=>$body){$calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function($url,$args)use(&$calls,$body){++$calls;self::assertSame(1048577,$args['limit_response_size']);self::assertTrue($args['sslverify']);return ['response'=>['code'=>200],'body'=>$body];});$r=new Reads($client);$g=$this->grant($r);$out=DigiForge\POD\PrintifyCatalogPreview::inspectScoped($g['sha256'],0,'bad-body-'.$i,$client);self::assertWPError($out);self::assertStringNotContainsString('offline-catalog-fixture-credential',wp_json_encode($out));self::assertSame(1,$calls);}
 }
 public function testDefaultLocksCrossScopeAndExpiryDuringRequestDeny():void {
  $calls=0;$r=new Reads($this->client($calls));$g=$this->grant($r);self::assertWPError($r->preview($g['sha256'],0,'locked-request'));self::assertSame(0,$calls);
  $this->unlocked();$out=(new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;return [];}))->scopedRead('not-the-configured-token',['kind'=>'variants','blueprint_id'=>101,'provider_id'=>999],['grant_sha256'=>$g['sha256'],'request_index'=>0,'request_sha256'=>hash('sha256','locked-request')]);self::assertWPError($out);self::assertSame(0,$calls);
  // Leave time for durable setup; expire inside transport, not before admission.
  $expiresAt=time()+5;$this->input['expires_at']=gmdate('c',$expiresAt);
  $client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls,$expiresAt){++$calls;usleep(max(0,$expiresAt-time())*1000000+100000);return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202}]}'];});
  $r=new Reads($client);$g=$this->grant($r);$result=$r->preview($g['sha256'],0,'expires-inflight');
  self::assertWPError($result);self::assertSame('catalog_read_expired_or_owner_revoked',$result->get_error_code());self::assertSame(1,$calls);
 }
 public function testReservedDispatchCannotReplayOrUseForeignCredential():void {
  $calls=0;$client=$this->client($calls);$r=new Reads($client);$g=$this->grant($r);$this->unlocked();self::assertIsArray($r->preview($g['sha256'],0,'single-dispatch'));
  $scope=['grant_sha256'=>$g['sha256'],'request_index'=>0,'request_sha256'=>hash('sha256','single-dispatch')];$request=$this->input['requests'][0];
  self::assertWPError($client->scopedRead('foreign-secret',$request,$scope));self::assertWPError($client->scopedRead('offline-catalog-fixture-credential',$request,$scope));self::assertSame(1,$calls);
 }
 private function permitted(Reads $r):array {
  $g=$this->grant($r);$e=$r->approveSafetyException($g['sha256'],['capability'=>'printify.catalog.read','expires_at'=>gmdate('c',time()+600),'acknowledge_catalog_get_only'=>true,'acknowledge_no_mutations'=>true,'acknowledge_locks_remain_on'=>true],'probe-exception-'.wp_generate_password(12,false));self::assertIsArray($e);self::assertTrue(DigiForge\Core\Settings::safety_locked());return $g;
 }
 public function testDeletedGrantRevocationMustRemainDenied():void {
  $calls=0;$r=new Reads($this->client($calls));$g=$this->permitted($r);self::assertTrue($r->revoke($g['sha256']));self::assertWPError($r->preview($g['sha256'],0,'before-deletion'));
  global $wpdb;$count=$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='catalog_read_revoked'");self::assertGreaterThan(0,(int)$count);
  $wpdb->delete($wpdb->options,['option_name'=>'df_catalog_read_revoked_'.$g['sha256']]);
  $after=$r->preview($g['sha256'],0,'after-deletion');self::assertSame($count,$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='catalog_read_revoked'"));
  self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());
  self::assertWPError($after,'Retained grant revocation audit must deny when its option is lost.');
 }
 public function testDeletedDispatchMustNotRedispatchSameReservedRequest():void {
  $calls=0;$client=$this->client($calls);$r=new Reads($client);$g=$this->permitted($r);self::assertIsArray($r->preview($g['sha256'],0,'probe-dispatch'));
  $key=hash('sha256','probe-dispatch');global $wpdb;$wpdb->delete($wpdb->options,['option_name'=>'df_catalog_read_dispatch_'.$g['sha256'].'_'.$key]);
  $after=$client->scopedRead('offline-catalog-fixture-credential',$g['grant']['requests'][0],['grant_sha256'=>$g['sha256'],'request_index'=>0,'request_sha256'=>$key]);
  self::assertSame(1,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());
  self::assertWPError($after,'Retained dispatch audit must deny a second dispatch after option loss.');
 }
 public function testMalformedVariantEnvelopeMustNotBecomeDraftEvidence():void {
  $client=new DigiForge\POD\PrintifyCatalogClient(static fn()=>['response'=>['code'=>200],'body'=>'{"unrelated":"synthetic unexpected field"}']);$r=new Reads($client);$g=$this->permitted($r);
  $out=$r->read($g['sha256'],0,'malformed-envelope');self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertWPError($r->evidence($g['sha256'],hash('sha256','malformed-envelope')));
  self::assertWPError($out,'Variants response must contain a validated variants list.');
 }

 public function testConcurrentDispatchLockAndRetryDoNotCreateSecondAttempt():void {
  $calls=0;$client=$this->client($calls);$r=new Reads($client);$g=$this->permitted($r);
  $connection=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$lock='df-catalog-read-'.substr($g['sha256'],0,45);
  self::assertSame('1',(string)$connection->get_var($connection->prepare('SELECT GET_LOCK(%s,0)',$lock)));
  try{self::assertWPError($r->preview($g['sha256'],0,'concurrent-request'));self::assertSame(0,$calls);}finally{$connection->get_var($connection->prepare('SELECT RELEASE_LOCK(%s)',$lock));$connection->close();}
  self::assertIsArray($r->preview($g['sha256'],0,'concurrent-request'));self::assertWPError($r->preview($g['sha256'],0,'concurrent-request'));self::assertSame(1,$calls);
 }
 public function testMissingDispatchAuditOrUnavailableAnchorsFailClosed():void {
  $calls=0;$client=$this->client($calls);$r=new Reads($client);$g=$this->permitted($r);self::assertIsArray($r->preview($g['sha256'],0,'audit-presence'));
  $key=hash('sha256','audit-presence');global $wpdb;$wpdb->query($wpdb->prepare('DELETE FROM '.Tables::audit_log().' WHERE event_type=%s AND context LIKE %s','catalog_read_dispatched','%'.$wpdb->esc_like('"request_sha256":"'.$key.'"').'%'));
  $authority=['grant_sha256'=>$g['sha256'],'request_index'=>0,'request_sha256'=>$key];self::assertWPError($r->authorizeReservedAttempt($authority,$g['grant']['requests'][0],'offline-catalog-fixture-credential',false));
  self::assertWPError($client->scopedRead('offline-catalog-fixture-credential',$g['grant']['requests'][0],$authority));self::assertSame(1,$calls);
  $g=$this->permitted($r);$wpdb->suppress_errors(true);$f=static fn(string $sql):string=>str_starts_with($sql,'SELECT context FROM ')&&str_contains($sql,'catalog_read_revoked')?'SELECT * FROM nonexistent_catalog_revocation_probe':$sql;add_filter('query',$f);
  try{self::assertWPError($r->preview($g['sha256'],0,'anchor-outage'));self::assertSame(1,$calls);}finally{remove_filter('query',$f);$wpdb->suppress_errors(false);}
 }
 public function testMalformedEndpointRecordsCannotBeSavedAsEvidence():void {
  $cases=[
   [['kind'=>'blueprint','blueprint_id'=>101],'{"id":999}'],
   [['kind'=>'providers','blueprint_id'=>101],'{"providers":[]}'],
   [['kind'=>'providers','blueprint_id'=>101],'[{"id":2},{"id":2}]'],
   [['kind'=>'variants','blueprint_id'=>101,'provider_id'=>2],'{"variants":[{"id":"202"}]}'],
   [['kind'=>'variants','blueprint_id'=>101,'provider_id'=>2],'{"variants":{"x":{"id":202}}}'],
   [['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202],'{"variants":[{"id":202},{"id":202}]}'],
  ];
  foreach($cases as $n=>[$request,$body]){$calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls,$body){++$calls;return ['response'=>['code'=>200],'body'=>$body];});$r=new Reads($client);$this->input['requests']=[$request];$g=$r->approve($this->input,'envelope-grant-'.$n);self::assertIsArray($g);$e=$r->approveSafetyException($g['sha256'],['capability'=>'printify.catalog.read','expires_at'=>gmdate('c',time()+600),'acknowledge_catalog_get_only'=>true,'acknowledge_no_mutations'=>true,'acknowledge_locks_remain_on'=>true],'envelope-exception-'.$n);self::assertIsArray($e);
   self::assertWPError($r->read($g['sha256'],0,'envelope-request-'.$n));self::assertWPError($r->evidence($g['sha256'],hash('sha256','envelope-request-'.$n)));self::assertSame(1,$calls);
  }
 }
}
