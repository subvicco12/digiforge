<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\{NativeBridge,NativeAuthorityAdapter,NativePanelAccessAdapter,NativePortContract,Bridge,ExternalOperationIntent,ShopBinding,CredentialReference};
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once __DIR__.'/Support/ReviewedOwnershipFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';

/** Actual disposable WordPress sessions/ACL/reviews; no real owner or supplier evidence. */
final class NativePrintifyCorePortWordPressTest extends WP_UnitTestCase
{
 private int $http=0;private int $ciphertext=0;private $httpTripwire;private $queryTripwire;
 protected function setUp():void {
  parent::setUp();PortalAccessFixture::reset();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
  $this->httpTripwire=function(){++$this->http;throw new RuntimeException('No provider HTTP permitted');};
  $this->queryTripwire=function(string $sql):string{if(preg_match('/^\s*SELECT\s/i',$sql)&&preg_match('/\bFROM\s+`?'.preg_quote(DigiForge\Database\Tables::integration_secrets(),'/').'`?\b/i',$sql)&&preg_match('/\bciphertext\b|\*/i',substr($sql,0,(int)stripos($sql,'FROM')))){++$this->ciphertext;throw new RuntimeException('No credential reads permitted');}return $sql;};
  add_filter('pre_http_request',$this->httpTripwire,PHP_INT_MAX);add_filter('query',$this->queryTripwire,PHP_INT_MAX);
 }
 protected function tearDown():void {
  remove_filter('pre_http_request',$this->httpTripwire,PHP_INT_MAX);remove_filter('query',$this->queryTripwire,PHP_INT_MAX);
  self::assertSame(0,$this->http);self::assertSame(0,$this->ciphertext);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
  global $wpdb;$wpdb->query('SET autocommit=0');PortalAccessFixture::reset();parent::tearDown();
 }
 private function scope():ShopBinding {
  $actors=ReviewedLocalFixture::actors('personalized_pod');$owner=$actors['author'];$old=ReviewedOwnershipFixture::create($this);$id=$old['integration_id'];
  $repo=new DigiForge\Integrations\Repository();self::assertTrue($repo->storeSecret($id,'personal_access_token','SYNTHETIC-NATIVE-NON-CREDENTIAL'));self::assertNotWPError($repo->setEnabled($id,true));
  ScopedProviderConnectionFixture::approve($id,$owner,null,'printify.connection.read');
  $caps=['manage_digiforge','manage_digiforge_settings','manage_digiforge_connections','manage_digiforge_pod','manage_digiforge_orders','manage_digiforge_listings'];
  ShopAccessFixture::authorizeActor($owner,['personalized_pod'],$caps);PortalAccessFixture::login($owner);
  $v=(new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$id,'printify.connection.read');self::assertNotWPError($v);
  return new ShopBinding(2,'personalized_pod',$id,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($id),$v['binding_hash'],$v['expires_at']);
 }
 private function context(ShopBinding $s,string $view='orders'):array{return ['version'=>'1.0.0','view'=>$view,'session_fingerprint'=>hash('sha256',wp_get_session_token()),'csrf_token'=>wp_create_nonce(NativePanelAccessAdapter::nonceAction($s,$view))];}
 public function testCurrentWordPressSessionAndSignedReviewedScopePermitOnlyPanelRead():void {
  $s=$this->scope();$p=NativeBridge::connect('1.0.0');$a=get_current_user_id();
  $r=$p->panelAccess($s,$a,$this->context($s));self::assertNotWPError($r);self::assertTrue(NativePortContract::valid('panel_access',$r));self::assertTrue($r['read_only']);self::assertFalse($r['external_execution_authorized']);$proof=$r;unset($proof['receipt_hash']);self::assertSame(DigiForgePrintifyConnector\Preparation\MutationIntent::hash($proof),$r['receipt_hash']);
  self::assertNotWPError($p->authorizeWorkflow($s,$a,'inspect_workflows'));
  self::assertWPError($p->authorizeWorkflow($s,$a,'record_order'));
 }
 public function testBuiltSourceMatchesPinnedActualCompanionPanelAdapter():void {
  if(!is_file(DIGIFORGE_PATH.'includes/Connector/Printify/native-build.json'))$this->markTestSkipped('Build-owned source identity is required; exercised again against extracted artifact.');
  $s=$this->scope();$p=NativeBridge::connect('1.0.0');$m=$p->contractManifest();self::assertTrue(NativePortContract::valid('manifest',$m));self::assertSame(['PANEL','GET_READ'],$m['capabilities']);
  $adapter=new DigiForgePrintifyConnector\Preparation\NativePortAdapter($p,$m['core_source_sha']);$r=$adapter->panelAccess($s,get_current_user_id(),$this->context($s));self::assertNotWPError($r);
  $wrong=new DigiForgePrintifyConnector\Preparation\NativePortAdapter($p,str_repeat('0',40));self::assertWPError($wrong->panelAccess($s,get_current_user_id(),$this->context($s)));
  self::assertWPError($adapter->exchange(NativePrintifyContractFixture::mutation('AUTHORIZE')));
 }
 public function testWrongNonceViewSessionActorAndDestroyedSessionDeny():void {
  $s=$this->scope();$p=NativeBridge::connect('1.0.0');$a=get_current_user_id();$c=$this->context($s);
  foreach(['csrf_token'=>'badnonce','session_fingerprint'=>str_repeat('f',64),'view'=>'publication']as $key=>$value)self::assertWPError($p->panelAccess($s,$a,array_replace($c,[$key=>$value])));
  self::assertWPError($p->panelAccess($s,$a+100000,$c));
  WP_Session_Tokens::get_instance($a)->destroy(wp_get_session_token());self::assertWPError($p->panelAccess($s,$a,$c));
 }
 public function testStaleProviderRevisionAndCrossShopCannotBorrowAuthority():void {
  $s=$this->scope();$a=get_current_user_id();$p=NativeBridge::connect('1.0.0');
  $stale=new ShopBinding(2,$s->shopKey,$s->integrationId,$s->providerShopId,$s->environment,$s->revision+1,$s->credential,$s->bindingHash,$s->expiresAt);
  self::assertWPError($p->panelAccess($stale,$a,$this->context($stale)));
  $foreign=new ShopBinding(3,'standard_pod',$s->integrationId,$s->providerShopId,$s->environment,$s->revision,$s->credential,$s->bindingHash,$s->expiresAt);self::assertWPError($p->panelAccess($foreign,$a,$this->context($foreign)));
  $q=NativePrintifyContractFixture::mutation('AUTHORIZE');$q['binding']['actor_id']=$a;$q['context']['actor_id']=$a;$r=$p->exchange($q);self::assertWPError($r);self::assertSame('printify_native_journal_and_independent_checkpoint_required',$r->get_error_code());
 }
 public function testAdminWithoutSignedGrantAndRoleRevocationDeny():void {
  $s=$this->scope();$a=get_current_user_id();$p=NativeBridge::connect('1.0.0');$c=$this->context($s);
  wp_get_current_user()->add_cap('manage_digiforge_orders',false);self::assertWPError($p->panelAccess($s,$a,$c));
  $admin=self::factory()->user->create(['role'=>'administrator']);PortalAccessFixture::login($admin);self::assertWPError($p->panelAccess($s,$admin,$this->context($s)));
 }
 public function testLockedGetAndPublicationNeverReachCredentialsOrHttp():void {
  $s=$this->scope();$a=get_current_user_id();$p=NativeBridge::connect('1.0.0');
  $i=new ExternalOperationIntent($a,'personalized_pod',$s->integrationId,'SHOPS_READ',[],str_repeat('a',32));
  self::assertWPError(Bridge::connect('1.0.0')->read($i));self::assertWPError($p->read($i));
  $binding=NativePrintifyContractFixture::binding();$binding['actor_id']=$a;self::assertWPError($p->authorizePublication($binding,[]));
  $called=false;self::assertWPError($p->withWebhookSecret($s,function()use(&$called){$called=true;}));self::assertFalse($called);
 }
 public function testRecoveryNeverAcknowledgesGenerationOrDrainEvenAfterRepeatedStop():void {
  $s=$this->scope();$a=get_current_user_id();$p=NativeBridge::connect('1.0.0');global $wpdb;
  // Only disposable local test settings. No emergency writer or rearm API is invented.
  $wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  for($n=0;$n<2;$n++){self::assertTrue(DigiForge\Core\Settings::protectProduction());$r=$p->recovery($s,$a,['action'=>'INSPECT']);self::assertWPError($r);self::assertSame('printify_native_independent_checkpoint_and_drain_required',$r->get_error_code());self::assertArrayNotHasKey('emergency_acknowledged',$r->get_error_data());}
  foreach(['REARM','RETRY','RESTORE']as $action)self::assertWPError($p->recovery($s,$a,['action'=>$action]));
 }
}
