<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
require_once __DIR__.'/PrintifyTemplateFixture.php';
/** Explicitly synthetic stored records; no provider execution or certification. */
final class ReviewedOwnershipFixture
{
 public static function create(WP_UnitTestCase $test):array
 {
  global $wpdb;$now=gmdate('Y-m-d H:i:s');$suffix=wp_generate_uuid4();
  $insert=static function(string $table,array $data)use($test,$wpdb):int{$test->assertSame(1,$wpdb->insert($table,$data));return (int)$wpdb->insert_id;};
  $business=$insert(Tables::businesses(),['business_key'=>'synthetic-'.$suffix,'status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);
  $store=$insert(Tables::stores(),['business_id'=>$business,'store_key'=>'synthetic-'.$suffix,'status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);
  $program=$insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);
  $factory=new DigiForge\ProductFactory\Repository();$opp=$factory->create('opportunity',['title'=>'Synthetic ownership']);$test->assertNotWPError($opp);$family=$factory->create('product_family',['opportunity_id'=>$opp['id'],'name'=>'Synthetic']);$test->assertNotWPError($family);$product=$factory->create('product',['product_family_id'=>$family['id'],'name'=>'Synthetic']);$test->assertNotWPError($product);$version=$factory->create('product_version',['product_id'=>$product['id'],'version_label'=>'v1']);$test->assertNotWPError($version);
  $insert(Tables::research_candidates(),['title'=>'Synthetic ownership','canonical_title'=>'synthetic-'.$suffix,'fingerprint'=>hash('sha256',$suffix),'score_version'=>'synthetic','score_inputs'=>wp_json_encode(['shop_key'=>'personalized_pod']),'opportunity_id'=>$opp['id'],'created_at'=>$now,'updated_at'=>$now]);
  $plan=$insert(Tables::production_plans(),['product_version_id'=>$version['id'],'plan_key'=>'synthetic','version_label'=>'v1','channel'=>'etsy','production_type'=>'POD','created_at'=>$now,'updated_at'=>$now]);
  $mapping=$insert(Tables::pod_mappings(),['product_version_id'=>$version['id'],'production_plan_id'=>$plan,'provider'=>'printify','environment'=>'test','provider_product_key'=>'101','provider_variant_key'=>'2:202','mapping_version'=>'v1','created_at'=>$now,'updated_at'=>$now]);
  $insert(Tables::pod_business_mappings(),['business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'product_version_id'=>$version['id'],'provider_mapping_id'=>$mapping,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
  $template=digiforge_test_printify_template(['template_id'=>'synthetic-'.$suffix,'template_version'=>1,'provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202],'environment'=>'test','print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>100,'height_px'=>100]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'SYNTHETIC','template_status'=>'DRAFT']);$test->assertNotWPError($template);unset($template['shop_key']);foreach(['variant_ids','print_areas']as $field)$template[$field]=wp_json_encode($template[$field]);$templateId=$insert(Tables::pod_production_templates(),$template+['created_at'=>$now,'updated_at'=>$now]);
  $integration=(new DigiForge\Integrations\Repository())->create(['provider'=>'printify','environment'=>'test','connection_key'=>'synthetic-'.$suffix,'status'=>'CONFIGURED','config'=>['account_reference'=>'synthetic-account-'.$suffix,'shop_id'=>(string)(700000000+$business),'shop_key'=>'personalized_pod','catalog_read_scope'=>['business_id'=>(string)$business,'store_id'=>(string)$store,'product_program'=>'PERSONALIZED_POD']]]);$test->assertNotWPError($integration);
  return ['integration_id'=>$integration['id'],'provider_mapping_id'=>$mapping,'production_template_id'=>$templateId,'source_sha256'=>hash('sha256','synthetic-local-source-'.$suffix),'evidence_reference'=>'local://synthetic/ownership'];
 }
}
