<?php
declare(strict_types=1);

final class PrintifyPreflightTest extends WP_UnitTestCase
{
 public function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 public function testValidatedTemplateRouteIsRequiredAndDryRunNeverAuthorizesExecution():void{
  global $wpdb;$now=current_time('mysql',true);
  $packageId=$this->seedReadyPackage($now,321,'77:88');
  $blocked=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);
  self::assertFalse(is_wp_error($blocked));self::assertContains('PRINTIFY_TEMPLATE_NOT_VALIDATED',$blocked['blockers']);self::assertFalse($blocked['ready_for_external_execution']);
  $template=(new DigiForge\POD\ProductionTemplateRepository())->save(['template_id'=>'wp-preflight','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>321,'provider_id'=>77,'variant_ids'=>[88],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1200,'height_px'=>1600]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);
  self::assertFalse(is_wp_error($template));
  $ready=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);
  self::assertFalse(is_wp_error($ready));self::assertNotContains('PRINTIFY_TEMPLATE_NOT_VALIDATED',$ready['blockers']);self::assertNotContains('PRINTIFY_ROUTE_TEMPLATE_MISMATCH',$ready['blockers']);self::assertFalse($ready['ready_for_external_execution']);
  $dry=(new DigiForge\POD\PersonalizedPodDryRun())->certify($packageId);self::assertFalse(is_wp_error($dry));self::assertSame('BLOCKED',$dry['execution_intent']['intent_state']);self::assertFalse($dry['execution_intent']['network_execution_performed']);self::assertFalse($dry['execution_intent']['retry_permitted']);
 }
 public function testReadinessDriftInvalidatesApprovedPackage():void{
  global $wpdb;$now=current_time('mysql',true);$packageId=$this->seedReadyPackage($now,777,'22:33');
  $template=(new DigiForge\POD\ProductionTemplateRepository())->save(['template_id'=>'wp-stale','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>777,'provider_id'=>22,'variant_ids'=>[33],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1000,'height_px'=>1000]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);self::assertFalse(is_wp_error($template));
  $before=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($before));self::assertNotContains('ORDER_READINESS_STALE',$before['blockers']);
  $orderId=(int)$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.DigiForge\Database\Tables::pod_authorization_packages().' WHERE id=%d',$packageId));
  $wpdb->update(DigiForge\Database\Tables::orders(),['state'=>'RECEIVED','updated_at'=>current_time('mysql',true)],['id'=>$orderId]);
  $after=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($after));self::assertContains('ORDER_READINESS_STALE',$after['blockers']);self::assertContains('ORDER_NOT_READY',$after['blockers']);self::assertFalse($after['ready_for_dry_run']);self::assertFalse($after['ready_for_external_execution']);
 }
 public function testRouteVariantMismatchFailsClosed():void{
  global $wpdb;$now=current_time('mysql',true);$packageId=$this->seedReadyPackage($now,654,'55:66');
  $template=(new DigiForge\POD\ProductionTemplateRepository())->save(['template_id'=>'wp-mismatch','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>654,'provider_id'=>55,'variant_ids'=>[67],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1000,'height_px'=>1000]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);self::assertFalse(is_wp_error($template));
  $result=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($result));self::assertContains('PRINTIFY_ROUTE_TEMPLATE_MISMATCH',$result['blockers']);self::assertFalse($result['ready_for_dry_run']);
 }
 private function seedReadyPackage(string $now,int $blueprint,string $variant):int{
  global $wpdb;
  $tables=DigiForge\Database\Tables::class;
  $wpdb->insert($tables::orders(),['channel'=>'etsy','environment'=>'sandbox','external_order_reference'=>wp_generate_uuid4(),'shop_reference'=>'test','currency'=>'USD','personalization_required'=>0,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$orderId=(int)$wpdb->insert_id;
  $wpdb->insert($tables::pod_mappings(),['product_version_id'=>1,'production_plan_id'=>1,'provider'=>'printify','environment'=>'sandbox','provider_product_key'=>(string)$blueprint,'provider_variant_key'=>$variant,'mapping_version'=>'test-v1','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$mappingId=(int)$wpdb->insert_id;
  $wpdb->insert($tables::order_line_items(),['order_id'=>$orderId,'product_version_id'=>1,'provider_mapping_id'=>$mappingId,'quantity'=>1,'currency'=>'USD','environment'=>'sandbox','validation_status'=>'VALIDATED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
  $wpdb->insert($tables::pod_print_areas(),['provider_mapping_id'=>$mappingId,'asset_spec_id'=>1,'area_key'=>'front','placement'=>'front','width_value'=>1200,'height_value'=>1600,'unit'=>'px','dpi_target'=>300,'state'=>'APPROVED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
  $wpdb->insert($tables::businesses(),['business_key'=>'preflight-'.wp_rand(10000,99999),'name'=>'Preflight','state'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert($tables::stores(),['business_id'=>$business,'store_key'=>'preflight-'.wp_rand(10000,99999),'name'=>'Preflight Store','channel'=>'etsy','state'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert($tables::product_programs(),['business_id'=>$business,'program_key'=>'PERSONALIZED_POD','name'=>'Personalized POD','state'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$program=(int)$wpdb->insert_id;
  $wpdb->insert($tables::pod_business_mappings(),['provider_mapping_id'=>$mappingId,'business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$ownership=(int)$wpdb->insert_id;
  $readiness=(new DigiForge\Orders\Repository())->readiness($orderId);self::assertFalse(is_wp_error($readiness));self::assertTrue($readiness['ready']);$hash=hash('sha256',wp_json_encode($readiness));
  $wpdb->insert($tables::pod_authorization_packages(),['order_id'=>$orderId,'render_evidence_id'=>1,'provider_mapping_id'=>$mappingId,'ownership_mapping_id'=>$ownership,'readiness_hash'=>$hash,'package_hash'=>hash('sha256','package-'.$orderId),'state'=>'APPROVED_PACKAGE','approved_by'=>get_current_user_id(),'approved_at'=>$now,'external_execution_authorized'=>0,'external_execution_performed'=>0,'created_at'=>$now]);
  return (int)$wpdb->insert_id;
 }
}
