<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/fixtures/ArtworkPersonalizationFixture.php';
require_once __DIR__.'/fixtures/SupplierPersonalizationFixture.php';
use DigiForge\Research\ArtworkPersonalizationRepository as Repo;
use DigiForge\POD\OfflineDateDisplayProof as Policy;
final class VersionedDatePolicyWorkflowTest extends WP_UnitTestCase
{
 protected function setUp():void {parent::setUp();$u=self::factory()->user->create_and_get();foreach(['manage_digiforge_pod','manage_digiforge_research']as $c)$u->add_cap($c);wp_set_current_user($u->ID);DigiForge\Core\Activator::activate();ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($u->ID,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research']);}
 protected function tearDown():void {$_GET=[];global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 private function fields(string $profile):array{$f=ArtworkPersonalizationFixture::fields();$f[0]['max_length']=$f[1]['max_length']=$profile==='fl03_mug_11oz'?13:16;return $f;}
 /** @dataProvider profiles */
 public function testPolicyPersistsAndReplaysWithoutChangingLegacyProofOrReview(string $profile):void {
  $a=ArtworkPersonalizationFixture::approved($profile);$r=new Repo();$fields=$this->fields($profile);$legacy=$r->define('personalized_pod',$a['hash'],$fields);$p=$r->sample('personalized_pod',$legacy['hash'],ArtworkPersonalizationFixture::values());$r->decide('personalized_pod',$p['hash'],'APPROVE','SYNTHETIC old local review');
  $s=$r->define('personalized_pod',$a['hash'],$fields,$legacy['hash'],Policy::POLICY);self::assertIsArray($s);self::assertSame(Policy::POLICY,$s['record']['data']['date_display_policy']);
  $v=$r->sample('personalized_pod',$s['hash'],ArtworkPersonalizationFixture::values());self::assertIsArray($v);$result=$v['record']['data']['result'];self::assertSame('2024-02-29',$result['samples']['date']);self::assertSame('February 29, 2024',$result['date_display']['samples']['date']);self::assertStringContainsString('February 29, 2024',$result['svg']);self::assertSame('MANUAL_REVIEW',$result['state']);self::assertNull($v['review']);self::assertSame($v,$r->read('personalized_pod',$v['hash']));self::assertNotSame($p['hash'],$v['hash']);self::assertSame('APPROVE',$r->read('personalized_pod',$p['hash'])['review']['decision']);self::assertWPError($r->sample('personalized_pod',$s['hash'],ArtworkPersonalizationFixture::values(),$p['hash']));self::assertWPError($r->read('digital',$v['hash']));
 }
 public static function profiles():array{return [['fl03_mug_11oz'],['fl04_poster_8x10']];}
 public function testUnknownPolicyOrConflictingCapsFailClosed():void {$a=ArtworkPersonalizationFixture::approved();$r=new Repo();self::assertWPError($r->define('personalized_pod',$a['hash'],$this->fields('fl03_mug_11oz'),null,'unknown'));self::assertWPError($r->define('personalized_pod',$a['hash'],ArtworkPersonalizationFixture::fields(),null,Policy::POLICY));}
 public function testPortalOptInAndCanonicalReadbackNoExternalRequests():void {
  DigiForge\Core\Settings::protectProduction();$calls=[];$block=static function($pre,$args,$url)use(&$calls){$calls[]=$url;return new WP_Error('offline');};add_filter('pre_http_request',$block,PHP_INT_MAX,3);
  try{$a=ArtworkPersonalizationFixture::approved();$panel=new DigiForge\Portal\ArtworkPersonalizationPanel();$base=['shop_key'=>'personalized_pod','workflow_hash'=>$a['record']['commercial_hash'],'factory_hash'=>$a['hash']];$s=$panel->process($base+['operation'=>'personalization_schema','fields_json'=>json_encode($this->fields('fl03_mug_11oz')),'date_display_policy'=>Policy::POLICY]);self::assertIsArray($s);$p=$panel->process($base+['operation'=>'personalization_sample','schema_hash'=>$s['personalization_hash'],'samples'=>ArtworkPersonalizationFixture::values()]);self::assertIsArray($p);$_GET=['df_personalization_version'=>$p['personalization_hash']];ob_start();$panel->render('personalized_pod',$base['workflow_hash'],$base['factory_hash']);$html=ob_get_clean();self::assertStringContainsString('February 29, 2024',$html);self::assertStringContainsString('value="2024-02-29"',$html);self::assertStringContainsString('DO NOT IMPORT',$html);self::assertStringContainsString('digiforge-en-month-day-year-v1" selected',$html);self::assertSame([],$calls);}finally{$_GET=[];remove_filter('pre_http_request',$block,PHP_INT_MAX);}
 }
 public function testTamperedDisplayAndRevokedActorCannotReadProof():void {
  $a=ArtworkPersonalizationFixture::approved();$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],$this->fields('fl03_mug_11oz'),null,Policy::POLICY);$p=$r->sample('personalized_pod',$s['hash'],ArtworkPersonalizationFixture::values());
  $record=$p['record'];$record['data']['result']['date_display']['samples']['date']='January 1, 2000';$raw=DigiForge\Research\CommercialResearchContract::json($record);$hash=hash('sha256',$raw);add_option('digiforge_shop2_personalization_version_'.$hash,$raw,'',false);self::assertNotSame($p['hash'],$hash);self::assertSame('January 1, 2000',$record['data']['result']['date_display']['samples']['date']);
  self::assertWPError($r->read('personalized_pod',$hash),'Tampered read must deny');
  self::assertWPError($r->decide('personalized_pod',$hash,'APPROVE','Forged display cannot be approved'),'Tampered approval must deny');
  $u=wp_get_current_user();$u->add_cap('manage_digiforge_research',false);self::assertWPError($r->read('personalized_pod',$p['hash']));self::assertWPError($r->decide('personalized_pod',$p['hash'],'APPROVE','Revoked capability cannot approve'));
 }
 public function testPolicyRejectsInvalidInputAndPreservesUnsupportedGlyphHold():void {
  $a=ArtworkPersonalizationFixture::approved();$r=new Repo();$s=$r->define('personalized_pod',$a['hash'],$this->fields('fl03_mug_11oz'),null,Policy::POLICY);
  self::assertWPError($r->sample('personalized_pod',$s['hash'],['first_name'=>'Maya','second_name'=>'Alex','date'=>'2023-02-29']));
  $p=$r->sample('personalized_pod',$s['hash'],['first_name'=>'🐈','second_name'=>'Alex','date'=>'2024-02-29']);self::assertSame('MANUAL_HOLD',$p['record']['data']['result']['state']);self::assertNull($p['record']['data']['result']['svg']);self::assertWPError($r->decide('personalized_pod',$p['hash'],'APPROVE','HOLD cannot be approved'));
 }

 /** @dataProvider profiles */
 public function testSupplierMountedPolicyProofResolvesThroughExistingIntake(string $profile):void {
  PortalAccessFixture::reset();PortalAccessFixture::enroll();
  $a=SupplierPersonalizationFixture::approved($profile==='fl03_mug_11oz'?'mug':'poster');$r=new Repo();$panel=new DigiForge\Portal\Shop2ProductWorkflowPanel();
  $post=$a+['shop_key'=>'personalized_pod'];$s=$panel->process($post+['operation'=>'personalization_schema','fields_json'=>json_encode($this->fields($profile)),'date_display_policy'=>Policy::POLICY]);self::assertIsArray($s);
  $p=$panel->process($post+['operation'=>'personalization_sample','schema_hash'=>$s['personalization_hash'],'samples'=>ArtworkPersonalizationFixture::values()]);self::assertIsArray($p);
  $review=$panel->process($post+['operation'=>'personalization_review','proof_hash'=>$p['personalization_hash'],'decision'=>'APPROVE','note'=>'SYNTHETIC policy proof only','confirm'=>'1']);self::assertIsArray($review);
  $v=$r->read('personalized_pod',$p['personalization_hash']);self::assertSame('2024-02-29',$v['record']['data']['result']['samples']['date']);self::assertSame('February 29, 2024',$v['record']['data']['result']['date_display']['samples']['date']);
  $intake=(new DigiForge\Research\ArtworkPersonalizationIntake())->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']);self::assertIsArray($intake);self::assertSame($v['hash'],$intake['proof']['hash']);
 }

}
