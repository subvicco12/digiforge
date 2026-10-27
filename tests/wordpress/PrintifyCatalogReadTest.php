<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';
use DigiForge\POD\PrintifyCatalogReadRepository as Reads;
use DigiForge\Database\Tables;
final class PrintifyCatalogReadTest extends WP_UnitTestCase {
 private array $input;private int $owner;
 public function setUp():void {
  parent::setUp();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());DigiForge\Core\Activator::activate();$this->owner=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($this->owner);ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($this->owner,['digital','personalized_pod','standard_pod','jewelry'],['manage_digiforge_connections','manage_digiforge_pod']);global $wpdb;$now=gmdate('Y-m-d H:i:s');DigiForge\Core\Settings::protectProduction();$suffix=wp_generate_uuid4();$businessKey='read-business-'.$suffix;$storeKey='read-store-'.$suffix;
  $wpdb->insert(Tables::businesses(),['business_key'=>$businessKey,'display_name'=>'Read fixture','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::stores(),['business_id'=>$business,'store_key'=>$storeKey,'display_name'=>'Read store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);
  $scope=['business_id'=>$businessKey,'store_id'=>$storeKey,'product_program'=>'PERSONALIZED_POD'];$repo=new DigiForge\Integrations\Repository();$integration=$repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'catalog-fixture-'.$suffix,'status'=>'CONFIGURED','config'=>['shop_key'=>'personalized_pod','catalog_read_scope'=>$scope]]);self::assertIsArray($integration);$id=$integration['id'];self::assertTrue($repo->storeSecret($id,'personal_access_token','offline-catalog-fixture-credential'));self::assertIsArray($repo->setEnabled($id,true));ScopedProviderConnectionFixture::approve($id,$this->owner);
  $this->input=['capability'=>'printify.catalog.read','integration_id'=>$id,'account_id'=>$this->owner]+$scope+['expires_at'=>gmdate('c',time()+1800),'max_requests'=>2,'requests'=>[['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202]]];
 }
 private function unlocked():void {global $wpdb;foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false]as $key=>$v)$wpdb->query($wpdb->prepare('UPDATE '.Tables::settings().' SET setting_value=%s WHERE setting_key=%s',wp_json_encode($v),$key));}
 private function client(int &$calls):DigiForge\POD\PrintifyCatalogClient {return new DigiForge\POD\PrintifyCatalogClient(static function($url,$args)use(&$calls){++$calls;self::assertSame('https://api.printify.com/v1/catalog/blueprints/101/print_providers/2/variants.json',$url);self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);self::assertSame(10,$args['timeout']);return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202,"title":"synthetic only","placeholders":[]},{"id":203}]}'];});}
 public function testOwnerApprovalCannotReleaseSafetyLocksOrReadCredentials():void {
  self::assertTrue(class_exists(Reads::class),'Scoped catalog read repository must exist.');$calls=0;$r=new Reads($this->client($calls));$grant=$r->approve($this->input,'grant-locked');self::assertIsArray($grant);self::assertSame('printify.catalog.read',$grant['grant']['capability']);$read=$r->read($grant['sha256'],0,'request-locked');self::assertWPError($read);self::assertSame('catalog_read_safety_locked',$read->get_error_code());self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::is_enabled('printify'));
 }
 public function testExactReadPersistsDraftEvidenceAndRejectsReplayCrossAccountRevocationAndTampering():void {
  self::assertTrue(class_exists(Reads::class));$calls=0;$r=new Reads($this->client($calls));$grant=$r->approve($this->input,'grant-functional');self::assertIsArray($grant);$this->unlocked();self::assertFalse(DigiForge\Core\Settings::is_enabled('printify'));
  $subscriber=self::factory()->user->create(['role'=>'subscriber']);wp_set_current_user($subscriber);self::assertWPError($r->read($grant['sha256'],0,'cross-account'));wp_set_current_user($this->owner);
  global $wpdb;$beforeCatalog=$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::pod_catalog());$read=$r->read($grant['sha256'],0,'first-read');self::assertIsArray($read);self::assertSame('DRAFT_UNVERIFIED',$read['state']);self::assertSame(202,$read['data']['id']);self::assertSame(1,$calls);self::assertSame(64,strlen($read['response_sha256']));self::assertSame($read,$r->evidence($grant['sha256'],hash('sha256','first-read')));self::assertWPError($r->read($grant['sha256'],0,'first-read'));self::assertSame(1,$calls);
  global $wpdb;self::assertSame($beforeCatalog,$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::pod_catalog()));self::assertTrue($r->revoke($grant['sha256']));self::assertWPError($r->read($grant['sha256'],0,'after-revoke'));self::assertSame(1,$calls);
  $grant=$r->approve($this->input,'grant-tamper');$name='df_catalog_read_grant_'.$grant['sha256'];$stored=json_decode($wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$name)),true);$stored['grant']['account_id']=$subscriber;$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($stored)],['option_name'=>$name]);self::assertWPError($r->read($grant['sha256'],0,'tampered'));self::assertSame(1,$calls);
 }
 public function testIntegrationShopDriftAndQuotaFailClosed():void {
  self::assertTrue(class_exists(Reads::class));$calls=0;$r=new Reads($this->client($calls));$this->input['max_requests']=1;$grant=$r->approve($this->input,'grant-quota');$this->unlocked();self::assertIsArray($r->read($grant['sha256'],0,'request-one'));self::assertWPError($r->read($grant['sha256'],0,'request-two'));self::assertSame(1,$calls);
  $grant=$r->approve($this->input,'grant-drift');(new DigiForge\Integrations\Repository())->update($this->input['integration_id'],['config'=>['catalog_read_scope'=>['business_id'=>'other','store_id'=>'read-store','product_program'=>'PERSONALIZED_POD']]]);self::assertWPError($r->read($grant['sha256'],0,'request-drift'));self::assertSame(1,$calls);
 }
 public function testEscapedCredentialsRedirectsLargeBodiesAndMalformedResponsesAreNeverEvidence():void {
  self::assertTrue(class_exists(Reads::class));$this->unlocked();$calls=0;
  $bodies=[['response'=>['code'=>302],'body'=>'{}'],['response'=>['code'=>429],'body'=>'{}'],['response'=>['code'=>200],'body'=>str_repeat('x',1048577)],['response'=>['code'=>200],'body'=>'{"variants":[{"id":202,"note":"\\u006fffline-catalog-fixture-credential"}]}'],['response'=>['code'=>200],'body'=>'{"variants":[{"id":202,"access_token":"sensitive"}]}'],['response'=>['code'=>200],'body'=>'not JSON']];
  foreach($bodies as $i=>$response){$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls,$response){++$calls;return $response;});$r=new Reads($client);$grant=$r->approve($this->input,'unsafe-grant-'.$i);self::assertIsArray($grant);$result=$r->read($grant['sha256'],0,'unsafe-request');self::assertWPError($result);self::assertStringNotContainsString('offline-catalog-fixture-credential',wp_json_encode($result));self::assertWPError($r->evidence($grant['sha256'],hash('sha256','unsafe-request')));self::assertWPError($r->read($grant['sha256'],0,'unsafe-request'));}
  self::assertSame(count($bodies),$calls);
 }
 public function testExpiredApprovalMainSiteDriftAndConcurrentReservationAreBlocked():void {
  self::assertTrue(class_exists(Reads::class));$calls=0;$r=new Reads($this->client($calls));$this->input['expires_at']=gmdate('c',time()+1);$grant=$r->approve($this->input,'grant-expiry');self::assertIsArray($grant);$this->unlocked();usleep(1100000);self::assertWPError($r->read($grant['sha256'],0,'expired-read'));self::assertSame(0,$calls);
  $this->input['expires_at']=gmdate('c',time()+1800);$grant=$r->approve($this->input,'grant-concurrency');self::assertIsArray($grant);$connection=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$lock='df-catalog-read-'.substr($grant['sha256'],0,45);self::assertSame('1',(string)$connection->get_var($connection->prepare('SELECT GET_LOCK(%s,0)',$lock)));try{self::assertWPError($r->read($grant['sha256'],0,'concurrent-read'));self::assertSame(0,$calls);}finally{$connection->get_var($connection->prepare('SELECT RELEASE_LOCK(%s)',$lock));$connection->close();}
  $site=site_url();update_option('siteurl','https://recovery.invalid');try{self::assertWPError($r->approve($this->input,'different-site'));self::assertWPError($r->read($grant['sha256'],0,'different-site'));self::assertSame(0,$calls);}finally{update_option('siteurl',$site);}
 }

 public function testAlteredReservationCannotRestoreQuota():void {
  self::assertTrue(class_exists(Reads::class));$calls=0;$r=new Reads($this->client($calls));$this->input['max_requests']=1;$grant=$r->approve($this->input,'grant-receipt-integrity');$this->unlocked();self::assertIsArray($r->read($grant['sha256'],0,'consumed-request'));global $wpdb;$name='df_catalog_read_request_'.$grant['sha256'].'_'.hash('sha256','consumed-request');$wpdb->update($wpdb->options,['option_value'=>'{}'],['option_name'=>$name]);$wpdb->delete($wpdb->options,['option_name'=>'df_catalog_read_count_'.$grant['sha256']]);self::assertWPError($r->read($grant['sha256'],0,'new-request'));self::assertSame(1,$calls);$wpdb->delete($wpdb->options,['option_name'=>$name]);self::assertWPError($r->read($grant['sha256'],0,'deleted-receipt'));self::assertSame(1,$calls);
 }

 public function testAuditAndReservationCommitFailuresPreventTransport():void {
  self::assertTrue(class_exists(Reads::class));$calls=0;$r=new Reads($this->client($calls));$grant=$r->approve($this->input,'grant-audit-failure');self::assertIsArray($grant);$this->unlocked();global $wpdb;$wpdb->suppress_errors(true);
  $auditFailure=static function(string $sql):string {return str_contains($sql,'INSERT INTO `'.Tables::audit_log().'`')&&str_contains($sql,'catalog_read_reserved')?'SELECT * FROM nonexistent_catalog_audit_fixture':$sql;};add_filter('query',$auditFailure);try{self::assertWPError($r->read($grant['sha256'],0,'audit-failure'));self::assertSame(0,$calls);}finally{remove_filter('query',$auditFailure);$wpdb->suppress_errors(false);}
  $wpdb->suppress_errors(true);$commitFailure=static fn(string $sql):string=>trim(strtoupper($sql))==='COMMIT'?'SELECT * FROM nonexistent_catalog_commit_fixture':$sql;add_filter('query',$commitFailure);try{self::assertWPError($r->read($grant['sha256'],0,'commit-failure'));self::assertSame(0,$calls);}finally{remove_filter('query',$commitFailure);$wpdb->query('ROLLBACK');$wpdb->suppress_errors(false);}
 }

 public function testRestNonceAccountAndUnknownFieldsAreRejectedWithoutProviderCalls():void {
  self::assertTrue(class_exists(Reads::class));$controller=new DigiForge\REST\PrintifyCatalogReadController();$req=new WP_REST_Request('POST','/digiforge/v1/printify/catalog-read/grants');self::assertWPError($controller->canApprove($req));$req->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));self::assertTrue($controller->canApprove($req));$req->set_header('Content-Type','application/json');$req->set_header('Idempotency-Key','rest-grant');$req->set_body(wp_json_encode($this->input+['enable_printify'=>true]));self::assertWPError($controller->approve($req));
  $grant=(new Reads())->approve($this->input,'rest-valid-grant');self::assertIsArray($grant);$get=new WP_REST_Request('GET','/digiforge/v1/printify/catalog-read/'.$grant['sha256']);$get->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$get->set_header('Idempotency-Key','rest-read-request');$get->set_query_params(['request_index'=>'0']);$controller->register();do_action('rest_api_init');$response=rest_do_request($get);self::assertSame(409,$response->get_status());self::assertSame('catalog_read_safety_locked',$response->get_data()['code']);$get->set_method('POST');self::assertSame(404,rest_do_request($get)->get_status());
  wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));self::assertWPError($controller->canRead($req));
 }
 private function exceptionInput():array{return ['capability'=>'printify.catalog.read','expires_at'=>gmdate('c',time()+600),'acknowledge_catalog_get_only'=>true,'acknowledge_no_mutations'=>true,'acknowledge_locks_remain_on'=>true];}
 public function testSeparateExceptionPermitsOnlyCatalogGetWithoutChangingGlobalSafety():void {
  self::assertTrue(method_exists(Reads::class,'approveSafetyException'),'Separate owner exception approval must exist.');$calls=0;$r=new Reads($this->client($calls));$grant=$r->approve($this->input,'exception-grant');$exception=$r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-approval');self::assertIsArray($exception);self::assertWPError($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-approval'));$result=$r->read($grant['sha256'],0,'exception-read');self::assertIsArray($result);self::assertSame($exception['sha256'],$result['safety_exception_sha256']);self::assertSame('DRAFT_UNVERIFIED',$result['state']);self::assertSame(1,$calls);
  self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue(DigiForge\Core\Settings::get('stop_all'));self::assertFalse(DigiForge\Core\Settings::get('automation_armed'));self::assertFalse(DigiForge\Core\Settings::get('activation_authorized'));
  foreach(['printify','gelato','etsy_publish','etsy_draft','order_automation','research','ai','product_development','gst_automation']as $cap)self::assertFalse(DigiForge\Core\Settings::is_enabled($cap));
  $credentialCalls=0;self::assertWPError((new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($this->input['integration_id'],function()use(&$credentialCalls){++$credentialCalls;}));self::assertSame(0,$credentialCalls);self::assertWPError((new DigiForge\POD\PrintifyCatalogSyncService())->syncProviderVariants($this->input['integration_id'],101,2));
  foreach(['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE']as $action){$denied=DigiForge\POD\PrintifyLiveTransportInterlock::authorize(['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>true,'external_execution_performed'=>false,'action'=>$action],['action'=>$action]);self::assertWPError($denied);self::assertSame('digiforge_printify_interlock_locked',$denied->get_error_code());}
  self::assertWPError($r->read($grant['sha256'],0,'exception-read'));self::assertIsArray($r->read($grant['sha256'],0,'exception-read-two'));self::assertWPError($r->read($grant['sha256'],0,'exception-read-three'));self::assertSame(2,$calls);
 }
 public function testExceptionRevocationExpiryAndSafetyPostureDriftNeverFallBack():void {
  self::assertTrue(method_exists(Reads::class,'approveSafetyException'));$calls=0;$r=new Reads($this->client($calls));$grant=$r->approve($this->input,'exception-revoke-grant');self::assertIsArray($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-revoke'));self::assertTrue($r->revokeSafetyException($grant['sha256']));self::assertWPError($r->read($grant['sha256'],0,'revoked-exception'));$this->unlocked();self::assertWPError($r->read($grant['sha256'],0,'revoked-unlocked'));DigiForge\Core\Settings::protectProduction();
  $grant=$r->approve($this->input,'exception-expire-grant');$body=$this->exceptionInput();$body['expires_at']=gmdate('c',time()+2);self::assertIsArray($r->approveSafetyException($grant['sha256'],$body,'exception-expire'));usleep(2100000);self::assertWPError($r->read($grant['sha256'],0,'expired-exception'));self::assertSame(0,$calls);
  $grant=$r->approve($this->input,'exception-drift-grant');self::assertIsArray($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-drift'));global $wpdb;$wpdb->query("UPDATE ".Tables::settings()." SET setting_value='true' WHERE setting_key='automation_armed'");self::assertWPError($r->read($grant['sha256'],0,'unsafe-posture'));self::assertSame(0,$calls);
 }
 public function testExceptionCannotCrossGrantAccountScopeOrSurviveEvidenceTampering():void {
  self::assertTrue(method_exists(Reads::class,'approveSafetyException'));$calls=0;$r=new Reads($this->client($calls));$grant=$r->approve($this->input,'exception-integrity-grant');$exception=$r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-integrity');self::assertIsArray($exception);
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$wrongAccount=$r->read($grant['sha256'],0,'wrong-manager-account');self::assertWPError($wrongAccount);self::assertSame('catalog_read_account_scope',$wrongAccount->get_error_code());
  wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));self::assertWPError($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'unprivileged'));self::assertWPError($r->read($grant['sha256'],0,'other-account'));wp_set_current_user($this->owner);
  $other=$r->approve($this->input,'exception-other-grant');self::assertWPError($r->read($other['sha256'],0,'other-grant'));global $wpdb;$name='df_catalog_get_exception_'.$grant['sha256'];$wpdb->update($wpdb->options,['option_value'=>'{}'],['option_name'=>$name]);self::assertWPError($r->read($grant['sha256'],0,'tampered-exception'));$wpdb->delete($wpdb->options,['option_name'=>$name]);self::assertWPError($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'replacement-exception'));self::assertWPError($r->read($grant['sha256'],0,'missing-exception'));self::assertSame(0,$calls);
 }
 public function testExceptionRestApprovalRequiresNonceExplicitAcknowledgementsAndOwner():void {
  self::assertTrue(method_exists(Reads::class,'approveSafetyException'));$grant=(new Reads())->approve($this->input,'exception-rest-grant');$controller=new DigiForge\REST\PrintifyCatalogReadController();$controller->register();do_action('rest_api_init');$path='/digiforge/v1/printify/catalog-read/'.$grant['sha256'].'/safety-exception';$request=new WP_REST_Request('POST',$path);$request->set_header('Content-Type','application/json');$request->set_header('Idempotency-Key','exception-rest-approval');$request->set_body(wp_json_encode($this->exceptionInput()));self::assertSame(403,rest_do_request($request)->get_status());$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));self::assertSame(200,rest_do_request($request)->get_status());self::assertSame(409,rest_do_request($request)->get_status());$request->set_method('PUT');self::assertSame(404,rest_do_request($request)->get_status());
 }

 public function testInFlightSafetyChangeDiscardsEvidenceAndConsumesAttempt():void {
  $calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;global $wpdb;$wpdb->query("UPDATE ".Tables::settings()." SET setting_value='true' WHERE setting_key='automation_armed'");return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202}]}'];});$r=new Reads($client);$grant=$r->approve($this->input,'exception-inflight-grant');self::assertIsArray($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-inflight'));self::assertWPError($r->read($grant['sha256'],0,'inflight-request'));self::assertWPError($r->evidence($grant['sha256'],hash('sha256','inflight-request')));DigiForge\Core\Settings::protectProduction();self::assertWPError($r->read($grant['sha256'],0,'inflight-request'));self::assertSame(1,$calls);
 }
 public function testInFlightExceptionOwnerRevocationCannotReturnEvidence():void {
  $approver=self::factory()->user->create(['role'=>'administrator']);$calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls,$approver){++$calls;$u=new WP_User($approver);$u->set_role('subscriber');return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202}]}'];});$r=new Reads($client);$grant=$r->approve($this->input,'exception-owner-grant');ShopAccessFixture::authorizeActor($approver,['personalized_pod'],['manage_digiforge','manage_digiforge_connections','manage_digiforge_settings','manage_digiforge_pod']);wp_set_current_user($approver);self::assertIsArray($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-other-owner'));wp_set_current_user($this->owner);$result=$r->read($grant['sha256'],0,'owner-inflight');self::assertWPError($result);self::assertSame('catalog_read_exception_owner_revoked',$result->get_error_code());self::assertWPError($r->evidence($grant['sha256'],hash('sha256','owner-inflight')));self::assertSame(1,$calls);
 }
 public function testDeletedRevocationAndUnavailableSafetyEvidenceNeverRestoreException():void {
  $calls=0;$r=new Reads($this->client($calls));$grant=$r->approve($this->input,'exception-delete-revoke-grant');self::assertIsArray($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-delete-revoke'));self::assertTrue($r->revokeSafetyException($grant['sha256']));global $wpdb;$wpdb->delete($wpdb->options,['option_name'=>'df_catalog_get_exception_revoked_'.$grant['sha256']]);self::assertWPError($r->read($grant['sha256'],0,'deleted-revocation'));$this->unlocked();self::assertWPError($r->read($grant['sha256'],0,'unlocked-deleted-revocation'));DigiForge\Core\Settings::protectProduction();
  $grant=$r->approve($this->input,'exception-safety-missing-grant');self::assertIsArray($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-safety-missing'));$wpdb->delete(Tables::settings(),['setting_key'=>'automation_armed']);self::assertWPError($r->read($grant['sha256'],0,'missing-safety-row'));self::assertSame(0,$calls);
 }
 public function testExceptionApprovalAuditFailureDoesNotCreateAdmission():void {
  $calls=0;$r=new Reads($this->client($calls));$grant=$r->approve($this->input,'exception-audit-grant');global $wpdb;$wpdb->suppress_errors(true);$f=static fn(string $sql):string=>str_contains($sql,'INSERT INTO `'.Tables::audit_log().'`')&&str_contains($sql,'catalog_get_exception_approved')?'SELECT * FROM nonexistent_exception_audit_fixture':$sql;add_filter('query',$f);try{self::assertWPError($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'exception-audit-failure'));}finally{remove_filter('query',$f);$wpdb->suppress_errors(false);}self::assertWPError($r->read($grant['sha256'],0,'failed-exception-approval'));self::assertSame(0,$calls);
 }

 public function testResearchReceiptCacheUsesNoSecondDispatchAndRechecksScope():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$grant=$r->approve($this->input,'research-cache-grant');
  $first=$r->research($grant['sha256'],0,'research-first',300);self::assertIsArray($first);self::assertSame('MISS',$first['cache_status']);
  $second=$r->research($grant['sha256'],0,'research-second',300);self::assertIsArray($second);self::assertSame('HIT',$second['cache_status']);self::assertSame(1,$calls);self::assertSame($first['response_sha256'],$second['response_sha256']);
  self::assertTrue($r->revoke($grant['sha256']));self::assertWPError($r->research($grant['sha256'],0,'research-revoked',300));self::assertSame(1,$calls);
 }
 public function testResearchCacheCannotBypassLocksExpiryOrMissingAudit():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$grant=$r->approve($this->input,'research-block-grant');self::assertIsArray($r->research($grant['sha256'],0,'research-block-first',300));
  DigiForge\Core\Settings::protectProduction();self::assertWPError($r->research($grant['sha256'],0,'research-locked',300));self::assertSame(1,$calls);$this->unlocked();
  global $wpdb;$before=$r->evidence($grant['sha256'],hash('sha256','research-block-first'));self::assertIsArray($before);self::assertSame(1,$wpdb->delete(Tables::audit_log(),['event_type'=>'catalog_read_evidence','object_id'=>$before['sha256']],['%s','%s']));self::assertWPError($r->evidence($grant['sha256'],hash('sha256','research-block-first')));
  self::assertWPError($r->research($grant['sha256'],0,'research-missing-audit',300));self::assertSame(1,$calls);
 }
 public function testAccountRateReservationSurvivesCrossGrantAndMissingRow():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$grant=$r->approve($this->input,'research-rate-first');self::assertIsArray($r->read($grant['sha256'],0,'research-rate-one'));
  global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'df_catalog_rate_%%' AND option_value LIKE %s",'%'.$wpdb->esc_like($grant['sha256']).'%'),ARRAY_A);self::assertCount(1,$rows);
  $wpdb->delete($wpdb->options,['option_name'=>$rows[0]['option_name']]);$other=$r->approve($this->input,'research-rate-second');self::assertWPError($r->read($other['sha256'],0,'research-rate-two'));self::assertSame(1,$calls);
 }

 public function testAccountRateCeilingAcrossGrantsAndRateAuditFailure():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$grant=$r->approve($this->input,'research-ceiling-first');
  $method=new ReflectionMethod(Reads::class,'reserveAccountRate');$method->setAccessible(true);
  for($i=0;$i<50;$i++)self::assertTrue($method->invoke($r,$grant));
  $other=$r->approve($this->input,'research-ceiling-second');$denied=$r->read($other['sha256'],0,'research-ceiling-denied');self::assertWPError($denied);self::assertSame('catalog_read_account_rate_limit',$denied->get_error_code());self::assertSame(0,$calls);
 }
 public function testResearchRestCacheIsNonceProtectedAndBounded():void {
  $controller=new DigiForge\REST\PrintifyCatalogReadController();$controller->register();do_action('rest_api_init');$grant=(new Reads())->approve($this->input,'research-rest-grant');
  $request=new WP_REST_Request('GET','/digiforge/v1/printify/catalog-read/'.$grant['sha256']);$request->set_query_params(['request_index'=>0,'max_age'=>901]);$request->set_header('Idempotency-Key','research-rest-request');
  self::assertSame(403,rest_do_request($request)->get_status());$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));self::assertSame(400,rest_do_request($request)->get_status());
 }

 public function testRateReservationCannotHideAnInFlightLockChange():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$grant=$r->approve($this->input,'research-rate-lock-grant');
  $hook=static function(string $sql):string{if(str_contains($sql,'catalog_read_rate_reserved')&&str_starts_with($sql,'INSERT')){global $wpdb;$wpdb->query("UPDATE ".Tables::settings()." SET setting_value='true' WHERE setting_key='stop_all'");}return $sql;};
  add_filter('query',$hook);try{self::assertWPError($r->read($grant['sha256'],0,'research-rate-lock'));self::assertSame(0,$calls);}finally{remove_filter('query',$hook);}
 }
 public function testCachedEvidenceCannotLeakToARevokedShopAssignment():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$grant=$r->approve($this->input,'research-assignment-grant');self::assertIsArray($r->research($grant['sha256'],0,'research-assignment-first'));
  ShopAccessFixture::reset();self::assertWPError($r->research($grant['sha256'],0,'research-assignment-denied'));self::assertWPError($r->evidence($grant['sha256'],hash('sha256','research-assignment-first')));self::assertSame(1,$calls);
 }
 public function testStructuredHttpFailuresRemainConsumedAndSanitized():void {
  $this->unlocked();foreach([401=>'AUTHORIZATION',403=>'AUTHORIZATION',429=>'RATE_LIMIT',503=>'PROVIDER_UNAVAILABLE']as $status=>$category){$calls=0;$r=new Reads(new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls,$status){++$calls;return ['response'=>['code'=>$status],'body'=>'private provider body'];}));$grant=$r->approve($this->input,'research-http-'.$status);$result=$r->read($grant['sha256'],0,'research-http-attempt');self::assertWPError($result);self::assertSame($status,$result->get_error_data()['provider_status']);self::assertSame($category,$result->get_error_data()['category']);self::assertFalse($result->get_error_data()['retry_permitted']);self::assertStringNotContainsString('private provider body',wp_json_encode($result));self::assertWPError($r->read($grant['sha256'],0,'research-http-attempt'));self::assertSame(1,$calls);}
 }

 public function testResearchConflictingReceiptsCannotBeSilentlySelected():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$grant=$r->approve($this->input,'research-conflict-grant');self::assertIsArray($r->read($grant['sha256'],0,'research-conflict-first'));
  $other=new Reads(new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202,"title":"different synthetic observation"}]}'];}));self::assertIsArray($other->read($grant['sha256'],0,'research-conflict-second'));
  $result=$r->research($grant['sha256'],0,'research-conflict-cache');self::assertWPError($result);self::assertSame('catalog_read_cache_conflict',$result->get_error_code());self::assertSame(2,$calls);
 }

 public function testPodOnlyRevocationDuringFinalCacheReloadDeniesHit():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$reader=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($reader,['personalized_pod'],['manage_digiforge','manage_digiforge_connections']);$podOwner=ShopAccessFixture::authorizeActor($reader,['personalized_pod'],['manage_digiforge_pod']);$this->input['account_id']=$reader;$grant=$r->approve($this->input,'research-race-grant');wp_set_current_user($reader);self::assertIsArray($r->research($grant['sha256'],0,'research-race-first'));
  $loads=0;$revoked=false;$hook=static function(string $sql)use(&$loads,&$revoked,$grant,$reader,$podOwner):string{
   if(str_starts_with($sql,'SELECT option_value')&&str_contains($sql,'df_catalog_read_grant_'.$grant['sha256'])&&++$loads===3){$revoked=true;wp_set_current_user($podOwner);$acl=new DigiForge\Security\ShopAccessRepository();$state=$acl->read('personalized_pod');foreach($state['grants']as $id=>$record){if($record['actor_id']===$reader&&$record['grantor_id']===$podOwner&&!$record['revoked']){self::assertIsArray($acl->revoke('personalized_pod',$id,'research-cache-pod-revoke',$state['revision']));break;}}wp_set_current_user($reader);}return $sql;};
  add_filter('query',$hook);try{$result=$r->research($grant['sha256'],0,'research-race-cached');}finally{remove_filter('query',$hook);}self::assertTrue($revoked);self::assertWPError($result);self::assertSame(1,$calls);
 }

 public function testSeparateGrantCannotDispatchWhileProviderAccountLockIsHeld():void {
  $calls=0;$r=new Reads($this->client($calls));$this->unlocked();$grant=$r->approve($this->input,'research-account-lock-grant');$context=DigiForge\POD\PrintifyConnectionBoundary::context('personalized_pod',$this->input['integration_id'],'printify.catalog.read');
  $json=new ReflectionMethod(Reads::class,'json');$json->setAccessible(true);$account=hash('sha256',$json->invoke(null,array_intersect_key($context,array_flip(['installation_sha256','environment','provider','external_account_reference']))));$connection=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$lock='df-catalog-read-'.substr($account,0,45);self::assertSame('1',(string)$connection->get_var($connection->prepare('SELECT GET_LOCK(%s,0)',$lock)));
  try{self::assertWPError($r->read($grant['sha256'],0,'research-account-lock-request'));self::assertSame(0,$calls);}finally{$connection->get_var($connection->prepare('SELECT RELEASE_LOCK(%s)',$lock));$connection->close();}
 }

 public function testPreparedLiveAcceptancePlanIsEightGetOnlyReadsUnderUnchangedLocks():void {
  $plan=DigiForge\POD\PrintifyCatalogResearch::requests(49,[410,217,61]);$this->input['requests']=$plan;$this->input['max_requests']=8;$calls=[];
  $client=new DigiForge\POD\PrintifyCatalogClient(static function(string $url,array $args)use(&$calls):array{
   self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);self::assertTrue($args['sslverify']);$calls[]=$url;
   if($url==='https://api.printify.com/v1/catalog/blueprints/49.json')$data=['id'=>49,'title'=>'synthetic blueprint'];
   elseif($url==='https://api.printify.com/v1/catalog/blueprints/49/print_providers.json')$data=[['id'=>410,'title'=>'synthetic provider410'],['id'=>217,'title'=>'synthetic provider217'],['id'=>61,'title'=>'synthetic provider61']];
   elseif(preg_match('#^https://api\.printify\.com/v1/catalog/blueprints/49/print_providers/(410|217|61)/variants\.json$#',$url,$match))$data=['id'=>(int)$match[1],'variants'=>[['id'=>1000+(int)$match[1],'title'=>'synthetic variant','decoration_methods'=>['dtg'],'placeholders'=>[['position'=>'front','decoration_method'=>'dtg','width'=>100,'height'=>200]]]]];
   else{self::assertMatchesRegularExpression('#^https://api\.printify\.com/v2/catalog/blueprints/49/print_providers/(410|217|61)/shipping\.json$#',$url);$data=['data'=>[['id'=>'1','type'=>'shipping_method','attributes'=>['name'=>'standard']]]];}
   return ['response'=>['code'=>200],'body'=>wp_json_encode($data)];
  });
  $r=new Reads($client);$grant=$r->approve($this->input,'prepared-acceptance-grant');self::assertIsArray($grant);self::assertWPError($r->research($grant['sha256'],0,'prepared-before-exception'));self::assertCount(0,$calls);
  self::assertIsArray($r->approveSafetyException($grant['sha256'],$this->exceptionInput(),'prepared-explicit-exception'));$receipts=[];
  foreach($plan as $index=>$scope){$e=$r->research($grant['sha256'],$index,'prepared-read-'.$index,0);self::assertIsArray($e);ksort($scope);self::assertSame($scope,$e['request']);self::assertFalse($e['supplier_certified']);self::assertFalse($e['mutation_authorized']);$receipts[]=$e;}
  self::assertCount(8,$calls);$comparison=DigiForge\POD\PrintifyCatalogResearch::compare($receipts);self::assertFalse($comparison['supplier_certified']);foreach($comparison['providers']as $provider){self::assertSame('UNKNOWN',$provider['safe_zone']);self::assertSame('UNKNOWN',$provider['dpi']);self::assertSame('UNKNOWN_UNTIL_EXPLICIT_METHOD_READ',$provider['destination_rates']);}
  foreach($plan as $index=>$scope){$cached=$r->research($grant['sha256'],$index,'prepared-cache-'.$index);self::assertIsArray($cached);self::assertSame('HIT',$cached['cache_status']);}self::assertCount(8,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue((bool)DigiForge\Core\Settings::get('stop_all',true));self::assertFalse(DigiForge\Core\Settings::is_enabled('printify'));
  self::assertWPError($r->research($grant['sha256'],8,'prepared-outside-scope'));self::assertTrue($r->revoke($grant['sha256']));self::assertWPError($r->research($grant['sha256'],0,'prepared-revoked'));self::assertCount(8,$calls);
 }

}
