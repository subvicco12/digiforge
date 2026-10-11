<?php
declare(strict_types=1);

final class PrintifyPreflightTest extends WP_UnitTestCase
{
 public function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 private function saveTemplate(array $input):array|WP_Error {
  $shop='preflight-fixture-'.get_current_user_id();
  $policy=(new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$shop,'currency'=>'USD','template_cap'=>['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-01-05 00:00:00']]);
  self::assertFalse(is_wp_error($policy));$input['shop_key']=$shop;
  return (new DigiForge\POD\ProductionTemplateRepository())->save($input);
 }
 public function testValidatedTemplateRouteIsRequiredAndDryRunNeverAuthorizesExecution():void{
  global $wpdb;$now=current_time('mysql',true);
  $packageId=$this->seedReadyPackage($now,321,'77:88');
  $blocked=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);
  self::assertFalse(is_wp_error($blocked));self::assertContains('PRINTIFY_TEMPLATE_NOT_VALIDATED',$blocked['blockers']);self::assertFalse($blocked['ready_for_external_execution']);
  $template=$this->saveTemplate(['template_id'=>'wp-preflight','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>321,'provider_id'=>77,'variant_ids'=>[88],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1200,'height_px'=>1600]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);
  self::assertFalse(is_wp_error($template));
  $ready=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);
  self::assertFalse(is_wp_error($ready));self::assertNotContains('PRINTIFY_TEMPLATE_NOT_VALIDATED',$ready['blockers']);self::assertNotContains('PRINTIFY_ROUTE_TEMPLATE_MISMATCH',$ready['blockers']);self::assertFalse($ready['ready_for_external_execution']);
  $dry=(new DigiForge\POD\PersonalizedPodDryRun())->certify($packageId);self::assertFalse(is_wp_error($dry));self::assertSame('BLOCKED',$dry['execution_intent']['intent_state']);self::assertFalse($dry['execution_intent']['network_execution_performed']);self::assertFalse($dry['execution_intent']['retry_permitted']);self::assertNotSame('',(string)$dry['execution_intent']['template_version']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',(string)$dry['execution_intent']['template_fingerprint']);
 }
 public function testExecutionPermitBindsCurrentPreflightAndRejectsReadinessDrift():void{
  global $wpdb;$now=current_time('mysql',true);$packageId=$this->seedReadyPackage($now,888,'44:55');
  $template=$this->saveTemplate(['template_id'=>'wp-permit','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>888,'provider_id'=>44,'variant_ids'=>[55],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1200,'height_px'=>1600]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);self::assertFalse(is_wp_error($template));
  $fp=hash('sha256','exact-provider-request');$permit=(new DigiForge\POD\ProductionExecutionPermit())->issue($packageId,$fp,'permit_nonce_12345678901234567890',300);
  self::assertFalse(is_wp_error($permit));self::assertSame($fp,$permit['request_fingerprint']);self::assertSame($fp,$permit['authorization']['request_fingerprint']);self::assertFalse($permit['external_execution_performed']);
  $orderId=(int)$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.DigiForge\Database\Tables::pod_authorization_packages().' WHERE id=%d',$packageId));
  $wpdb->update(DigiForge\Database\Tables::orders(),['state'=>'RECEIVED','updated_at'=>current_time('mysql',true)],['id'=>$orderId]);
  $stale=(new DigiForge\POD\ProductionExecutionPermit())->issue($packageId,$fp,'permit_nonce_abcdefghijklmnopqrstuvwxyz',300);
  self::assertTrue(is_wp_error($stale));self::assertSame('production_preflight_not_current',$stale->get_error_code());
 }
 public function testProductionPermitConsumptionIsOneTimeFingerprintBoundAndExpirySafe():void{
  $now=current_time('timestamp',true);$fp=hash('sha256','provider-payload');$nonce='consume_nonce_12345678901234567890';
  $approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>hash('sha256','preflight')];
  global $wpdb;$seedNow=current_time('mysql',true);$packageId=$this->seedReadyPackage($seedNow,991,'61:62');$packageHash=(string)$wpdb->get_var($wpdb->prepare('SELECT package_hash FROM '.DigiForge\Database\Tables::pod_authorization_packages().' WHERE id=%d',$packageId));$permit=DigiForge\POD\ExecutionAuthorization::issue($approval,'PROVIDER_ORDER_SUBMIT',get_current_user_id(),$nonce,300,$fp);self::assertFalse(is_wp_error($permit));$permit['package_id']=$packageId;$permit['package_hash']=$packageHash;
  $consumer=new DigiForge\POD\ProductionExecutionPermitConsumer();$first=$consumer->consume($permit,$fp,get_current_user_id(),$now);
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
  $template=$this->saveTemplate(['template_id'=>'wp-stale','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>777,'provider_id'=>22,'variant_ids'=>[33],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1000,'height_px'=>1000]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);self::assertFalse(is_wp_error($template));
  $before=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($before));self::assertNotContains('ORDER_READINESS_STALE',$before['blockers']);
  $orderId=(int)$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.DigiForge\Database\Tables::pod_authorization_packages().' WHERE id=%d',$packageId));
  $wpdb->update(DigiForge\Database\Tables::orders(),['state'=>'RECEIVED','updated_at'=>current_time('mysql',true)],['id'=>$orderId]);
  $after=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($after));self::assertContains('ORDER_READINESS_STALE',$after['blockers']);self::assertContains('ORDER_NOT_READY',$after['blockers']);self::assertFalse($after['ready_for_dry_run']);self::assertFalse($after['ready_for_external_execution']);
 }
 public function testRouteVariantMismatchFailsClosed():void{
  global $wpdb;$now=current_time('mysql',true);$packageId=$this->seedReadyPackage($now,654,'55:66');
  $template=$this->saveTemplate(['template_id'=>'wp-mismatch','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>654,'provider_id'=>55,'variant_ids'=>[67],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1000,'height_px'=>1000]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);self::assertFalse(is_wp_error($template));
  $result=(new DigiForge\POD\PrintifyProductionPreflight())->evaluate($packageId);self::assertFalse(is_wp_error($result));self::assertContains('PRINTIFY_ROUTE_TEMPLATE_MISMATCH',$result['blockers']);self::assertFalse($result['ready_for_dry_run']);
 }
 private function seedReadyPackage(string $now,int $blueprint,string $variant):int{
  global $wpdb;
  $tables=DigiForge\Database\Tables::class;
  $wpdb->insert($tables::orders(),['channel'=>'etsy','environment'=>'sandbox','external_order_reference'=>wp_generate_uuid4(),'shop_reference'=>'test','currency'=>'USD','personalization_required'=>0,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$orderId=(int)$wpdb->insert_id;
  $wpdb->insert($tables::pod_mappings(),['product_version_id'=>1,'production_plan_id'=>1,'provider'=>'printify','environment'=>'sandbox','provider_product_key'=>(string)$blueprint,'provider_variant_key'=>$variant,'mapping_version'=>'test-v1','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$mappingId=(int)$wpdb->insert_id;
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
