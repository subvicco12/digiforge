<?php
use DigiForge\Portal\Shop2ProductWorkflowPanel;
use DigiForge\Research\ArtworkPersonalizationRepository;
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/fixtures/ArtworkPersonalizationFixture.php';
require_once __DIR__.'/fixtures/SupplierPersonalizationFixture.php';
final class SupplierPersonalizationWorkflowTest extends WP_UnitTestCase
{
 protected function setUp():void {parent::setUp();PortalAccessFixture::reset();$_GET=[];PortalAccessFixture::enroll();}
 protected function tearDown():void {$_GET=[];global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 /** @dataProvider families */
 public function testSupplierMountedSchemaSampleAndImmutableReview(string $family):void {
  DigiForge\Core\Settings::protectProduction();$calls=0;$deny=static function()use(&$calls){++$calls;throw new RuntimeException('Unexpected HTTP');};add_filter('pre_http_request',$deny);
  try{
  $ctx=SupplierPersonalizationFixture::approved($family);$panel=new Shop2ProductWorkflowPanel();
  $schema=$panel->process($ctx+['operation'=>'personalization_schema','fields_json'=>wp_json_encode(ArtworkPersonalizationFixture::fields()),'shop_key'=>'personalized_pod']);
  self::assertIsArray($schema,is_wp_error($schema)?$schema->get_error_message():'');self::assertSame($ctx['supplier_workflow_hash'],$schema['supplier_workflow_hash']);
  $proof=$panel->process($ctx+['operation'=>'personalization_sample','schema_hash'=>$schema['personalization_hash'],'samples'=>ArtworkPersonalizationFixture::values(),'shop_key'=>'personalized_pod']);self::assertIsArray($proof);
  $review=$panel->process($ctx+['operation'=>'personalization_review','proof_hash'=>$proof['personalization_hash'],'decision'=>'APPROVE','note'=>'SYNTHETIC local proof review','confirm'=>'1','shop_key'=>'personalized_pod']);self::assertIsArray($review);self::assertSame($proof['personalization_hash'],$review['personalization_hash']);
  $saved=(new ArtworkPersonalizationRepository())->read('personalized_pod',$proof['personalization_hash']);self::assertSame('APPROVE',$saved['review']['decision']);self::assertFalse($saved['external_execution_authorized']);self::assertTrue($saved['release_hold']);
  self::assertWPError($panel->process($ctx+['operation'=>'personalization_review','proof_hash'=>$proof['personalization_hash'],'decision'=>'REJECT','note'=>'Cannot overwrite','confirm'=>'1','shop_key'=>'personalized_pod']));
  self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::get('stop_all'));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed'));
  }finally{remove_filter('pre_http_request',$deny);}
 }
 public function testSupplierPostRequiresItsOwnValidNonceBeforeDispatch():void {
  $prior=$_REQUEST;$method=$_SERVER['REQUEST_METHOD']??null;$_REQUEST=['_df_workflow_nonce'=>'invalid'];$_SERVER['REQUEST_METHOD']='POST';
  $handler=static fn()=>static function(){throw new RuntimeException('expected_supplier_nonce_denial');};add_filter('wp_die_handler',$handler);
  try{$this->expectExceptionMessage('expected_supplier_nonce_denial');(new Shop2ProductWorkflowPanel())->submit();}
  finally{$_REQUEST=$prior;if($method===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$method;remove_filter('wp_die_handler',$handler);}
 }
 public static function families():array {return [['mug'],['poster']];}
 public function testPersonalizationCannotBorrowForeignSupplierResearchOrFactory():void {
  $a=SupplierPersonalizationFixture::approved('mug');$b=SupplierPersonalizationFixture::approved('poster');$panel=new Shop2ProductWorkflowPanel();$post=$a+['operation'=>'personalization_schema','fields_json'=>wp_json_encode(ArtworkPersonalizationFixture::fields()),'shop_key'=>'personalized_pod'];
  self::assertWPError($panel->process(array_replace($post,['supplier_workflow_hash'=>$b['supplier_workflow_hash']])));
  self::assertWPError($panel->process(array_replace($post,['factory_hash'=>$b['factory_hash']])));
  self::assertWPError($panel->process(array_replace($post,['shop_key'=>'digital'])));
 }
}
