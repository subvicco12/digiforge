<?php
declare(strict_types=1);
final class ProductionLifecyclePersistenceTest extends WP_UnitTestCase{
 protected function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 public function testAcknowledgementPersistsIdempotentlyAndRejectsConflict():void{
  global $wpdb;$now=current_time('mysql',true);$auth=hash('sha256','ack-auth');$unknown=hash('sha256','ack-unknown');$e=['authorization_hash'=>$auth,'unknown_hash'=>$unknown,'request_fingerprint'=>hash('sha256','fp'),'integration_id'=>1,'resolution_state'=>'PRINTIFY_RECONCILIATION_CONFIRMED','provider_order_id'=>'p1','provider_status'=>'ok','http_status'=>200,'pagination_required'=>false,'pages_checked'=>1,'retry_permitted'=>false,'reconciliation_required'=>false];$canonical=$e;ksort($canonical);$rh=hash('sha256',(string)wp_json_encode($canonical));
  $wpdb->insert(DigiForge\Database\Tables::pod_printify_reconciliations(),['authorization_hash'=>$auth,'unknown_hash'=>$unknown,'request_fingerprint'=>$e['request_fingerprint'],'integration_id'=>1,'resolution_state'=>$e['resolution_state'],'evidence'=>wp_json_encode($e),'reconciliation_hash'=>$rh,'created_at'=>$now]);
  $ack=DigiForge\POD\ReconciliationAcknowledgement::acknowledge(['authorization_hash'=>$auth,'unknown_hash'=>$unknown,'reconciliation_hash'=>$rh,'resolution_state'=>$e['resolution_state']],get_current_user_id(),'ACKNOWLEDGE_CONFIRMED');self::assertFalse(is_wp_error($ack));
  $first=DigiForge\POD\ReconciliationAcknowledgementRepository::save($ack);$again=DigiForge\POD\ReconciliationAcknowledgementRepository::save($ack);self::assertFalse(is_wp_error($first));self::assertSame((int)$first['id'],(int)$again['id']);
  $bad=$ack;$bad['acknowledgement_hash']=hash('sha256','different');$conflict=DigiForge\POD\ReconciliationAcknowledgementRepository::save($bad);self::assertWPError($conflict);self::assertSame('reconciliation_ack_conflict',$conflict->get_error_code());
 }
 public function testClosureRequiresPersistedPackageNonceAndTerminalOutcomeAndReplaysExactly():void{
  global $wpdb;$now=current_time('mysql',true);$auth=hash('sha256','closure-auth');$packageHash=hash('sha256','closure-package');$nonce='closure_nonce_12345678901234567890';$nonceHash=hash('sha256',$nonce);$failureHash=hash('sha256','closure-failure');
  $wpdb->insert(DigiForge\Database\Tables::pod_authorization_packages(),['order_id'=>1,'render_evidence_id'=>1,'provider_mapping_id'=>1,'ownership_mapping_id'=>1,'readiness_hash'=>hash('sha256','ready'),'package_hash'=>$packageHash,'state'=>'APPROVED_PACKAGE','approved_by'=>get_current_user_id(),'approved_at'=>$now,'external_execution_authorized'=>0,'external_execution_performed'=>0,'created_at'=>$now]);$packageId=(int)$wpdb->insert_id;
  self::assertTrue(DigiForge\POD\ExecutionNonceLedger::consume($nonce,$auth,get_current_user_id()));
  $wpdb->insert(DigiForge\Database\Tables::pod_execution_failures(),['action'=>'X','evidence_hash'=>hash('sha256','e'),'authorization_hash'=>$auth,'nonce_hash'=>$nonceHash,'failure_category'=>'adapter_error','failure_code'=>'timeout','executed_by'=>get_current_user_id(),'recorded_at'=>$now,'failure_hash'=>$failureHash,'created_at'=>$now]);
  $package=['id'=>$packageId,'package_hash'=>$packageHash,'state'=>'APPROVED_PACKAGE','approved_by'=>get_current_user_id()];$projection=(new DigiForge\POD\ConsumedPermitOutcomeReadModel())->project($auth);self::assertSame('EXECUTION_FAILED',$projection['state']);
  $closure=(new DigiForge\POD\ProductionLifecycleClosure())->close($package,$projection,get_current_user_id());self::assertFalse(is_wp_error($closure));$first=DigiForge\POD\ProductionLifecycleClosureRepository::save($closure);$again=DigiForge\POD\ProductionLifecycleClosureRepository::save($closure);self::assertFalse(is_wp_error($first));self::assertSame((int)$first['id'],(int)$again['id']);
  $bad=$closure;$bad['closure_hash']=hash('sha256','different');$conflict=DigiForge\POD\ProductionLifecycleClosureRepository::save($bad);self::assertWPError($conflict);self::assertSame('production_closure_conflict',$conflict->get_error_code());
 }
}
