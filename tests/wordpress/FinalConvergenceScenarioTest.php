<?php
declare(strict_types=1);
final class FinalConvergenceScenarioTest extends WP_UnitTestCase {
 protected function setUp():void { parent::setUp(); DigiForge\Core\Activator::activate(); wp_set_current_user(self::factory()->user->create(['role'=>'administrator'])); }
 public function testPersonalizedPodReviewChainStopsBeforeExternalExecution():void {
  global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);
  $wpdb->insert($t::orders(),['channel'=>'etsy','environment'=>'sandbox','external_order_reference'=>'final-e2e-'.wp_generate_uuid4(),'shop_reference'=>'digicraftifygoods','currency'=>'USD','personalization_required'=>1,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$order=(int)$wpdb->insert_id;
  $wpdb->insert($t::pod_mappings(),['product_version_id'=>1,'production_plan_id'=>1,'provider'=>'printify','environment'=>'sandbox','provider_product_key'=>'101','provider_variant_key'=>'202','mapping_version'=>'final-v1','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$mapping=(int)$wpdb->insert_id;
  $wpdb->insert($t::order_line_items(),['order_id'=>$order,'product_version_id'=>1,'provider_mapping_id'=>$mapping,'quantity'=>1,'currency'=>'USD','environment'=>'sandbox','validation_status'=>'VALIDATED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$line=(int)$wpdb->insert_id;
  $payload=wp_json_encode(['name'=>'Asha']);$ph=hash('sha256',(string)$payload);$wpdb->insert($t::personalization_submissions(),['order_line_item_id'=>$line,'personalization_schema_id'=>1,'canonical_payload'=>$payload,'payload_hash'=>$ph,'review_status'=>'APPROVED','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now,'environment'=>'sandbox','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
  $wpdb->insert($t::businesses(),['business_key'=>'digicraftifygoods','display_name'=>'DigiCraftifyGoods','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert($t::stores(),['business_id'=>$business,'store_key'=>'digicraftifygoods-etsy','display_name'=>'DigiCraftifyGoods Etsy','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert($t::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);$program=(int)$wpdb->insert_id;
  $wpdb->insert($t::pod_business_mappings(),['provider_mapping_id'=>$mapping,'business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'product_version_id'=>1,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
  $render=(new DigiForge\POD\RenderEvidenceRepository())->create(['order_id'=>$order,'provider_mapping_id'=>$mapping,'template_key'=>'final-template','template_version'=>'1','template_sha256'=>hash('sha256','template'),'personalization_evidence_hash'=>$ph,'render_mode'=>'DETERMINISTIC','output_sha256'=>hash('sha256','preview'),'buyer_preview_sha256'=>hash('sha256','preview')]);self::assertFalse(is_wp_error($render));
  $render=(new DigiForge\POD\RenderEvidenceRepository())->approve((int)$render['id']);self::assertSame('APPROVED',$render['review_status']);self::assertSame('0',(string)$render['external_execution_performed']);
  $package=(new DigiForge\POD\ProductionAuthorizationRepository())->create($order,(int)$render['id']);self::assertFalse(is_wp_error($package));self::assertSame('REVIEW_REQUIRED',$package['state']);self::assertSame('0',(string)$package['external_execution_authorized']);
  $package=(new DigiForge\POD\ProductionAuthorizationRepository())->approveForReview((int)$package['id']);self::assertSame('APPROVED_PACKAGE',$package['state']);self::assertSame('0',(string)$package['external_execution_authorized']);self::assertSame('0',(string)$package['external_execution_performed']);
 }
 public function testScopedCapabilityRequiresGlobalShopAndWorkflow():void {
  DigiForge\Core\Settings::protectProduction();
  $workflowOff=DigiForge\Portal\ScopedCapabilityPolicy::evaluate('research',['research'=>true],['research'=>false]);self::assertFalse($workflowOff['effective_enabled']);self::assertFalse($workflowOff['external_execution_authorized']);
  $shopOff=DigiForge\Portal\ScopedCapabilityPolicy::evaluate('research',['research'=>false],['research'=>true]);self::assertFalse($shopOff['effective_enabled']);
  $protected=DigiForge\Portal\ScopedCapabilityPolicy::evaluate('research',['research'=>true],['research'=>true]);self::assertFalse($protected['global_enabled']);self::assertFalse($protected['effective_enabled']);self::assertTrue($protected['global_disable_wins']);self::assertTrue($protected['shop_disable_wins']);self::assertTrue($protected['narrower_scope_cannot_override_parent']);
 }
}
