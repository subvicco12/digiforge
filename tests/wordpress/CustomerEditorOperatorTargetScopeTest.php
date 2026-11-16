<?php
declare(strict_types=1);
require_once __DIR__.'/CustomerPhotoHeldIntegrationTest.php';
require_once __DIR__.'/fixtures/PhotoWorkflowFixtureIsolation.php';
use DigiForge\Database\Tables;
use DigiForge\POD\{CustomerEditorRepository,CustomerEditorWorkflow};
use DigiForge\PortalAccess\{Session,Store};
use DigiForge\Security\{ShopAccess,ShopAccessTargetScope};

/** Actual signed operator, stored customer access and registered REST callbacks. */
final class CustomerEditorOperatorTargetScopeTest extends WP_UnitTestCase {
 use NativeBusinessOrderFixture;
 use PhotoWorkflowFixtureIsolation {tearDown as private restorePhotoIsolation;}
 private array $options=[];
 private array $users=[];
 private array $f=[];
 private int $operator;
 private int $http=0;
 private $denyHttp;
 public function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();$this->capturePhotoFixtureState();$this->isolatePhotoFixtureRows(Tables::businesses(),['business_key'=>'digicraftifygoods']);$this->bootOrderFixture('digicraftifygoods');global $wpdb;
  foreach([Tables::pod_catalog(),Tables::pod_production_templates(),Tables::shop_ai_policies(),Tables::shop_ai_usage(),Tables::fulfillment_intents(),Tables::pod_render_evidence(),Tables::pod_authorization_packages()]as $table)$this->rows[$table]=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);
  foreach(['df_ce_','df_photo_','digiforge_render_']as $prefix)foreach($wpdb->get_results($wpdb->prepare('SELECT * FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like($prefix).'%'),ARRAY_A)as $row)$this->options[$row['option_name']]=$row;
  $this->denyHttp=function(){$this->http++;return new WP_Error('unexpected_editor_scope_http');};add_filter('pre_http_request',$this->denyHttp);
  // Reuse the actual unified producer fixture, sharing its already approved native source.
  $builder=new CustomerPhotoHeldIntegrationTest();(new ReflectionProperty($builder,'fixture'))->setValue($builder,$this->fixture);
  $this->f=(new ReflectionMethod($builder,'photo'))->invoke($builder);$this->users[]=$this->f['customer'];
  $this->operator=self::factory()->user->create(['role'=>'administrator']);$this->users[]=$this->operator;
  PrintifyApiApprovalFixture::checked(Store::change($this->operator,'Operator',['personalized_pod'=>['personalized_pod']],true,0,wp_generate_uuid4()));
  PortalAccessFixture::login($this->operator);PortalAccessFixture::verifyEmail(get_userdata($this->operator)->user_email);self::assertTrue(Session::start());self::assertSame(['personalized_pod'],ShopAccess::allowedShops('manage_digiforge_pod'));
  self::assertFalse(ShopAccess::authorize('digital','manage_digiforge_pod')===true);self::assertSame('personalized_pod',(new CustomerEditorRepository())->targetShopForSession($this->f['session']));
  global $wp_rest_server;$wp_rest_server=new WP_REST_Server();do_action('rest_api_init');
 }
 public function tearDown():void {
  remove_filter('pre_http_request',$this->denyHttp);
  try{global $wpdb;$wpdb->query('ROLLBACK');foreach(['df_ce_','df_photo_','digiforge_render_']as $prefix)$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like($prefix).'%'));foreach($this->options as $row)$wpdb->insert($wpdb->options,$row,array_fill(0,count($row),'%s'));require_once ABSPATH.'wp-admin/includes/user.php';foreach($this->users as $id)wp_delete_user($id);$this->restoreOrderFixture();self::assertSame(0,$this->http);}finally{global $wp_rest_server;$wp_rest_server=null;$this->restorePhotoIsolation();}
 }
 private function request(string $action,array $body=[],array $query=[],bool $nonce=true):WP_REST_Request {
  $r=new WP_REST_Request('POST','/digiforge/v1/customer-editor/'.$this->f['session'].'/'.$action);$r->set_url_params(['session'=>$this->f['session']]);$r->set_header('Content-Type','application/json');if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_body(wp_json_encode($body));$r->set_query_params($query);return $r;
 }
 private function view():array{return ['operation'=>'PHOTO_VIEW','shop_key'=>'personalized_pod'];}
 public function testPodOnlyOperatorReachesAllThreeRealRegisteredCallbacks():void {
  $view=rest_do_request($this->request('intake',$this->view()));self::assertSame(200,$view->get_status(),wp_json_encode($view->get_data()));
  $layout=rest_do_request($this->request('publish',['operation'=>'PHOTO_LAYOUT','shop_key'=>'personalized_pod','selection_sha256'=>$this->f['selection']['sha256'],'expected_revision'=>1,'template_key'=>$this->f['template'],'template_version'=>1,'note'=>'Synthetic scoped local layout review']));self::assertSame(200,$layout->get_status(),wp_json_encode($layout->get_data()));
  $revoke=rest_do_request($this->request('revoke',['access_sha256'=>$this->f['access_sha256']]));self::assertSame(200,$revoke->get_status(),wp_json_encode($revoke->get_data()));self::assertWPError((new CustomerEditorRepository())->targetShopForSession($this->f['session']));self::assertGreaterThanOrEqual(400,rest_do_request($this->request('intake',$this->view()))->get_status());
  self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
 }
 public function testConflictingClaimsCannotShadowRegisteredSessionOrShop():void {
  foreach([['session'=>'other_session'],['shop'=>'digital'],['shop_key'=>'digital'],['session'=>[$this->f['session']]]]as $query){$request=$this->request('intake',$this->view(),$query);self::assertWPError(ShopAccessTargetScope::forRequest($request,['personalized_pod']));self::assertGreaterThanOrEqual(400,rest_do_request($request)->get_status());}
  $r=$this->request('intake',$this->view());$r->set_body_params(['session'=>'other_session']);self::assertWPError(ShopAccessTargetScope::forRequest($r,['personalized_pod']),'Lower priority form parameters must not hide behind JSON.');
  $r=$this->request('intake',$this->view());$r->set_body_params(['shop_key'=>'digital']);self::assertWPError(ShopAccessTargetScope::forRequest($r,['personalized_pod']));
  $r=$this->request('intake',$this->view());$r->set_url_params(['session'=>'other_session']);self::assertWPError(ShopAccessTargetScope::forRequest($r,['personalized_pod']));
  $r=$this->request('intake',$this->view()+['session'=>'other_session']);self::assertWPError(ShopAccessTargetScope::forRequest($r,['personalized_pod']));
 }
 public function testProducerErrorsAndCallerTransactionDenyWithoutGlobalFallback():void {
  global $wpdb;$repo=new CustomerEditorRepository();$r=$this->request('intake',$this->view());
  self::assertNotFalse($wpdb->query('START TRANSACTION'));try{$expected=$repo->targetShopForSession($this->f['session']);$actual=ShopAccessTargetScope::forRequest($r,['personalized_pod']);self::assertWPError($expected);self::assertWPError($actual);self::assertSame($expected->get_error_code(),$actual->get_error_code());self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));}finally{$wpdb->query('ROLLBACK');}
  $access=PrintifyApiApprovalFixture::checked($repo->access($this->f['session'],true));$path=DigiForge\ProductFactory\AssetStorage::absolutePath($access['proof_reference']);self::assertNotNull($path);$bytes=file_get_contents($path);
  try{file_put_contents($path,'{}');$expected=$repo->targetShopForSession($this->f['session']);$actual=ShopAccessTargetScope::forRequest($r,['personalized_pod']);self::assertWPError($actual);self::assertSame($expected->get_error_code(),$actual->get_error_code());self::assertGreaterThanOrEqual(400,rest_do_request($r)->get_status());}finally{file_put_contents($path,$bytes);}
  self::assertSame(1,$wpdb->update(Tables::orders(),['shop_reference'=>'digital'],['id'=>$this->f['order']]));$expected=$repo->targetShopForSession($this->f['session']);$actual=ShopAccessTargetScope::forRequest($r,['personalized_pod']);self::assertWPError($actual);self::assertSame($expected->get_error_code(),$actual->get_error_code());
 }
 public function testNonceBuyerAndMissingGrantRemainDenied():void {
  self::assertSame(403,rest_do_request($this->request('intake',$this->view(),[],false))->get_status());self::assertWPError(ShopAccessTargetScope::forRequest($this->request('intake',$this->view()),['digital']));
  $this->fixture->owner();$digital=self::factory()->user->create(['role'=>'administrator']);$this->users[]=$digital;PrintifyApiApprovalFixture::checked(Store::change($digital,'Operator',['digital'=>['digital']],true,0,wp_generate_uuid4()));PortalAccessFixture::login($digital);PortalAccessFixture::verifyEmail(get_userdata($digital)->user_email);self::assertTrue(Session::start());self::assertSame([],ShopAccess::allowedShops('manage_digiforge_pod'));self::assertSame(403,rest_do_request($this->request('intake',$this->view()))->get_status());
  PortalAccessFixture::login($this->f['customer']);self::assertSame(403,rest_do_request($this->request('intake',$this->view()))->get_status());wp_set_current_user(0);self::assertGreaterThanOrEqual(400,rest_do_request($this->request('intake',$this->view()))->get_status());
 }
 public function testBuyerAndUnregisteredRouteGrammarRemainOutsideTargetBridge():void {
  foreach([['GET','intake'],['POST','view'],['POST','upload'],['POST','fields'],['POST','approve'],['POST','intake/extra'],['POST','mockup-evidence']]as [$method,$action]){$r=$this->request($action);$r->set_method($method);self::assertFalse(ShopAccessTargetScope::forRequest($r,['personalized_pod']));}
  // SOURCE_INSPECTION only: this process's actual final producer class cannot lose its API.
  $source=file_get_contents((new ReflectionClass(ShopAccessTargetScope::class))->getFileName());
  self::assertStringContainsString("!defined(\$class.'::OPERATOR_SESSION_SCOPE_CONTRACT')",$source);
  self::assertStringContainsString("constant(\$class.'::OPERATOR_SESSION_SCOPE_CONTRACT')!=='shop2-editor-session-scope-v1'",$source);
  self::assertStringContainsString("!method_exists(\$class,'targetShopForSession')",$source);
 }
}
