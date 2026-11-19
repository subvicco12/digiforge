<?php
use DigiForge\Research\{ArtworkPersonalizationIntake as Intake,ArtworkPersonalizationRepository as Proofs};
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/fixtures/ArtworkPersonalizationFixture.php';
require_once __DIR__.'/fixtures/SupplierPersonalizationFixture.php';
final class ArtworkPersonalizationIntakeTest extends WP_UnitTestCase
{
 protected function setUp():void {parent::setUp();PortalAccessFixture::reset();PortalAccessFixture::enroll();$_GET=[];}
 protected function tearDown():void {$_GET=[];global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 private function proof(array $ctx,array $values=[],?string $schema=null,?string $parent=null,bool $approve=true):array {
  $r=new Proofs();if($schema===null){$s=$r->define('personalized_pod',$ctx['factory_hash'],ArtworkPersonalizationFixture::fields());self::assertIsArray($s);$schema=$s['hash'];}
  $p=$r->sample('personalized_pod',$schema,$values?:ArtworkPersonalizationFixture::values(),$parent);self::assertIsArray($p);
  if($approve){$p=$r->decide('personalized_pod',$p['hash'],'APPROVE','SYNTHETIC local sample only');self::assertIsArray($p);}return $p;
 }
 /** @dataProvider families */
 public function testResolvesOnlyServerSelectedReviewedProofWithoutDuplicatingProofStorage(string $family):void {
  self::assertTrue(class_exists(Intake::class),'Versioned proof intake is missing');$ctx=SupplierPersonalizationFixture::approved($family);$p=$this->proof($ctx);$r=new Intake();
  self::assertWPError($r->resolve('personalized_pod',$ctx['supplier_workflow_hash'],$ctx['factory_hash']));
  global $wpdb;$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_shop2_personalization_version_%'");
  $selected=$r->selectReviewed('personalized_pod',$ctx['supplier_workflow_hash'],$ctx['factory_hash'],$p['hash']);self::assertIsArray($selected,is_wp_error($selected)?$selected->get_error_message():'');
  self::assertSame('DF_ARTWORK_PERSONALIZATION_INTAKE_1',$selected['schema']);self::assertSame($p['hash'],$selected['proof']['hash']);self::assertSame($p['review']['hash'],$selected['review']['hash']);self::assertSame($ctx['supplier_workflow_hash'],$selected['supplier']['hash']);self::assertSame(get_current_user_id(),$selected['actor_scope']['actor_id']);self::assertSame($ctx['workflow_hash'],$selected['versions']['research_hash']);
  $_REQUEST=['proof_hash'=>str_repeat('f',64),'schema_hash'=>str_repeat('a',64),'review'=>['decision'=>'APPROVE']];try{$again=$r->resolve('personalized_pod',$ctx['supplier_workflow_hash'],$ctx['factory_hash'],$selected['intake_hash']);self::assertSame($selected,$again);}finally{$_REQUEST=[];}
  self::assertFalse($selected['external_execution_authorized']);self::assertFalse($selected['production_authorized']);self::assertTrue($selected['release_hold']);self::assertSame('MANUAL_REVIEW',$selected['proof']['record']['data']['result']['state']);
  self::assertSame($before,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_shop2_personalization_version_%'"));
 }
 public static function families():array {return [['mug'],['poster']];}
 public function testMissingUnreviewedAndForeignProofCannotReplaceSelection():void {
  $a=SupplierPersonalizationFixture::approved('mug');$b=SupplierPersonalizationFixture::approved('poster');$r=new Intake();$p=$this->proof($a);$good=$r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$p['hash']);
  self::assertWPError($r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],str_repeat('f',64)));
  $foreign=$this->proof($b);self::assertWPError($r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$foreign['hash']));self::assertWPError($r->resolve('personalized_pod',$b['supplier_workflow_hash'],$a['factory_hash']));self::assertWPError($r->resolve('digital',$a['supplier_workflow_hash'],$a['factory_hash']));
  $pending=$this->proof($a,['first_name'=>'Sam','second_name'=>'Alex','date'=>'2024-02-29'],$p['record']['data']['schema_hash'],$p['hash'],false);self::assertWPError($r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$pending['hash']));self::assertSame($good,$r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']));
 }
 public function testChangedSelectionMakesPriorIntakeStaleWithoutMutatingProofHistory():void {
  $a=SupplierPersonalizationFixture::approved('mug');$r=new Intake();$p=$this->proof($a);$old=$r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$p['hash']);
  $next=$this->proof($a,['first_name'=>'José','second_name'=>'Élodie','date'=>'2024-02-29'],$p['record']['data']['schema_hash'],$p['hash']);$new=$r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$next['hash']);self::assertIsArray($new);self::assertNotSame($old['intake_hash'],$new['intake_hash']);self::assertWPError($r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$old['intake_hash']));self::assertSame('APPROVE',(new Proofs())->read('personalized_pod',$p['hash'])['review']['decision']);
 }
 public function testCorruptSelectedProofAndMissingReviewDenyWithoutFallback():void {
  $a=SupplierPersonalizationFixture::approved('mug');$r=new Intake();$p=$this->proof($a);$r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$p['hash']);$key='digiforge_shop2_personalization_version_'.$p['hash'];$raw=get_option($key);
  try{update_option($key,'corrupt',false);self::assertWPError($r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']));}finally{update_option($key,$raw,false);}
  $pointer='digiforge_shop2_personalization_decision_'.$p['hash'];$decision=get_option($pointer);try{delete_option($pointer);self::assertWPError($r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']));}finally{add_option($pointer,$decision,'',false);}
 }
 public function testExistingSupplierReviewSelectsServerIntakeAndLegacyReviewCanBeExplicitlySelected():void {
  $a=SupplierPersonalizationFixture::approved('mug');$r=new Intake();$p=$this->proof($a,[],null,null,false);$panel=new DigiForge\Portal\Shop2ProductWorkflowPanel();
  $post=$a+['operation'=>'personalization_review','shop_key'=>'personalized_pod','proof_hash'=>$p['hash'],'decision'=>'APPROVE','note'=>'SYNTHETIC exact sample review','confirm'=>'1'];$result=$panel->process($post);self::assertIsArray($result,is_wp_error($result)?$result->get_error_message():'');self::assertSame($p['hash'],$r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'])['proof']['hash']);
  $next=$this->proof($a,['first_name'=>'Sam','second_name'=>'Alex','date'=>'2024-02-29'],$p['record']['data']['schema_hash'],$p['hash']);
  $_GET=['df_supplier_workflow'=>$a['supplier_workflow_hash'],'df_personalization_version'=>$next['hash']];ob_start();(new DigiForge\Portal\ArtworkPersonalizationPanel())->render('personalized_pod',$a['workflow_hash'],$a['factory_hash']);$html=ob_get_clean();self::assertStringContainsString('Select reviewed sample',$html);
  $again=$panel->process(array_replace($post,['proof_hash'=>$next['hash'],'select_reviewed'=>'1']));self::assertIsArray($again);self::assertSame($next['hash'],$r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'])['proof']['hash']);self::assertSame($next['review'],(new Proofs())->read('personalized_pod',$next['hash'])['review']);
 }
 public function testCorruptSelectionHeadAndLostCapabilitiesDenyRatherThanBorrowAnOlderProof():void {
  $a=SupplierPersonalizationFixture::approved('mug');$r=new Intake();$p=$this->proof($a);$good=$r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$p['hash']);$key='digiforge_shop2_proof_intake_head_'.hash('sha256','personalized_pod:'.$a['supplier_workflow_hash'].':'.$a['factory_hash']);$old=get_option($key);
  try{update_option($key,str_repeat('f',64),false);self::assertWPError($r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']));}finally{update_option($key,$old,false);}
  self::assertSame($good,$r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']));$u=get_userdata(get_current_user_id());$u->set_role('subscriber');$u->remove_cap('manage_digiforge_products');wp_set_current_user(0);wp_set_current_user($u->ID);self::assertWPError($r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']));
 }
 public function testRevokedSessionAndArtworkApprovalDenyCurrentIntake():void {
  $a=SupplierPersonalizationFixture::approved('mug');$r=new Intake();$p=$this->proof($a);$r->selectReviewed('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash'],$p['hash']);$factory=(new DigiForge\Research\DesignFactoryRepository())->read('personalized_pod',$a['factory_hash']);$key='digiforge_opportunity_personalized_pod_'.$factory['artwork']['version_hash'].'_decision';$approval=get_option($key);
  try{delete_option($key);self::assertWPError($r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']));}finally{add_option($key,$approval,'',false);}
  WP_Session_Tokens::get_instance(get_current_user_id())->destroy(wp_get_session_token());self::assertWPError($r->resolve('personalized_pod',$a['supplier_workflow_hash'],$a['factory_hash']));
 }
}
