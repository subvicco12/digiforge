<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Research\OpportunityDesignRepository;
/** Real WordPress options, capability and actor checks; no remote dependencies. */
final class OpportunityDesignWorkflowTest extends WP_UnitTestCase
{
 private function input():array {
  return ['shop_key'=>'personalized_pod','title'=>'Original gift', 'sources'=>[['url'=>'https://example.org/source','observed_at'=>'2026-11-03T12:00:00Z','excerpt'=>'Observed personalization, volume unverified']], 'briefs'=>array_map(static fn($i)=>['audience'=>'Gift buyer','visual_direction'=>'Direction '.$i,'rationale'=>'Source-led rationale'],[1,2,3]),'artwork'=>['svg'=>'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#fff"/></svg>','width_px'=>100,'height_px'=>100,'dpi'=>300,'selected_brief'=>1,'native_layers'=>['name'],'rights_note'=>'Original graphic','safe_area_reviewed'=>true,'font_license_reviewed'=>true,'mockup_reviewed'=>true]];
 }
 protected function setUp():void {parent::setUp();$id=self::factory()->user->create();$u=new WP_User($id);$u->add_cap('manage_digiforge_pod');$u->add_cap('manage_digiforge_research');wp_set_current_user($id);DigiForge\Core\Activator::activate();ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research']);}
 public function testImmutableApprovalAndNewVersion():void {
  $repo=new OpportunityDesignRepository();$input=$this->input();$v=$repo->save($input);self::assertIsArray($v);
  $a=$repo->decide('personalized_pod',$v['version_hash'],'APPROVE');self::assertIsArray($a);self::assertSame(get_current_user_id(),$a['approval']['approved_by']);
  self::assertWPError($repo->decide('personalized_pod',$v['version_hash'],'REJECT'));
  $input['title']='Changed original gift';$next=$repo->save($input);self::assertNotSame($v['version_hash'],$next['version_hash']);self::assertNull($next['approval']);
  self::assertSame($a,$repo->read('personalized_pod',$v['version_hash']));
 }
 public function testIsolationAndMissingEvidence():void {
  $repo=new OpportunityDesignRepository();$v=$repo->save($this->input());
  foreach(['digital','standard_pod','all','jewelry']as $shop){self::assertWPError($repo->read($shop,$v['version_hash']));self::assertWPError($repo->decide($shop,$v['version_hash'],'APPROVE'));}
  $bad=$this->input();$bad['sources']=[];self::assertWPError($repo->save($bad));self::assertWPError($repo->decide('personalized_pod',str_repeat('0',64),'APPROVE'));
  wp_set_current_user(0);self::assertWPError($repo->read('personalized_pod',$v['version_hash']));self::assertWPError($repo->save($this->input()));
 }
 public function testCannotSpoofActorOrSkipCapabilities():void {
  $repo=new OpportunityDesignRepository();$bad=$this->input();$bad['created_by']=999;self::assertWPError($repo->save($bad));
  $u=wp_get_current_user();$u->add_cap('manage_digiforge_research',false);self::assertWPError($repo->save($this->input()));
 }
 public function testPortalRoundTripRequiresConfirmationAndNeverCallsHttp():void {
  $calls=0;$deny=static function()use(&$calls){++$calls;return new WP_Error('test_no_http','No network permitted');};
  add_filter('pre_http_request',$deny);
  try {
   $panel=new \DigiForge\Portal\OpportunityDesignPanel();
   $v=$panel->process(['operation'=>'import','shop_key'=>'personalized_pod','evidence_json'=>wp_json_encode($this->input())]);self::assertIsArray($v);
   self::assertWPError($panel->process(['operation'=>'decide','shop_key'=>'personalized_pod','version_hash'=>$v['version_hash'],'decision'=>'APPROVE']));
   $approved=$panel->process(['operation'=>'decide','shop_key'=>'personalized_pod','version_hash'=>$v['version_hash'],'decision'=>'APPROVE','confirm'=>'1']);self::assertSame('HUMAN_APPROVED',$approved['approval']['state']);
   $_GET['df_design_version']=$v['version_hash'];ob_start();$panel->render('personalized_pod');$html=ob_get_clean();unset($_GET['df_design_version']);
   self::assertStringContainsString('HUMAN_APPROVED',$html);self::assertStringContainsString('Original gift',$html);self::assertStringNotContainsString('name="decision"',$html);
   self::assertSame(0,$calls);self::assertFalse($approved['record']['publishing_enabled']);self::assertTrue($approved['release_hold']);
  } finally {remove_filter('pre_http_request',$deny);}
 }
 public function testReadDeniesTamperedVersionAndDecision():void {
  $repo=new OpportunityDesignRepository();$v=$repo->save($this->input());self::assertIsArray($v);
  $name='digiforge_opportunity_personalized_pod_'.$v['version_hash'];
  update_option($name.'_decision','{"state":"HUMAN_APPROVED"}');self::assertWPError($repo->read('personalized_pod',$v['version_hash']));
  delete_option($name.'_decision');update_option($name,'{}');self::assertWPError($repo->read('personalized_pod',$v['version_hash']));
 }
 public function testUnicodeArtworkRoundTrip():void {
  $in=$this->input();$in['artwork']['svg']='<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><text>'.str_repeat('é',90000).'</text></svg>';
  $saved=(new OpportunityDesignRepository())->save($in);self::assertIsArray($saved);self::assertSame($in,$saved['record']['input']);
 }
 public function testPortalRetainsShortcodePageOnReturn():void {
  $id=self::factory()->post->create(['post_type'=>'page','post_content'=>'[digiforge_admin_portal]']);$this->go_to(get_permalink($id));
  $panel=new \DigiForge\Portal\OpportunityDesignPanel();ob_start();$panel->render('personalized_pod');$html=ob_get_clean();
  self::assertStringContainsString('name="return_url" value="'.esc_attr(get_permalink($id)).'"',$html);
 }
}
