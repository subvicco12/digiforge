<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PortalAccessFixture.php';


/** Test-only synthetic Printify evidence. It never represents supplier certification. */
function digiforge_acceptance_final_template(array $input): array|WP_Error
{
    $blueprint=(int)($input['provider_blueprint_id']??0);$provider=(int)($input['provider_id']??0);$ids=array_map('intval',(array)($input['variant_ids']??[]));$areas=(array)($input['print_areas']??[]);$environment=(string)($input['environment']??'sandbox');foreach($areas as &$area)if(!isset($area['region']))$area['region']='US';unset($area);
    if($blueprint<1||$provider<1||$ids===[]||$areas===[])return new WP_Error('fixture_invalid','Synthetic Printify catalog fixture requires exact identity and areas.');
    $persistence=new DigiForge\POD\PrintifyCatalogPersistence();$rows=[];
    foreach($ids as $variantId){$placeholders=[];foreach($areas as $area)$placeholders[]=['position'=>(string)$area['position'],'decoration_method'=>(string)$area['decoration_method'],'width'=>(int)$area['width_px'],'height'=>(int)$area['height_px']];$variant=['id'=>$variantId,'title'=>'Synthetic approved test variant','placeholders'=>$placeholders];$encoded=wp_json_encode($variant,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$observed=gmdate('c');$variant['_digiforge_source_evidence']=['schema'=>'printify-variant-evidence-v1','endpoint_path'=>"/v1/catalog/blueprints/$blueprint/print_providers/$provider/variants.json",'response_sha256'=>hash('sha256',(string)$encoded),'observed_at'=>$observed,'variant_sha256'=>hash('sha256',(string)$encoded),'blueprint_id'=>$blueprint,'provider_id'=>$provider,'variant_id'=>$variantId,'environment'=>$environment];$saved=$persistence->persistVariant(['blueprint_id'=>$blueprint,'print_provider_id'=>$provider,'variant_id'=>$variantId,'environment'=>$environment,'attributes'=>$variant,'title'=>'Synthetic test blueprint','variant_label'=>'Synthetic test variant','base_cost'=>0,'currency'=>'USD','shipping_profile'=>[],'availability_state'=>'AVAILABLE','source_revision'=>'synthetic-fixture-v1','observed_at'=>$observed]);if(is_wp_error($saved))return $saved;$rows[]=$saved;}
    foreach(['supplier','provider_blueprint_id','provider_id','variant_ids','print_areas'] as $reserved)unset($input[$reserved]);$template=DigiForge\POD\ProductionTemplateContract::fromPrintifyVariants($rows,$areas,$input);$template['shop_key']=(string)($input['shop_key']??'');return $template;
}

final class FinalConvergenceScenarioTest extends WP_UnitTestCase {
 protected function setUp():void { parent::setUp(); DigiForge\Core\Activator::activate(); PortalAccessFixture::reset(); PortalAccessFixture::enroll(); }
 public function testPersonalizedPodReviewChainStopsBeforeExternalExecution():void {
  global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);
  $wpdb->insert($t::orders(),['channel'=>'etsy','environment'=>'sandbox','external_order_reference'=>'final-e2e-'.wp_generate_uuid4(),'shop_reference'=>'personalized_pod','currency'=>'USD','personalization_required'=>1,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$order=(int)$wpdb->insert_id;
  $wpdb->insert($t::pod_mappings(),['product_version_id'=>1,'production_plan_id'=>1,'provider'=>'printify','environment'=>'sandbox','provider_product_key'=>'101','provider_variant_key'=>'2:202','mapping_version'=>'final-v1','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$mapping=(int)$wpdb->insert_id;
  $wpdb->insert($t::order_line_items(),['order_id'=>$order,'product_version_id'=>1,'provider_mapping_id'=>$mapping,'quantity'=>1,'currency'=>'USD','environment'=>'sandbox','validation_status'=>'VALIDATED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$line=(int)$wpdb->insert_id;
  $payload=wp_json_encode(['name'=>'Asha']);$ph=hash('sha256',(string)$payload);$wpdb->insert($t::personalization_submissions(),['order_line_item_id'=>$line,'personalization_schema_id'=>1,'canonical_payload'=>$payload,'payload_hash'=>$ph,'review_status'=>'APPROVED','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now,'environment'=>'sandbox','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
  $wpdb->insert($t::businesses(),['business_key'=>'digicraftifygoods','display_name'=>'DigiCraftifyGoods','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert($t::stores(),['business_id'=>$business,'store_key'=>'digicraftifygoods-etsy','display_name'=>'DigiCraftifyGoods Etsy','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert($t::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);$program=(int)$wpdb->insert_id;
  $wpdb->insert($t::pod_business_mappings(),['provider_mapping_id'=>$mapping,'business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'product_version_id'=>1,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
  $policy=(new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>'personalized_pod','template_cap'=>['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-01-05 00:00:00']]);self::assertNotWPError($policy);
  $templateInput=digiforge_acceptance_final_template(['shop_key'=>'personalized_pod','template_id'=>'final-template','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>640,'height_px'=>480]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);self::assertNotWPError($templateInput);$template=(new DigiForge\POD\ProductionTemplateRepository())->save($templateInput);self::assertNotWPError($template);
  $wpdb->insert($t::personalization_schemas(),['product_version_id'=>1,'schema_key'=>'final-name','version_label'=>'1','field_definitions'=>'[{"key":"name","type":"text","max_length":30}]','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$schema=(int)$wpdb->insert_id;
  $wpdb->update($t::personalization_submissions(),['personalization_schema_id'=>$schema],['order_line_item_id'=>$line]);
  $renderer=new DigiForge\POD\DeterministicPersonalizationRenderer();
  $recipe=['engine'=>'NAME_MONOGRAM','background'=>'#ffffff','bitmap_font'=>['id'=>'offline-final-glyphs','version'=>1,'width'=>3,'height'=>5,'glyphs'=>['A'=>['010','101','111','101','101'],'s'=>['000','011','010','001','110'],'h'=>['100','100','110','101','101'],'a'=>['000','010','001','011','111']]],'slots'=>[['type'=>'text','field'=>'name','x'=>10,'y'=>100,'font_size'=>24,'color'=>'#000000','max_characters'=>30]]];
  self::assertNotWPError($renderer->approveRecipe('final-template',1,$recipe));
  $input=['order_id'=>$order,'provider_mapping_id'=>$mapping,'template_key'=>'final-template','template_version'=>'1','template_sha256'=>$template['fingerprint'],'personalization_evidence_hash'=>$ph,'render_mode'=>'DETERMINISTIC'];
  $expected=$renderer->expected($input);self::assertNotWPError($expected);
  $artifact=tempnam(sys_get_temp_dir(),'df-final-');try{file_put_contents($artifact,$expected['svg']);$render=(new DigiForge\POD\RenderEvidenceRepository())->createFromArtifacts($input,$artifact,$artifact);}finally{unlink($artifact);}self::assertFalse(is_wp_error($render));
  $render=(new DigiForge\POD\RenderEvidenceRepository())->approve((int)$render['id']);self::assertNotWPError($render);self::assertSame('APPROVED',$render['review_status']);self::assertSame('0',(string)$render['external_execution_performed']);
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
