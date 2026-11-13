<?php
declare(strict_types=1);

require_once __DIR__.'/../fixtures/PrintifyGeometryFixture.php';

final class PrintifyPreflightTest extends WP_UnitTestCase
{
 public function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 public function testValidatedTemplateRouteIsRequiredAndDryRunNeverAuthorizesExecution():void{
  global $wpdb;$now=current_time('mysql',true);
  $packageId=$this->seedReadyPackage($now,321,'77:88');
  $blocked=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);
  self::assertFalse(is_wp_error($blocked));self::assertContains('PRINTIFY_TEMPLATE_NOT_VALIDATED',$blocked['blockers']);self::assertFalse($blocked['ready_for_external_execution']);
  $template=$this->saveAuthoritativeTemplate('wp-preflight',321,77,88,1200,1600);
  self::assertFalse(is_wp_error($template));
  $ready=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);
  self::assertFalse(is_wp_error($ready));self::assertNotContains('PRINTIFY_TEMPLATE_NOT_VALIDATED',$ready['blockers']);self::assertNotContains('PRINTIFY_ROUTE_TEMPLATE_MISMATCH',$ready['blockers']);self::assertFalse($ready['ready_for_external_execution']);
  $dry=(new DigiForge\POD\PersonalizedPodDryRun())->certify($packageId);self::assertFalse(is_wp_error($dry));self::assertSame('BLOCKED',$dry['execution_intent']['intent_state']);self::assertFalse($dry['execution_intent']['network_execution_performed']);self::assertFalse($dry['execution_intent']['retry_permitted']);self::assertNotSame('',(string)$dry['execution_intent']['template_version']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',(string)$dry['execution_intent']['template_fingerprint']);
 }
 public function testExecutionPermitBindsCurrentPreflightAndRejectsReadinessDrift():void{
  global $wpdb;$now=current_time('mysql',true);$packageId=$this->seedReadyPackage($now,888,'44:55');
  $template=$this->saveAuthoritativeTemplate('wp-permit',888,44,55,1200,1600);self::assertFalse(is_wp_error($template));
  $fp=hash('sha256','exact-provider-request');$permit=(new DigiForge\POD\ProductionExecutionPermit())->issue($packageId,$fp,'permit_nonce_12345678901234567890',300);
  self::assertFalse(is_wp_error($permit));self::assertSame($fp,$permit['request_fingerprint']);self::assertSame($fp,$permit['authorization']['request_fingerprint']);self::assertFalse($permit['external_execution_performed']);
  $orderId=(int)$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.DigiForge\Database\Tables::pod_authorization_packages().' WHERE id=%d',$packageId));
  $wpdb->update(DigiForge\Database\Tables::orders(),['state'=>'RECEIVED','updated_at'=>current_time('mysql',true)],['id'=>$orderId]);
  $stale=(new DigiForge\POD\ProductionExecutionPermit())->issue($packageId,$fp,'permit_nonce_abcdefghijklmnopqrstuvwxyz',300);
  self::assertTrue(is_wp_error($stale));self::assertSame('production_preflight_not_current',$stale->get_error_code());
 }
 public function testProductionPermitConsumptionIsOneTimeFingerprintBoundAndExpirySafe():void{
  $fp=hash('sha256','provider-payload');$nonce='consume_nonce_12345678901234567890';
  $approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>hash('sha256','preflight')];
  global $wpdb;$seedNow=current_time('mysql',true);$packageId=$this->seedReadyPackage($seedNow,991,'61:62');$packageHash=(string)$wpdb->get_var($wpdb->prepare('SELECT package_hash FROM '.DigiForge\Database\Tables::pod_authorization_packages().' WHERE id=%d',$packageId));$permit=DigiForge\POD\ExecutionAuthorization::issue($approval,'PROVIDER_ORDER_SUBMIT',get_current_user_id(),$nonce,300,$fp);self::assertFalse(is_wp_error($permit));$permit['package_id']=$packageId;$permit['package_hash']=$packageHash;
  // Use the permit clock, not a timestamp captured before potentially slow SQL setup.
  $now=(int)$permit['authorization']['issued_at'];$consumer=new DigiForge\POD\ProductionExecutionPermitConsumer();
  $early=$consumer->consume($permit,$fp,get_current_user_id(),$now-1);self::assertTrue(is_wp_error($early));self::assertSame('production_permit_expired',$early->get_error_code());
  $first=$consumer->consume($permit,$fp,get_current_user_id(),$now);
  self::assertFalse(is_wp_error($first));self::assertTrue($first['nonce_consumed']);self::assertFalse($first['external_execution_performed']);
  $replay=$consumer->consume($permit,$fp,get_current_user_id(),$now);self::assertTrue(is_wp_error($replay));self::assertSame('digiforge_execution_replay',$replay->get_error_code());
  $mismatch=DigiForge\POD\ExecutionAuthorization::issue($approval,'PROVIDER_ORDER_SUBMIT',get_current_user_id(),'consume_nonce_abcdefghijklmnopqrstuvwxyz',300,$fp);self::assertFalse(is_wp_error($mismatch));$mismatch['package_id']=$packageId;$mismatch['package_hash']=$packageHash;
  $bad=$consumer->consume($mismatch,hash('sha256','different-payload'),get_current_user_id(),$now);self::assertTrue(is_wp_error($bad));self::assertSame('production_permit_fingerprint_mismatch',$bad->get_error_code());
  $expired=DigiForge\POD\ExecutionAuthorization::issue($approval,'PROVIDER_ORDER_SUBMIT',get_current_user_id(),'consume_nonce_expired_1234567890',60,$fp);self::assertFalse(is_wp_error($expired));$expired['package_id']=$packageId;$expired['package_hash']=$packageHash;
  $late=$consumer->consume($expired,$fp,get_current_user_id(),(int)$expired['authorization']['expires_at']+1);self::assertTrue(is_wp_error($late));self::assertSame('production_permit_expired',$late->get_error_code());
 }
 public function testLifecycleClosureAndReconciliationAcknowledgementFailClosed():void{
  $reviewer=get_current_user_id();$auth=hash('sha256','auth');$unknown=hash('sha256','unknown');$rh=hash('sha256','reconciliation');
  $reconciliation=['authorization_hash'=>$auth,'unknown_hash'=>$unknown,'reconciliation_hash'=>$rh,'resolution_state'=>'PRINTIFY_RECONCILIATION_CONFIRMED'];
  $ack=DigiForge\POD\ReconciliationAcknowledgement::acknowledge($reconciliation,$reviewer,'ACKNOWLEDGE_CONFIRMED');self::assertFalse(is_wp_error($ack));self::assertFalse($ack['acknowledgement']['retry_permitted']);
  $conflict=DigiForge\POD\ReconciliationAcknowledgement::acknowledge($reconciliation,$reviewer,'ACKNOWLEDGE_NOT_CONFIRMED');self::assertTrue(is_wp_error($conflict));self::assertSame('reconciliation_acknowledgement_conflict',$conflict->get_error_code());
  $package=['id'=>7,'package_hash'=>hash('sha256','package'),'state'=>'APPROVED_PACKAGE','approved_by'=>$reviewer];
  $projection=['state'=>'EXECUTION_FAILED','authorization_hash'=>$auth,'nonce_consumed'=>true,'terminal_outcome'=>['state'=>'EXECUTION_FAILED']];
  $closed=(new DigiForge\POD\ProductionLifecycleClosure())->close($package,$projection,$reviewer);self::assertFalse(is_wp_error($closed));self::assertSame('PRODUCTION_LIFECYCLE_CLOSED',$closed['state']);self::assertSame('CONFIRMED_FAILURE',$closed['closure']['external_execution_state']);self::assertArrayNotHasKey('external_execution_performed',$closed['closure']);
  $unknownProjection=$projection;$unknownProjection['state']='EXECUTION_UNKNOWN';$unknownProjection['external_execution_state']='UNKNOWN';$unknownBlocked=(new DigiForge\POD\ProductionLifecycleClosure())->close($package,$unknownProjection,$reviewer);self::assertTrue(is_wp_error($unknownBlocked));self::assertSame('production_closure_outcome_invalid',$unknownBlocked->get_error_code());
  $bad=$projection;$bad['nonce_consumed']=false;$blocked=(new DigiForge\POD\ProductionLifecycleClosure())->close($package,$bad,$reviewer);self::assertTrue(is_wp_error($blocked));self::assertSame('production_closure_outcome_invalid',$blocked->get_error_code());
 }
 public function testReadinessDriftInvalidatesApprovedPackage():void{
  global $wpdb;$now=current_time('mysql',true);$packageId=$this->seedReadyPackage($now,777,'22:33');
  $template=$this->saveAuthoritativeTemplate('wp-stale',777,22,33,1000,1000);self::assertFalse(is_wp_error($template));
  $before=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($before));self::assertNotContains('ORDER_READINESS_STALE',$before['blockers']);
  $orderId=(int)$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.DigiForge\Database\Tables::pod_authorization_packages().' WHERE id=%d',$packageId));
  $wpdb->update(DigiForge\Database\Tables::orders(),['state'=>'RECEIVED','updated_at'=>current_time('mysql',true)],['id'=>$orderId]);
  $after=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($after));self::assertContains('ORDER_READINESS_STALE',$after['blockers']);self::assertContains('ORDER_NOT_READY',$after['blockers']);self::assertFalse($after['ready_for_dry_run']);self::assertFalse($after['ready_for_external_execution']);
 }
 public function testRouteVariantMismatchFailsClosed():void{
  global $wpdb;$now=current_time('mysql',true);$packageId=$this->seedReadyPackage($now,654,'55:66');
  $template=$this->saveAuthoritativeTemplate('wp-mismatch',654,55,67,1000,1000);self::assertFalse(is_wp_error($template));
  $result=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($result));self::assertContains('PRINTIFY_ROUTE_TEMPLATE_MISMATCH',$result['blockers']);self::assertFalse($result['ready_for_dry_run']);
 }
 public function testChangedCatalogObservationBlocksPreviouslyValidatedTemplate():void {
  global $wpdb;$id=$this->seedReadyPackage(current_time('mysql',true),812,'77:88');$t=$this->saveAuthoritativeTemplate('stale-observation',812,77,88,1200,1600);self::assertFalse(is_wp_error($t));
  $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::pod_catalog().' WHERE provider_product_key=%s','812'),ARRAY_A);$attrs=json_decode($row['attributes'],true);$attrs['_digiforge_source_evidence']['observed_at']=gmdate('c',time()-60);$wpdb->update(DigiForge\Database\Tables::pod_catalog(),['attributes'=>wp_json_encode($attrs)],['id'=>$row['id']]);
  $r=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($id);self::assertFalse(is_wp_error($r));self::assertContains('PRINTIFY_TEMPLATE_PROVENANCE_STALE',$r['blockers']);self::assertFalse($r['ready_for_dry_run']);self::assertFalse($r['ready_for_external_execution']);
 }
 public function testMissingExactVariantEvidenceBlocksValidatedTemplate():void {
  global $wpdb;$id=$this->seedReadyPackage(current_time('mysql',true),813,'77:88');$t=$this->saveAuthoritativeTemplate('missing-catalog',813,77,88,1200,1600);self::assertFalse(is_wp_error($t));$wpdb->delete(DigiForge\Database\Tables::pod_catalog(),['provider_product_key'=>'813']);
  $r=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($id);self::assertFalse(is_wp_error($r));self::assertContains('PRINTIFY_TEMPLATE_PROVENANCE_STALE',$r['blockers']);self::assertFalse($r['ready_for_dry_run']);
 }
 public function testApprovedMappingCannotSubstituteDifferentGeometry():void {
  global $wpdb;$id=$this->seedReadyPackage(current_time('mysql',true),815,'77:88');$t=$this->saveAuthoritativeTemplate('mapped-geometry',815,77,88,1200,1600);self::assertFalse(is_wp_error($t));$mapping=(int)$wpdb->get_var($wpdb->prepare('SELECT provider_mapping_id FROM '.DigiForge\Database\Tables::pod_authorization_packages().' WHERE id=%d',$id));$wpdb->update(DigiForge\Database\Tables::pod_print_areas(),['width_value'=>1201],['provider_mapping_id'=>$mapping]);
  $r=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($id);self::assertFalse(is_wp_error($r));self::assertContains('PRINTIFY_MAPPED_GEOMETRY_MISMATCH',$r['blockers']);self::assertFalse($r['ready_for_dry_run']);
 }
 public function testStoredGeometryFingerprintCannotBeForged():void {
  global $wpdb;$id=$this->seedReadyPackage(current_time('mysql',true),814,'77:88');$t=$this->saveAuthoritativeTemplate('changed-fingerprint',814,77,88,1200,1600);self::assertFalse(is_wp_error($t));$wpdb->update(DigiForge\Database\Tables::pod_production_templates(),['fingerprint'=>str_repeat('a',64)],['id'=>$t['id']]);
  $r=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($id);self::assertFalse(is_wp_error($r));self::assertContains('PRINTIFY_TEMPLATE_PROVENANCE_INVALID',$r['blockers']);self::assertFalse($r['ready_for_dry_run']);
 }
 private function saveAuthoritativeTemplate(string $id,int $blueprint,int $provider,int $variant,int $width,int $height):array|WP_Error {
  global $wpdb;$row=PrintifyGeometryFixture::catalog($blueprint,$provider,$variant,$width,$height);$now=current_time('mysql',true);
  $data=$row;$data['attributes']=wp_json_encode($row['attributes']);$data+=['title'=>'Synthetic supplier fixture','variant_label'=>'Fixture','currency'=>'USD','base_cost'=>1,'shipping_profile'=>'{}','availability_state'=>'AVAILABLE','observed_at'=>$now,'state'=>'DRAFT','created_at'=>$now,'updated_at'=>$now];
  self::assertNotFalse($wpdb->insert(DigiForge\Database\Tables::pod_catalog(),$data));
  // Existing installed-lineage template-cycle policy is explicitly supplied, never bypassed.
  $policy=['template_cap'=>['limit'=>50,'duration_seconds'=>86400,'anchor_utc'=>gmdate('Y-m-d 00:00:00')],'currency'=>'USD'];
  $wpdb->delete(DigiForge\Database\Tables::shop_ai_policies(),['shop_key'=>'personalized_pod','environment'=>'production']);
  self::assertNotFalse($wpdb->insert(DigiForge\Database\Tables::shop_ai_policies(),['shop_key'=>'personalized_pod','environment'=>'production','currency'=>'USD','policy'=>wp_json_encode($policy),'policy_hash'=>hash('sha256',wp_json_encode($policy)),'state'=>'ACTIVE','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]));
  $candidate=PrintifyGeometryFixture::candidate($row,$id,$width,$height);$candidate['shop_key']='personalized_pod';
  return (new DigiForge\POD\ProductionTemplateRepository())->save($candidate);
 }
 private function seedReadyPackage(string $now,int $blueprint,string $variant):int{
  global $wpdb;
  $tables=DigiForge\Database\Tables::class;
  $wpdb->insert($tables::orders(),['channel'=>'etsy','environment'=>'sandbox','external_order_reference'=>wp_generate_uuid4(),'shop_reference'=>'test','currency'=>'USD','personalization_required'=>0,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$orderId=(int)$wpdb->insert_id;
  $wpdb->insert($tables::pod_mappings(),['product_version_id'=>1,'production_plan_id'=>1,'provider'=>'printify','environment'=>'sandbox','provider_product_key'=>(string)$blueprint,'provider_variant_key'=>$variant,'mapping_version'=>'fixture-'.wp_generate_uuid4(),'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$mappingId=(int)$wpdb->insert_id;
  $wpdb->insert($tables::order_line_items(),['order_id'=>$orderId,'product_version_id'=>1,'provider_mapping_id'=>$mappingId,'quantity'=>1,'currency'=>'USD','environment'=>'sandbox','validation_status'=>'VALIDATED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
  $wpdb->insert($tables::pod_print_areas(),['provider_mapping_id'=>$mappingId,'asset_spec_id'=>1,'area_key'=>'front','placement'=>'front','width_value'=>1200,'height_value'=>1600,'unit'=>'px','dpi_target'=>300,'state'=>'APPROVED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
  $wpdb->insert($tables::businesses(),['business_key'=>'preflight-'.wp_rand(10000,99999),'display_name'=>'Preflight','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert($tables::stores(),['business_id'=>$business,'store_key'=>'preflight-'.wp_rand(10000,99999),'display_name'=>'Preflight Store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert($tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);$program=(int)$wpdb->insert_id;
  $wpdb->insert($tables::pod_business_mappings(),['provider_mapping_id'=>$mappingId,'business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'product_version_id'=>1,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$ownership=(int)$wpdb->insert_id;
  $readiness=(new DigiForge\Orders\Repository())->readiness($orderId);self::assertFalse(is_wp_error($readiness));self::assertTrue($readiness['ready']);$hash=hash('sha256',wp_json_encode($readiness));
  $wpdb->insert($tables::pod_authorization_packages(),['order_id'=>$orderId,'render_evidence_id'=>1,'provider_mapping_id'=>$mappingId,'ownership_mapping_id'=>$ownership,'readiness_hash'=>$hash,'package_hash'=>hash('sha256','package-'.$orderId),'state'=>'APPROVED_PACKAGE','approved_by'=>get_current_user_id(),'approved_at'=>$now,'external_execution_authorized'=>0,'external_execution_performed'=>0,'created_at'=>$now]);
  return (int)$wpdb->insert_id;
 }
}
