<?php
declare(strict_types=1);
require_once __DIR__.'/Support/SyntheticEtsyIdentity.php';
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/PortalActionAcceptanceFixture.php';

/** Synthetic local evidence only; no supplier or rights certification. */
final class RenderSourceArtifactIntegrityTest extends WP_UnitTestCase
{
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();global $wpdb;$t=DigiForge\Database\Tables::class;
  // Review/schema DDL may commit fixtures. Isolate this class's fixed synthetic identities between cases.
  $wpdb->query("DELETE usage_row FROM ".$t::shop_ai_usage()." usage_row INNER JOIN ".$t::pod_production_templates()." template ON template.fingerprint=usage_row.model_key WHERE template.template_id='personalized_pod' AND usage_row.workflow='production_template'");
  $wpdb->query("DELETE ps FROM ".$t::personalization_submissions()." ps INNER JOIN ".$t::order_line_items()." li ON li.id=ps.order_line_item_id INNER JOIN ".$t::orders()." o ON o.id=li.order_id WHERE o.external_order_reference='personalized_pod' AND o.shop_reference='personalized_pod'");
  $wpdb->query("DELETE li FROM ".$t::order_line_items()." li INNER JOIN ".$t::orders()." o ON o.id=li.order_id WHERE o.external_order_reference='personalized_pod' AND o.shop_reference='personalized_pod'");
  $wpdb->delete($t::orders(),['external_order_reference'=>'personalized_pod','shop_reference'=>'personalized_pod']);
  $wpdb->delete($t::pod_mappings(),['mapping_version'=>'personalized_pod','provider_product_key'=>'101','product_version_id'=>1]);
  $wpdb->delete($t::pod_production_templates(),['template_id'=>'personalized_pod']);
  $wpdb->delete($t::personalization_schemas(),['product_version_id'=>1,'schema_key'=>'name','version_label'=>'1']);
  $wpdb->delete($t::businesses(),['business_key'=>'digicraftifygoods','display_name'=>'Fixture goods']);
 }
 private function fixture(string $engine='PHOTO_TEXT',int $width=1,int $height=1,array $panels=[]):array {
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);$shop='personalized_pod';
  (new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$shop,'template_cap'=>['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-01-05 00:00:00']]);
  $templateInput=digiforge_test_printify_template(['shop_key'=>$shop,'template_id'=>$shop,'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202],'print_areas'=>$panels?:[['position'=>'front','decoration_method'=>'dtg','width_px'=>$width,'height_px'=>$height]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>$engine,'template_status'=>'VALIDATED','environment'=>'sandbox']);self::assertNotWPError($templateInput);$template=(new DigiForge\POD\ProductionTemplateRepository())->save($templateInput);self::assertNotWPError($template);
  $wpdb->insert($t::orders(),['channel'=>'etsy','environment'=>'sandbox','external_order_reference'=>$shop,'shop_reference'=>$shop,'currency'=>'USD','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$order=(int)$wpdb->insert_id;
  $wpdb->insert($t::pod_mappings(),['product_version_id'=>1,'production_plan_id'=>1,'provider'=>'printify','environment'=>'sandbox','provider_product_key'=>'101','provider_variant_key'=>'2:202','mapping_version'=>$shop,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$mapping=(int)$wpdb->insert_id;
  $wpdb->insert($t::order_line_items(),['order_id'=>$order,'product_version_id'=>1,'provider_mapping_id'=>$mapping,'quantity'=>1,'currency'=>'USD','environment'=>'sandbox','validation_status'=>'VALIDATED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$line=(int)$wpdb->insert_id;$ph=hash('sha256','fixture-personalization');
  $wpdb->insert($t::personalization_submissions(),['order_line_item_id'=>$line,'personalization_schema_id'=>1,'canonical_payload'=>'{}','payload_hash'=>$ph,'review_status'=>'APPROVED','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now,'environment'=>'sandbox','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
  $wpdb->insert($t::businesses(),['business_key'=>'digicraftifygoods','display_name'=>'Fixture goods','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert($t::stores(),['business_id'=>$business,'store_key'=>'fixture-etsy','display_name'=>'Fixture','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert($t::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);$program=(int)$wpdb->insert_id;
  $wpdb->insert($t::pod_business_mappings(),['provider_mapping_id'=>$mapping,'business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'product_version_id'=>1,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
  return ['order_id' =>$order,'provider_mapping_id'=>$mapping,'template_key'=>$shop,'template_version'=>'1','template_sha256'=>$template['fingerprint'],'personalization_evidence_hash'=>$ph,'render_mode'=>'DETERMINISTIC','output_sha256'=>hash('sha256','asserted'),'buyer_preview_sha256'=>hash('sha256','asserted')];
 }
 private function deterministicFixture(string $engine='NAME_MONOGRAM',array $panels=[]):array {
  $input=$this->fixture($engine,640,480,$panels);global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);$wpdb->insert($t::personalization_schemas(),['product_version_id'=>1,'schema_key'=>'name','version_label'=>'1','field_definitions'=>'[{"key":"name","type":"text","max_length":30},{"key":"photo","type":"image_reference"}]','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$schema=(int)$wpdb->insert_id;$payload=DigiForge\Orders\Validator::canonicalJson(['name'=>'Ada & <Lovelace>']);$old=$input['personalization_evidence_hash'];$input['personalization_evidence_hash']=hash('sha256',$payload);$wpdb->update($t::personalization_submissions(),['personalization_schema_id'=>$schema,'canonical_payload'=>$payload,'payload_hash'=>$input['personalization_evidence_hash']],['payload_hash'=>$old]);return $input;
 }

 public function testValidSourceSubstitutionCannotReachNewPreparationOrExistingPreview():void {
  $input=$this->deterministicFixture('SINGLE_PHOTO');
  $source=tempnam(sys_get_temp_dir(),'df-approved-source-');
  $original=DigiForge\POD\RasterArtworkCodec::encode(2,2,str_repeat("\xff\0\0\xff",4));
  $replacement=DigiForge\POD\RasterArtworkCodec::encode(2,2,str_repeat("\0\0\xff\xff",4));
  file_put_contents($source,$original);
  $verifier=new DigiForge\POD\RenderArtifactVerifier();
  $stored=(new DigiForge\POD\RenderArtifactStore())->retain($source,$verifier->readArtifact($source));
  self::assertNotWPError($stored);
  $path=DigiForge\ProductFactory\AssetStorage::absolutePath($stored['storage_reference']);
  self::assertNotNull($path);
  global $wpdb;$t=DigiForge\Database\Tables::class;
  $payload=DigiForge\Orders\Validator::canonicalJson(['photo'=>$stored['storage_reference']]);
  self::assertSame(1,$wpdb->update($t::personalization_submissions(),['canonical_payload'=>$payload,'payload_hash'=>hash('sha256',$payload)],['payload_hash'=>$input['personalization_evidence_hash']]));
  $input['personalization_evidence_hash']=hash('sha256',$payload);
  $submission=(int)$wpdb->get_var($wpdb->prepare('SELECT ps.id FROM '.$t::personalization_submissions().' ps INNER JOIN '.$t::order_line_items().' li ON li.id=ps.order_line_item_id WHERE li.order_id=%d AND ps.payload_hash=%s',$input['order_id'],$input['personalization_evidence_hash']));
  $renderer=new DigiForge\POD\DeterministicPersonalizationRenderer();
  $recipe=['engine'=>'SINGLE_PHOTO','background'=>'#ffffff','slots'=>[['type'=>'image','field'=>'photo','x'=>0,'y'=>0,'width'=>2,'height'=>2]]];
  self::assertNotWPError($renderer->approveRecipe($input['template_key'],1,$recipe));
  $workflow=new DigiForge\POD\DeterministicRenderWorkflow();
  $request=['shop_key'=>'personalized_pod','template_key'=>$input['template_key'],'template_version'=>1,'submission_id'=>$submission,'decision'=>'PREPARE_LOCAL_ARTIFACTS','artifact_format'=>'png'];
  try{
   $expected=$renderer->expected($input);self::assertNotWPError($expected);
   self::assertStringContainsString(base64_encode($original),$expected['svg']);
   $render=$workflow->prepare($request);self::assertNotWPError($render);
   $preview=$workflow->preview((int)$render['id'],'front');self::assertNotWPError($preview);
   self::assertSame($preview['sha256'],hash('sha256',base64_decode($preview['content_base64'],true)));
   self::assertSame('UNREVIEWED',$render['review_status']);
   file_put_contents($path,$replacement);
   self::assertNotWPError($verifier->readArtifact($path),'Replacement is an actual valid image, not undecodable corruption.');
   $blocked=$renderer->expected($input);self::assertWPError($blocked);
   self::assertSame('deterministic_render_image_conflict',$blocked->get_error_code());
   self::assertWPError($workflow->prepare($request));
   self::assertWPError($workflow->preview((int)$render['id'],'front'));
   file_put_contents($path,$original);
   self::assertSame($expected['sha256'],$renderer->expected($input)['sha256']);
   self::assertSame($preview['sha256'],$workflow->preview((int)$render['id'],'front')['sha256']);
  }finally{file_put_contents($path,$original);unlink($source);}
 }
}
