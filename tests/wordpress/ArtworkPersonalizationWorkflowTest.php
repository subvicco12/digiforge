<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/fixtures/ArtworkPersonalizationFixture.php';
use DigiForge\Research\ArtworkPersonalizationRepository as Repo;
final class ArtworkPersonalizationWorkflowTest extends WP_UnitTestCase
{
 public function testNormalPortalRegistrationMountsResearchPostActions():void {
  (new DigiForge\Portal\Portal())->register();
  self::assertNotFalse(has_action('admin_post_digiforge_commercial_research'));
  self::assertNotFalse(has_action('admin_post_digiforge_opportunity_design'));
 }
 public function testOmittedOptionalDateRoundTripsWithoutPoisoningSavedProofs():void {
  $a=ArtworkPersonalizationFixture::approved();$r=new Repo();$fields=ArtworkPersonalizationFixture::fields();$fields[2]['required']=false;$s=$r->define('personalized_pod',$a['hash'],$fields);
  $p=$r->sample('personalized_pod',$s['hash'],['first_name'=>'Élodie','second_name'=>'José']);self::assertIsArray($p);self::assertIsArray($r->read('personalized_pod',$p['hash']));self::assertArrayNotHasKey('date',$p['record']['data']['result']['samples']);self::assertIsArray($r->recent('personalized_pod',$a['hash']));
  self::assertWPError($r->sample('personalized_pod',$s['hash'],['first_name'=>'Élodie','second_name'=>'José','date'=>'']));
 }
 public function testSavedDesignDiscoveryIsSourceBoundAndShopScoped():void {
  $a=ArtworkPersonalizationFixture::approved();$b=ArtworkPersonalizationFixture::approved('fl04_poster_8x10');$r=new DigiForge\Research\DesignFactoryRepository();$saved=$r->recent('personalized_pod',$a['record']['commercial_hash']);self::assertContains($a['hash'],array_column($saved,'hash'));self::assertNotContains($b['hash'],array_column($saved,'hash'));self::assertWPError($r->recent('digital',$a['record']['commercial_hash']));
 }
 public function testOfflineProofAndDecisionIssueNoHttpAndPreserveSafetySettings():void {
  DigiForge\Core\Settings::protectProduction();$before=[DigiForge\Core\Settings::get('stop_all'),DigiForge\Core\Settings::safety_locked(),DigiForge\Core\Settings::get('automation_armed')];$calls=[];$block=static function($pre,$args,$url)use(&$calls){$calls[]=$url;return new WP_Error('offline_test');};add_filter('pre_http_request',$block,PHP_INT_MAX,3);
  try{$a=ArtworkPersonalizationFixture::approved();$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields());$p=$r->sample('personalized_pod',$s['hash'],ArtworkPersonalizationFixture::values());$v=$r->decide('personalized_pod',$p['hash'],'APPROVE','SYNTHETIC local review only');self::assertIsArray($v);self::assertFalse($v['external_execution_authorized']);self::assertTrue($v['release_hold']);}finally{remove_filter('pre_http_request',$block,PHP_INT_MAX);}
  self::assertSame([],$calls);self::assertSame($before,[DigiForge\Core\Settings::get('stop_all'),DigiForge\Core\Settings::safety_locked(),DigiForge\Core\Settings::get('automation_armed')]);
 }
 private int $grantor;
 protected function setUp():void {parent::setUp();$u=self::factory()->user->create_and_get();foreach(['manage_digiforge_pod','manage_digiforge_research']as $c)$u->add_cap($c);wp_set_current_user($u->ID);DigiForge\Core\Activator::activate();ShopAccessFixture::reset();$this->grantor=ShopAccessFixture::authorizeActor($u->ID,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research']);}
 /** @dataProvider profiles */
 public function testApprovedArtSchemaProofReviewAndRevisionPersist(string $profile):void {
  $art=ArtworkPersonalizationFixture::approved($profile);self::assertIsArray($art);$r=new Repo();$s=$r->define('personalized_pod',$art['hash'],ArtworkPersonalizationFixture::fields());self::assertIsArray($s);$p=$r->sample('personalized_pod',$s['hash'],ArtworkPersonalizationFixture::values());self::assertIsArray($p);self::assertSame('MANUAL_REVIEW',$p['record']['data']['result']['state']);
  $review=$r->decide('personalized_pod',$p['hash'],'APPROVE','SYNTHETIC local sample reviewed');self::assertSame('APPROVE',$review['review']['decision']);self::assertFalse($review['external_execution_authorized']);self::assertWPError($r->decide('personalized_pod',$p['hash'],'REJECT','Cannot overwrite decision'));
  $next=$r->sample('personalized_pod',$s['hash'],['first_name'=>'José','second_name'=>'Élodie','date'=>'2024-02-29'],$p['hash']);self::assertNotSame($p['hash'],$next['hash']);self::assertNull($next['review']);self::assertSame('APPROVE',$r->read('personalized_pod',$p['hash'])['review']['decision']);self::assertCount(3,$r->history('personalized_pod',$next['hash']));self::assertSame($art['record']['asset']['svg'],(new DigiForge\Research\DesignFactoryRepository())->read('personalized_pod',$art['hash'])['record']['asset']['svg']);
 }
 public function testSignedGrantRevocationDeniesPreviouslyValidSchema():void {
  $a=ArtworkPersonalizationFixture::approved();$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields());$actor=get_current_user_id();$acl=new DigiForge\Security\ShopAccessRepository();$state=$acl->read('personalized_pod');$grant=array_key_first($state['grants']);wp_set_current_user($this->grantor);$revoked=$acl->revoke('personalized_pod',$grant,wp_generate_uuid4(),$state['revision']);self::assertIsArray($revoked);wp_set_current_user($actor);self::assertWPError($r->read('personalized_pod',$s['hash']));
 }
 public function testDecisionWriteFailureCannotApproveProof():void {
  $a=ArtworkPersonalizationFixture::approved();$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields());$p=$r->sample('personalized_pod',$s['hash'],ArtworkPersonalizationFixture::values());
  $fault=static fn($q)=>str_contains($q,'INSERT INTO')&&str_contains($q,'digiforge_shop2_personalization_decision_')?'SELECT 1 WHERE 0=1':$q;add_filter('query',$fault);try{self::assertWPError($r->decide('personalized_pod',$p['hash'],'APPROVE','SYNTHETIC failed pointer'));}finally{remove_filter('query',$fault);}self::assertNull($r->read('personalized_pod',$p['hash'])['review']);
 }
 public function testPanelPersistsAndDisplaysSampleThroughExistingAction():void {
  $a=ArtworkPersonalizationFixture::approved();$panel=new DigiForge\Portal\CommercialResearchPanel();$base=['shop_key'=>'personalized_pod','workflow_hash'=>$a['record']['commercial_hash'],'factory_hash'=>$a['hash']];$s=$panel->process($base+['operation'=>'personalization_schema','fields_json'=>json_encode(ArtworkPersonalizationFixture::fields())]);self::assertIsArray($s);$p=$panel->process($base+['operation'=>'personalization_sample','schema_hash'=>$s['personalization_hash'],'samples'=>ArtworkPersonalizationFixture::values()]);self::assertIsArray($p);$_GET=['df_personalization_version'=>$p['personalization_hash']];try{ob_start();(new DigiForge\Portal\ArtworkPersonalizationPanel())->render('personalized_pod',$base['workflow_hash'],$base['factory_hash']);$html=ob_get_clean();self::assertStringContainsString('MANUAL_REVIEW',$html);self::assertStringContainsString('DO NOT IMPORT',$html);self::assertStringContainsString('samples[first_name]',$html);self::assertStringContainsString('SYNTHETIC',$html);}finally{$_GET=[];}
 }
 public function testPanelCannotMixFactoryAndSchema():void {
  $a=ArtworkPersonalizationFixture::approved();$b=ArtworkPersonalizationFixture::approved('fl04_poster_8x10');$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields());$panel=new DigiForge\Portal\CommercialResearchPanel();self::assertWPError($panel->process(['shop_key'=>'personalized_pod','workflow_hash'=>$b['record']['commercial_hash'],'factory_hash'=>$b['hash'],'operation'=>'personalization_sample','schema_hash'=>$s['hash'],'samples'=>ArtworkPersonalizationFixture::values()]));
 }
 public function testExistingPostActionRejectsMissingNonce():void {
  $handler=static fn()=>static function(){throw new RuntimeException('expected_nonce_denial');};add_filter('wp_die_handler',$handler);$prior=$_REQUEST;$_REQUEST=[];try{$this->expectExceptionMessage('expected_nonce_denial');(new DigiForge\Portal\CommercialResearchPanel())->submit();}finally{$_REQUEST=$prior;remove_filter('wp_die_handler',$handler);}
 }
 public static function profiles():array {return [['fl03_mug_11oz'],['fl04_poster_8x10']];}
 public function testUnapprovedArtworkAndOtherShopDenied():void {$a=ArtworkPersonalizationFixture::approved('fl03_mug_11oz',false);$r=new Repo();self::assertWPError($r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields()));self::assertWPError($r->define('digital',$a['hash'],ArtworkPersonalizationFixture::fields()));}
 public function testInvalidDateMissingExtraAndOverCapAreRejected():void {
  $a=ArtworkPersonalizationFixture::approved();$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields());$valid=ArtworkPersonalizationFixture::values();
  foreach([array_replace($valid,['date'=>'2025-02-29']),array_replace($valid,['first_name'=>str_repeat('é',21)]),array_diff_key($valid,['date'=>true]),$valid+['other'=>'x']]as $v)self::assertWPError($r->sample('personalized_pod',$s['hash'],$v));
 }
 public function testUnsupportedGlyphHoldCannotBeApproved():void {$a=ArtworkPersonalizationFixture::approved();$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields());$p=$r->sample('personalized_pod',$s['hash'],array_replace(ArtworkPersonalizationFixture::values(),['first_name'=>'A😀']));self::assertSame('MANUAL_HOLD',$p['record']['data']['result']['state']);self::assertNull($p['record']['data']['result']['svg']);self::assertWPError($r->decide('personalized_pod',$p['hash'],'APPROVE','Cannot certify unsupported glyph'));}
 public function testRevokedCapabilityAndCorruptProofFailClosed():void {$a=ArtworkPersonalizationFixture::approved();$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields());$p=$r->sample('personalized_pod',$s['hash'],ArtworkPersonalizationFixture::values());$role=array_values(array_filter(wp_get_current_user()->roles,static fn($v)=>str_starts_with($v,'df_acl_fixture_')))[0];wp_get_current_user()->remove_role($role);self::assertWPError($r->read('personalized_pod',$p['hash']));wp_get_current_user()->add_role($role);update_option('digiforge_shop2_personalization_version_'.$p['hash'],'{}');self::assertWPError($r->read('personalized_pod',$p['hash']));}
 public function testSavedResearchDiscoveryIsBoundedAndShopScoped():void {$a=ArtworkPersonalizationFixture::approved();$r=new DigiForge\Research\CommercialResearchRepository();$recent=$r->recent('personalized_pod');self::assertIsArray($recent);self::assertNotEmpty($recent);self::assertWPError($r->recent('digital'));self::assertWPError($r->recent('personalized_pod',51));}
}
