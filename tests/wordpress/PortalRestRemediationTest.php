<?php
use DigiForge\PortalAccess\{Guard,Store,Session};
use DigiForge\Security\ShopAccessAuthority;
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PortalRestRemediationTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 private function actor(string $role,array $modules):int{PortalAccessFixture::enroll();$id=self::factory()->user->create(['role'=>'administrator']);self::assertIsArray(Store::change($id,$role,['personalized_pod'=>$modules],true,0,wp_generate_uuid4()));PortalAccessFixture::login($id);PortalAccessFixture::verifyEmail(get_userdata($id)->user_email);self::assertTrue(Session::start());return $id;}
 public function testPreviouslyUnmappedRoutesCannotEscapeAnyManagedRole():void{foreach(['Viewer','Operator','Admin'] as $role){PortalAccessFixture::reset();$this->actor($role,['research']);foreach([['GET','/digiforge/v1/launch/status'],['POST','/digiforge/v1/etsy/controlled-draft'],['POST','/digiforge/v1/customer-editor/access'],['GET','/digiforge/v1/recovery/evidence'],['POST','/digiforge/v1/controls/stop_all'],['GET','/digiforge/v1/new-sensitive-route'],['OPTIONS','/digiforge/v1/products']] as [$verb,$route])self::assertWPError((new Guard())->rest(null,[],new WP_REST_Request($verb,$route)),$role.' '.$verb.' '.$route);}}
 public function testGlobalCollectionCannotBeScopedByUserClaim():void{$this->actor('Admin',['products']);$r=new WP_REST_Request('GET','/digiforge/v1/products');$r->set_param('shop','personalized_pod');self::assertWPError((new Guard())->rest(null,[],$r));}
 public function testConflictingOrMalformedShopAliasesAreDenied():void{$this->actor('Operator',['personalized_pod','research','integrations']);foreach(['digital',['personalized_pod'],0] as $claim){$r=new WP_REST_Request('GET','/digiforge/v1/shops/personalized_pod/printify-research/readiness');$r->set_param('shop','personalized_pod');$r->set_param('shop_key',$claim);self::assertWPError((new Guard())->rest(null,[],$r));}}
 public function testFixedShopReadStillRequiresCurrentNativeGrantAndProfile():void{$id=$this->actor('Operator',['personalized_pod','research','integrations']);$r=new WP_REST_Request('GET','/digiforge/v1/shops/personalized_pod/printify-research/readiness');self::assertNull((new Guard())->rest(null,[],$r));get_userdata($id)->add_cap('manage_digiforge_research',false);self::assertWPError((new Guard())->rest(null,[],$r));}
 public function testDisabledAndStaleSessionsCannotEnterKnownRead():void{$id=$this->actor('Admin',['personalized_pod','research','integrations']);$s=Store::read($id);PortalAccessFixture::login((int)get_user_by('email',\DigiForge\PortalAccess\Owner::EMAIL)->ID);PortalAccessFixture::verifyEmail();Session::start();self::assertIsArray(Store::change($id,'Admin',['personalized_pod'=>['research']],false,$s['revision'],wp_generate_uuid4()));PortalAccessFixture::login($id);$r=new WP_REST_Request('GET','/digiforge/v1/shops/personalized_pod/printify-research/readiness');self::assertWPError((new Guard())->rest(null,[],$r));}
 public function testUnknownSensitiveRouteDeniesUnmanagedAdministratorToo():void{wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));self::assertWPError((new Guard())->rest(null,[],new WP_REST_Request('GET','/digiforge/v1/new-sensitive-route')));}
 public function testEd25519RejectsWrongKeySignatureExpiryActorAndShop():void{$keys=sodium_crypto_sign_keypair();$key=base64_encode(sodium_crypto_sign_publickey($keys));$secret=sodium_crypto_sign_secretkey($keys);$record=['actor_id'=>123,'shop'=>'personalized_pod','caps'=>['manage_digiforge'],'expires_at'=>time()+60,'source_approval_hash'=>str_repeat('a',64),'authority'=>'OWNER'];$verify=static function($r)use($secret,$key){$j=wp_json_encode(['version'=>1,'authorities'=>[$r]]);return ShopAccessAuthority::verify($j,base64_encode(sodium_crypto_sign_detached($j,$secret)),$key,time());};self::assertIsArray($verify($record));foreach([['actor_id'=>0],['actor_id'=>'123'],['shop'=>'unknown'],['expires_at'=>time()-1],['authority'=>'administrator']] as $bad)self::assertWPError($verify(array_replace($record,$bad)));$json=wp_json_encode(['version'=>1,'authorities'=>[$record]]);$signature=base64_encode(sodium_crypto_sign_detached($json,$secret));foreach(['',base64_encode(random_bytes(32)),base64_encode(random_bytes(31))] as $badKey)self::assertWPError(ShopAccessAuthority::verify($json,$signature,$badKey,time()));self::assertWPError(ShopAccessAuthority::verify($json,base64_encode(random_bytes(64)),$key,time()));self::assertWPError(ShopAccessAuthority::verify($json.' ',$signature,$key,time()));sodium_memzero($secret);}
 public function testEveryRegisteredRouteAndMethodHasAnExactReviewedContract():void {
  $reviewed=\DigiForge\PortalAccess\RestRoutes::ENTRIES;$count=0;
  foreach(rest_get_server()->get_routes() as $pattern=>$handlers){if(!str_starts_with($pattern,'/digiforge/v1/'))continue;foreach($handlers as $h){if(!isset($h['callback'],$h['methods']))continue;foreach($h['methods'] as $method=>$yes){if(!$yes)continue;$row=[$pattern,$method,\DigiForge\PortalAccess\RestPolicy::callbackName($h['callback']),\DigiForge\PortalAccess\RestPolicy::callbackName($h['permission_callback'])];self::assertContains($row,$reviewed,$method.' '.$pattern);$count++;}}}
  self::assertGreaterThanOrEqual(189,$count);
 }
 public function testUnknownRegisteredCallbackIsDeniedBeforeExecution():void {
  $this->actor('Admin',['products']);$called=false;
  do_action('rest_api_init');register_rest_route('digiforge/v1','/future-sensitive',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>static function()use(&$called){$called=true;return ['unsafe'=>true];}],true);
  $response=rest_do_request(new WP_REST_Request('POST','/digiforge/v1/future-sensitive'));self::assertSame(403,$response->get_status());self::assertFalse($called);
 }
 public function testIndependentBoundaryCannotBeReusedByAnUnreviewedCallback():void {
  wp_set_current_user(0);self::assertWPError((new Guard())->rest(null,['callback'=>'__return_true'],new WP_REST_Request('POST','/digiforge/v1/etsy/webhook')));
 }
 public function testSignedAuthorityDoesNotTransferToAnotherActorOrShop():void {
  $owner=PortalAccessFixture::owner();$other=self::factory()->user->create(['role'=>'administrator']);
  self::assertWPError(\DigiForge\Security\ShopAccessRepository::authorityFor($other,'personalized_pod',['manage_digiforge'],time()+60));
  self::assertWPError(\DigiForge\Security\ShopAccessRepository::authorityFor($owner,'digital',['manage_digiforge'],time()+60));
 }

 public function testGlobalCollectionRequiresAndAcceptsAllFourCurrentShopGrants():void {
  $owner=PortalAccessFixture::enroll();$records=[];$scopes=[];
  foreach(\DigiForge\Security\ShopAccessPolicy::canonicalShops() as $shop){$records[]=['actor_id'=>$owner,'shop'=>$shop,'caps'=>\DigiForge\Core\Capabilities::ALL,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','synthetic1257'),'authority'=>'OWNER'];$scopes[$shop]=['products'];}
  SyntheticShopAuthority::write($records);
  foreach(['Admin','Operator'] as $role){PortalAccessFixture::login($owner);PortalAccessFixture::verifyEmail();Session::start();$id=self::factory()->user->create(['role'=>'administrator']);self::assertIsArray(Store::change($id,$role,$scopes,true,0,wp_generate_uuid4()));PortalAccessFixture::login($id);PortalAccessFixture::verifyEmail(get_userdata($id)->user_email);Session::start();self::assertNull((new Guard())->rest(null,[],new WP_REST_Request('GET','/digiforge/v1/products')));self::assertWPError((new Guard())->rest(null,[],new WP_REST_Request('GET','/digiforge/v1/orders/orders')));}
 }
 public function testStoredResearchTargetCannotBeReassignedByClaimOrMalformedId():void {
  $this->actor('Operator',['research']);$candidate=(new \DigiForge\Research\Repository())->createCandidate(['title'=>'Exact scope '.wp_generate_uuid4(),'shop_key'=>'personalized_pod'],wp_generate_uuid4());self::assertIsArray($candidate);
  global $wpdb;$table=\DigiForge\Database\Tables::research_candidates();self::assertSame(1,$wpdb->update($table,['score_inputs'=>wp_json_encode(['shop_key'=>'personalized_pod'])],['id'=>$candidate['id']]));
  $r=new WP_REST_Request('POST','/digiforge/v1/research/candidates/'.$candidate['id'].'/review');$r->set_param('id',$candidate['id']);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode(['decision'=>'REJECTED']));self::assertNull((new Guard())->rest(null,[],$r));
  $r->set_param('shop','digital');self::assertWPError((new Guard())->rest(null,[],$r));$r->set_param('shop','personalized_pod');$r->set_param('id',[$candidate['id']]);self::assertWPError((new Guard())->rest(null,[],$r));
  $r->set_param('id',$candidate['id']);self::assertSame(1,$wpdb->update($table,['score_inputs'=>wp_json_encode(['shop_key'=>'digital'])],['id'=>$candidate['id']]));self::assertWPError((new Guard())->rest(null,[],$r));
 }

 public function testCompoundListingAndFactoryRoutesRequireAllAffectedModules():void {
  $owner=PortalAccessFixture::enroll();$records=[];$scopes=[];
  foreach(\DigiForge\Security\ShopAccessPolicy::canonicalShops() as $shop){$records[]=['actor_id'=>$owner,'shop'=>$shop,'caps'=>\DigiForge\Core\Capabilities::ALL,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','synthetic1257'),'authority'=>'OWNER'];$scopes[$shop]=['integrations','research'];}SyntheticShopAuthority::write($records);
  foreach(['Admin','Operator'] as $role){PortalAccessFixture::login($owner);PortalAccessFixture::verifyEmail();Session::start();$id=self::factory()->user->create(['role'=>'administrator']);self::assertIsArray(Store::change($id,$role,$scopes,true,0,wp_generate_uuid4()));PortalAccessFixture::login($id);PortalAccessFixture::verifyEmail(get_userdata($id)->user_email);Session::start();foreach(['/etsy/controlled-draft','/etsy/controlled-listing-image','/etsy/controlled-digital-file','/research/candidates/1/promote','/research/candidates/1/retry-product-factory','/research/candidates/1/resume-product-factory','/research/candidates/1/review'] as $path){$r=new WP_REST_Request('POST','/digiforge/v1'.$path);$r->set_param('id',1);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode(['decision'=>'APPROVED']));self::assertWPError((new Guard())->rest(null,[],$r),$role.' '.$path);}}
 }

 public function testGlobalOwnerCanManageAllCanonicalShopsThroughNativeRest():void {
  PortalAccessFixture::enroll();
  $r=new WP_REST_Request('GET','/digiforge/v1/shop-access/grants');$r->set_query_params(['shop_key'=>'personalized_pod']);self::assertNull((new Guard())->rest(null,[],$r));$r->set_query_params(['shop_key'=>'digital']);self::assertNull((new Guard())->rest(null,[],$r));
  foreach(['/digiforge/v1/shop-access/grants','/digiforge/v1/shop-access/grants/'.str_repeat('a',64).'/revoke'] as $route){$r=new WP_REST_Request('POST',$route);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode(['shop'=>'unknown','caps'=>['manage_digiforge']]));self::assertWPError((new Guard())->rest(null,[],$r));}
 }

 public function testLegacySignedResearchGrantCannotEscalateIntoProductModule():void {
  $owner=PortalAccessFixture::enroll();$caps=['manage_digiforge','manage_digiforge_research'];$signed=[];
  foreach(\DigiForge\Security\ShopAccessPolicy::canonicalShops() as $shop)$signed[]=['actor_id'=>$owner,'shop'=>$shop,'caps'=>\DigiForge\Core\Capabilities::ALL,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','synthetic1257'),'authority'=>'OWNER'];SyntheticShopAuthority::write($signed);
  $id=self::factory()->user->create(['role'=>'administrator']);$repo=new \DigiForge\Security\ShopAccessRepository();foreach(\DigiForge\Security\ShopAccessPolicy::canonicalShops() as $shop)self::assertIsArray($repo->grant($id,$shop,'administrator',$caps,time()+600,wp_generate_uuid4(),$repo->read($shop)['revision']));
  PortalAccessFixture::login($id);self::assertNull(Store::read($id));
  self::assertNull((new Guard())->rest(null,[],new WP_REST_Request('GET','/digiforge/v1/research/candidates')));
  foreach(['/research/candidates/1/promote','/research/candidates/1/retry-product-factory','/research/candidates/1/resume-product-factory','/research/candidates/1/review'] as $path){$r=new WP_REST_Request('POST','/digiforge/v1'.$path);$r->set_param('id',1);self::assertWPError((new Guard())->rest(null,[],$r),$path);}
 }

 public function testNoncanonicalNumericIdsCannotEscapeCompoundOrStoredScope():void {
  $this->actor('Admin',['research']);foreach(['0','01','0001','999999999999999999999999999999'] as $id)foreach(['promote','review','retry-product-factory','resume-product-factory'] as $operation){$r=new WP_REST_Request('POST','/digiforge/v1/research/candidates/'.$id.'/'.$operation);$r->set_param('id',$id);self::assertNull(\DigiForge\PortalAccess\RestPolicy::contract($r));self::assertWPError((new Guard())->rest(null,[],$r));}
 }

 public function testFactorySchedulingCannotUseCandidateClaimAsWorkflowShopScope():void {
  $this->actor('Admin',['research','products','ai','production','listings']);
  foreach(['review','retry-product-factory','resume-product-factory'] as $operation){$r=new WP_REST_Request('POST','/digiforge/v1/research/candidates/1/'.$operation);$r->set_param('id',1);$r->set_param('shop','personalized_pod');$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode(['decision'=>'APPROVED']));self::assertWPError((new Guard())->rest(null,[],$r));}
 }

 public function testOwnerCannotGrantAModuleAbsentFromNativeCapabilities():void {
  PortalAccessFixture::enroll();get_userdata(1)->add_cap('manage_digiforge_products',false);
  $r=new WP_REST_Request('POST','/digiforge/v1/shop-access/grants');$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode(['shop'=>'personalized_pod','caps'=>['manage_digiforge_products']]));self::assertWPError((new Guard())->rest(null,[],$r));
 }

}
