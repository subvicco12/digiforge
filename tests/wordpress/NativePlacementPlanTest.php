<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once __DIR__.'/Support/ReviewedOwnershipFixture.php';
use DigiForge\Database\Tables;
use DigiForge\POD\NativePlacementPlan;
use DigiForge\POD\ReviewedOwnershipBinding;
final class NativePlacementPlanTest extends WP_UnitTestCase
{
 private function binding(string $status='VALIDATED',string $pipeline='PRINTIFY_NATIVE',bool $multi=false):array {
  DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();$actors=ReviewedLocalFixture::actors('personalized_pod');wp_set_current_user($actors['author']);$input=ReviewedOwnershipFixture::create($this);global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE id=%d',$input['production_template_id']),ARRAY_A);
  $areas=[['position'=>'front','decoration_method'=>'dtg','width_px'=>100,'height_px'=>100]];if($multi)$areas[]=['position'=>'back','decoration_method'=>'dtg','width_px'=>100,'height_px'=>100];
  $template=digiforge_test_printify_template(['template_id'=>$row['template_id'],'template_version'=>1,'provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202],'environment'=>'test','print_areas'=>$areas,'personalization_pipeline'=>$pipeline,'personalization_engine'=>'SYNTHETIC','template_status'=>$status]);$this->assertNotWPError($template);unset($template['shop_key']);foreach(['variant_ids','print_areas']as $field)$template[$field]=wp_json_encode($template[$field]);$this->assertNotFalse($wpdb->update(Tables::pod_production_templates(),$template,['id'=>$input['production_template_id']]));
  $repo=new ReviewedOwnershipBinding();$proposal=$repo->propose('personalized_pod',$input,time()-1,time()+600,'placement-proposal-'.wp_generate_uuid4(),0);$this->assertNotWPError($proposal);$key=hash('sha256',json_encode(['personalized_pod','test',$input['provider_mapping_id'],$input['production_template_id']],JSON_UNESCAPED_SLASHES));wp_set_current_user($actors['reviewer']);$approved=$repo->decide('personalized_pod',$key,$proposal['revision'],$proposal['proposal_hash'],'APPROVED','placement-review-'.wp_generate_uuid4());$this->assertNotWPError($approved);return [$key,$input];
 }
 public function test_validated_native_single_placement_qualifies_only_locally():void {
  [$key]=$this->binding();$plan=(new NativePlacementPlan())->prepare('personalized_pod',$key);$this->assertNotWPError($plan);$this->assertSame('SIMPLE',$plan['classification']);$this->assertTrue($plan['local_qualification']);$this->assertSame(100,$plan['placements'][0]['width_px']);$this->assertSame('front',$plan['placements'][0]['position']);$this->assertFalse($plan['provider_identity_verified']);$this->assertFalse($plan['supplier_certified']);$this->assertFalse($plan['external_execution_authorized']);$this->assertTrue($plan['requires_external_certification']);$this->assertSame($plan,(new NativePlacementPlan())->prepare('personalized_pod',$key));$this->assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function test_multi_placement_requires_review():void {[$key]=$this->binding('VALIDATED','PRINTIFY_NATIVE',true);$plan=(new NativePlacementPlan())->prepare('personalized_pod',$key);$this->assertNotWPError($plan);$this->assertSame('REVIEW_REQUIRED',$plan['classification']);$this->assertFalse($plan['local_qualification']);$this->assertCount(2,$plan['placements']);}
 public function test_draft_geometry_requires_review():void {[$key]=$this->binding('DRAFT');$plan=(new NativePlacementPlan())->prepare('personalized_pod',$key);$this->assertNotWPError($plan);$this->assertSame('REVIEW_REQUIRED',$plan['classification']);}
 public function test_non_native_pipeline_requires_review():void {[$key]=$this->binding('VALIDATED','DIGIFORGE_RENDER');$plan=(new NativePlacementPlan())->prepare('personalized_pod',$key);$this->assertNotWPError($plan);$this->assertSame('REVIEW_REQUIRED',$plan['classification']);}
 public function test_current_binding_is_required_after_template_changes():void {[$key,$input]=$this->binding();global $wpdb;$wpdb->update(Tables::pod_production_templates(),['template_status'=>'RETIRED'],['id'=>$input['production_template_id']]);$this->assertWPError((new NativePlacementPlan())->prepare('personalized_pod',$key));}
 public function test_missing_binding_cannot_qualify():void {$this->assertWPError((new NativePlacementPlan())->prepare('personalized_pod',str_repeat('a',64)));}
}
