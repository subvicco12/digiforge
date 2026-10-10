<?php
declare(strict_types=1);
final class RenderArtifactCertificationTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();}
 public function testSubscriberCannotApproveRenderOrPackage():void {
  wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));
  $r=(new DigiForge\POD\RenderEvidenceRepository())->approve(1);self::assertTrue(is_wp_error($r));self::assertSame('render_reviewer_required',$r->get_error_code());
  $p=(new DigiForge\POD\ProductionAuthorizationRepository())->approveForReview(1);self::assertTrue(is_wp_error($p));self::assertSame('authorization_reviewer_required',$p->get_error_code());
 }
 public function testSuppliedHashesCannotStandInForActualArtifacts():void {
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$r=(new DigiForge\POD\RenderArtifactVerifier())->assertCertified(['evidence_hash'=>hash('sha256','fake')]);self::assertTrue(is_wp_error($r));self::assertSame('render_artifact_receipt_required',$r->get_error_code());
 }
 public function testArtifactReaderRejectsRemoteAndNonImageInputs():void {
  $v=new DigiForge\POD\RenderArtifactVerifier();self::assertTrue(is_wp_error($v->readArtifact('https://example.invalid/never-requested')));$p=tempnam(sys_get_temp_dir(),'df-render-');try{file_put_contents($p,'not image');self::assertTrue(is_wp_error($v->readArtifact($p)));}finally{unlink($p);}
 }
 public function testActualImageDigestAndDimensionsComeFromBytes():void {
  $p=tempnam(sys_get_temp_dir(),'df-render-');$bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aR9sAAAAASUVORK5CYII=');try{file_put_contents($p,$bytes);$r=(new DigiForge\POD\RenderArtifactVerifier())->readArtifact($p);self::assertFalse(is_wp_error($r));self::assertSame(hash('sha256',$bytes),$r['sha256']);self::assertSame(1,$r['width_px']);self::assertSame(1,$r['height_px']);self::assertArrayNotHasKey('path',$r);}finally{unlink($p);}
 }
 private function fixture():array {
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);$shop='personalized_pod';
  (new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$shop,'template_cap'=>['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-01-05 00:00:00']]);
  $template=(new DigiForge\POD\ProductionTemplateRepository())->save(['shop_key'=>$shop,'template_id'=>$shop,'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1,'height_px'=>1]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'PHOTO_TEXT','template_status'=>'VALIDATED']);self::assertFalse(is_wp_error($template));
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
 private function image():string {$p=tempnam(sys_get_temp_dir(),'df-render-');file_put_contents($p,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aR9sAAAAASUVORK5CYII='));return $p;}
 public function testHashOnlyLegacyEvidenceRemainsUnreviewed():void {
  $input=$this->fixture();$repo=new DigiForge\POD\RenderEvidenceRepository();$r=$repo->create($input);self::assertFalse(is_wp_error($r));$result=$repo->approve((int)$r['id']);self::assertTrue(is_wp_error($result));self::assertSame('render_artifact_receipt_required',$result->get_error_code());global $wpdb;self::assertSame('UNREVIEWED',$wpdb->get_var($wpdb->prepare('SELECT review_status FROM '.DigiForge\Database\Tables::pod_render_evidence().' WHERE id=%d',$r['id'])));
 }
 public function testActualArtifactsBindTemplateAndApprovalThenDetectTemplateDrift():void {
  $input=$this->fixture();$p=$this->image();$repo=new DigiForge\POD\RenderEvidenceRepository();try{$r=$repo->createFromArtifacts($input,$p,$p);self::assertFalse(is_wp_error($r));self::assertSame(hash_file('sha256',$p),$r['output_sha256']);$approved=$repo->approve((int)$r['id']);self::assertSame('APPROVED',$approved['review_status']);global $wpdb;$wpdb->update(DigiForge\Database\Tables::pod_production_templates(),['template_status'=>'DRAFT'],['template_id'=>$input['template_key']]);$check=(new DigiForge\POD\RenderArtifactVerifier())->assertCertified($approved);self::assertTrue(is_wp_error($check));self::assertSame('render_artifact_template_required',$check->get_error_code());}finally{unlink($p);}
 }
 public function testTemplateFingerprintMappingAndShopConflictsAreBlocked():void {
  $input=$this->fixture();$p=$this->image();$repo=new DigiForge\POD\RenderEvidenceRepository();global $wpdb;try{
   $bad=$input;$bad['template_sha256']=hash('sha256','different');$r=$repo->createFromArtifacts($bad,$p,$p);self::assertSame('render_artifact_template_conflict',$r->get_error_code());
   $wpdb->update(DigiForge\Database\Tables::pod_mappings(),['provider_variant_key'=>'3:202'],['id'=>$input['provider_mapping_id']]);$r=$repo->createFromArtifacts($input,$p,$p);self::assertSame('render_artifact_mapping_conflict',$r->get_error_code());
   $wpdb->update(DigiForge\Database\Tables::pod_mappings(),['provider_variant_key'=>'2:202'],['id'=>$input['provider_mapping_id']]);$wpdb->update(DigiForge\Database\Tables::orders(),['shop_reference'=>'another-shop'],['id'=>$input['order_id']]);$r=$repo->createFromArtifacts($input,$p,$p);self::assertSame('render_artifact_scope_conflict',$r->get_error_code());
  }finally{unlink($p);}
 }
 public function testActualPreviewMismatchFailsBeforeCertification():void {
  $input=$this->fixture();$p=$this->image();$q=$this->image();try{file_put_contents($q,file_get_contents($q).'different');$r=(new DigiForge\POD\RenderEvidenceRepository())->createFromArtifacts($input,$p,$q);self::assertTrue(is_wp_error($r));self::assertSame('render_preview_parity_failed',$r->get_error_code());}finally{unlink($p);unlink($q);}
 }

 public function testReceiptPersistenceDoesNotAcceptCallerHashArrays():void {
  self::assertFalse(method_exists(DigiForge\POD\RenderArtifactVerifier::class,'record'));
  $r=new ReflectionMethod(DigiForge\POD\RenderArtifactVerifier::class,'recordFromArtifacts');self::assertSame('string',(string)$r->getParameters()[1]->getType());self::assertSame('string',(string)$r->getParameters()[2]->getType());
 }
 public function testExternalEtsyShopRequiresExactVerifiedBusinessIdentity():void {
  $input=$this->fixture();global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);$wpdb->update($t::orders(),['shop_reference'=>'68031896'],['id'=>$input['order_id']]);$p=$this->image();$repo=new DigiForge\POD\RenderEvidenceRepository();try{
   self::assertTrue(is_wp_error($repo->createFromArtifacts($input,$p,$p)));
   $wpdb->insert($t::integrations(),['provider'=>'etsy','environment'=>'sandbox','connection_key'=>'fixture-render-'.wp_generate_uuid4(),'display_name'=>'Offline identity fixture','status'=>'CONFIGURED','enabled'=>0,'config'=>wp_json_encode(['_connection_test'=>['ok'=>true,'details'=>['shop_id'=>68031896,'shop_name'=>'DigicraftifyShop']]]),'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
   $r=$repo->createFromArtifacts($input,$p,$p);self::assertFalse(is_wp_error($r));self::assertSame('APPROVED',$repo->approve((int)$r['id'])['review_status']);
   $wpdb->update($t::orders(),['shop_reference'=>'67757764'],['id'=>$input['order_id']]);$bad=(new DigiForge\POD\RenderArtifactVerifier())->assertCertified($r);self::assertTrue(is_wp_error($bad));self::assertSame('render_artifact_scope_conflict',$bad->get_error_code());
  }finally{unlink($p);}
 }

}
