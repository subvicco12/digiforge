<?php
declare(strict_types=1);
require_once __DIR__.'/PortalActionAcceptanceFixture.php';
use DigiForge\Database\Tables;
final class PortalStageBudgetAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 private function stored():array {global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT policy FROM '.Tables::shop_ai_policies().' WHERE shop_key=%s AND environment=%s','digital','production'),ARRAY_A);return json_decode((string)$row['policy'],true);}
 public function testNativeEditorPreservesExactZeroAndDecimalAmountsAndAbsence():void {
  PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>'digital','currency'=>'USD','stage_develop_limit'=>'2','stage_develop_monthly_budget'=>'0.00','stage_listing_prepare_monthly_budget'=>'12.340000','stage_render_monthly_budget'=>'']);$policy=$this->stored();self::assertSame('0.00',$policy['stages']['develop']['monthly_budget']);self::assertSame('12.340000',$policy['stages']['listing_prepare']['monthly_budget']);self::assertNull($policy['stages']['render']['monthly_budget']);self::assertArrayNotHasKey('monthly_budget',$policy['stages']['qa']);
  $get=$_GET;try{$_GET=['df_view'=>'ai_budget','df_shop'=>'digital'];$html=(new DigiForge\Portal\Portal())->render();self::assertStringContainsString('name="stage_develop_monthly_budget" value="0.00"',$html);self::assertStringContainsString('name="stage_listing_prepare_monthly_budget" value="12.340000"',$html);self::assertStringContainsString('stage_develop_task_binding',$html);}finally{$_GET=$get;}
  PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>'digital','currency'=>'USD','stage_develop_limit'=>'3']);self::assertSame('0.00',$this->stored()['stages']['develop']['monthly_budget']);self::assertSame('12.340000',$this->stored()['stages']['listing_prepare']['monthly_budget']);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testInvalidDecimalOrBindingCannotReplaceReviewedPolicy():void {
  PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>'digital','currency'=>'USD','stage_develop_monthly_budget'=>'1.200000']);$before=$this->stored();foreach(['1e2','-1','1.1234567','999999999',[]] as $invalid){PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>'digital','currency'=>'USD','stage_develop_monthly_budget'=>$invalid],true);self::assertSame($before,$this->stored());}
  foreach(['[]','false','{"task_id":'] as $invalid){PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>'digital','currency'=>'USD','stage_develop_task_binding'=>$invalid],true);self::assertSame($before,$this->stored());}
 }
 public function testReviewedRegisteredTaskBindingRoundTripsThroughNativeEditorAndRouting():void {
  $registry=new DigiForge\AI\Repository();$capability='portal-binding-'.wp_rand();
  $task=$registry->createTask(['task_key'=>'portal-task-'.wp_rand(),'name'=>'Offline portal binding fixture','environment'=>'production','required_capabilities'=>[$capability],'min_quality_tier'=>2]);self::assertNotWPError($task);
  $model=$registry->createModel(['model_key'=>'portal-model-'.wp_rand(),'provider'=>'openai','environment'=>'production','capabilities'=>[$capability],'quality_tier'=>2,'enabled'=>true]);self::assertNotWPError($model);
  $binding=['task_id'=>(int)$task['id'],'provider_models'=>[(int)$model['id']=>'reviewed-fixture-provider']];
  PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>'digital','currency'=>'USD','stage_qa_monthly_budget'=>'0.00','stage_qa_task_binding'=>wp_slash((string)wp_json_encode($binding))]);
  self::assertSame($binding,$this->stored()['stages']['qa']['task_binding']);$route=DigiForge\AI\StageTaskBinding::load($this->stored()['stages']['qa']['task_binding']);self::assertNotWPError($route);self::assertSame((int)$model['id'],$route['model_id']);self::assertSame('reviewed-fixture-provider',$route['provider_model']);
  $get=$_GET;try{$_GET=['df_view'=>'ai_budget','df_shop'=>'digital'];$html=(new DigiForge\Portal\Portal())->render();self::assertStringContainsString(esc_textarea((string)wp_json_encode($binding)),$html);}finally{$_GET=$get;}
  PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>'digital','currency'=>'USD','stage_qa_limit'=>'1']);self::assertSame($binding,$this->stored()['stages']['qa']['task_binding']);
  global $wpdb;self::assertSame(1,$wpdb->update(Tables::ai_models(),['enabled'=>0],['id'=>$model['id']]));self::assertWPError(DigiForge\AI\StageTaskBinding::load($binding));self::assertSame($binding,$this->stored()['stages']['qa']['task_binding']);
  self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
}
