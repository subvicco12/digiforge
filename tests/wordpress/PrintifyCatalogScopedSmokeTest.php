<?php
declare(strict_types=1);
use DigiForge\POD\PrintifyCatalogReadRepository as Reads;
use DigiForge\Database\Tables;
final class PrintifyCatalogScopedSmokeTest extends WP_UnitTestCase {
 private array $input;private int $owner;
 public function setUp():void {
  parent::setUp();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());DigiForge\Core\Activator::activate();$this->owner=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($this->owner);global $wpdb;$now=gmdate('Y-m-d H:i:s');DigiForge\Core\Settings::protectProduction();$suffix=wp_rand(10000,99999);$businessKey='read-business-'.$suffix;$storeKey='read-store-'.$suffix;
  $wpdb->insert(Tables::businesses(),['business_key'=>$businessKey,'display_name'=>'Read fixture','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::stores(),['business_id'=>$business,'store_key'=>$storeKey,'display_name'=>'Read store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);
  $scope=['business_id'=>$businessKey,'store_id'=>$storeKey,'product_program'=>'PERSONALIZED_POD'];$repo=new DigiForge\Integrations\Repository();$integration=$repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'catalog-fixture-'.$suffix,'status'=>'CONFIGURED','config'=>['catalog_read_scope'=>$scope]]);self::assertIsArray($integration);$id=$integration['id'];self::assertTrue($repo->storeSecret($id,'personal_access_token','offline-catalog-fixture-credential'));self::assertIsArray($repo->setEnabled($id,true));
  $this->input=['capability'=>'printify.catalog.read','integration_id'=>$id,'account_id'=>$this->owner]+$scope+['expires_at'=>gmdate('c',time()+1800),'max_requests'=>2,'requests'=>[['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202]]];
 }
 private function unlocked():void {global $wpdb;foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false]as $key=>$v)$wpdb->query($wpdb->prepare('UPDATE '.Tables::settings().' SET setting_value=%s WHERE setting_key=%s',wp_json_encode($v),$key));}
 private function client(int &$calls):DigiForge\POD\PrintifyCatalogClient {return new DigiForge\POD\PrintifyCatalogClient(static function($url,$args)use(&$calls){++$calls;self::assertSame('https://api.printify.com/v1/catalog/blueprints/101/print_providers/2/variants.json',$url);self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);self::assertSame(10,$args['timeout']);return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202,"title":"synthetic only","placeholders":[]},{"id":203}]}'];});}

 private function grant(Reads $r):array {$this->input['requests']=[['kind'=>'variants','blueprint_id'=>101,'provider_id'=>2]]; $g=$r->approve($this->input,'scoped-fixture-'.wp_generate_password(12,false));self::assertIsArray($g);return $g;}
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
   if($change==='connector')(new DigiForge\Integrations\Repository())->setEnabled($this->input['integration_id'],true);
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
  $this->input['expires_at']=gmdate('c',time()+1);$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;usleep(1100000);return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202}]}'];});$r=new Reads($client);$g=$this->grant($r);self::assertWPError($r->preview($g['sha256'],0,'expires-inflight'));self::assertSame(1,$calls);
 }
 public function testReservedDispatchCannotReplayOrUseForeignCredential():void {
  $calls=0;$client=$this->client($calls);$r=new Reads($client);$g=$this->grant($r);$this->unlocked();self::assertIsArray($r->preview($g['sha256'],0,'single-dispatch'));
  $scope=['grant_sha256'=>$g['sha256'],'request_index'=>0,'request_sha256'=>hash('sha256','single-dispatch')];$request=$this->input['requests'][0];
  self::assertWPError($client->scopedRead('foreign-secret',$request,$scope));self::assertWPError($client->scopedRead('offline-catalog-fixture-credential',$request,$scope));self::assertSame(1,$calls);
 }
}
