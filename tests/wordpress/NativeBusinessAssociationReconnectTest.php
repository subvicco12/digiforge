<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeBusinessMutationFixture.php';
use DigiForge\Connector\Printify\{NativeProtectedOptionDependencies as Roots,NativeBusinessMutationEvidence as Evidence,NativeBusinessMutationAssociation as Association,NativeHeldJournalSchema};
use DigiForge\Database\Tables;
final class NativeBusinessAssociationReconnectTest extends WP_UnitTestCase {
 use NativeBusinessMutationFixture;
 private PrintifyApiApprovalFixture $fixture;private array $snapshots=[];
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();global $wpdb;foreach([Tables::opportunities(),Tables::product_families(),Tables::products(),Tables::product_versions(),Tables::production_plans(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::asset_specs(),Tables::production_plan_assets()]as $t)$this->snapshots[$t]=$wpdb->get_results('SELECT * FROM '.$t,ARRAY_A);DigiForge\Core\Settings::protectProduction();}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');foreach(NativeHeldJournalSchema::tables()as $t)$wpdb->query('DROP TABLE IF EXISTS '.$t);foreach($this->snapshots as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $r)$wpdb->insert($t,$r,array_fill(0,count($r),'%s'));}$this->fixture->restore();}finally{parent::tearDown();}}
 public function testReconnectAfterProducerCannotFalselyAcknowledgeApprovedSource():void {
  $f=$this->businessMutationFixture($this->fixture);$scope=$f['scope'];$m=$f['mapping_id'];$s=$f['spec_id'];$p=$f['plan_hash'];$source=PrintifyApiApprovalFixture::checked(Association::source($scope,1,$m,$s,$p));$proposal=PrintifyApiApprovalFixture::checked(Association::propose($scope,1,$m,$s,$p,$source['source_hash'],0,time(),time()+1200,wp_generate_uuid4()));
  PortalAccessFixture::login($this->fixture->reviewer);global $wpdb;$originalId=(string)$wpdb->get_var('SELECT CONNECTION_ID()');$seenProducer=false;$reconnected=false;$connectResult=false;
  $hook=static function($q)use(&$seenProducer,&$reconnected,&$connectResult,$wpdb){
   if(str_starts_with(ltrim($q),'INSERT')&&str_contains($q,'native_mutation_business_source_reviewed'))$seenProducer=true;
   if($seenProducer&&!$reconnected&&str_starts_with(ltrim($q),'UPDATE')&&str_contains($q,$wpdb->options)&&str_contains($q,'digiforge_local_review_')){$reconnected=true;$wpdb->close();$connectResult=$wpdb->db_connect(false);}
   return $q;
  };
  add_filter('query',$hook);try{$result=Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,$proposal['revision'],$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4());}finally{remove_filter('query',$hook);}
  self::assertTrue($seenProducer);self::assertTrue($reconnected);self::assertTrue($connectResult);self::assertNotSame($originalId,(string)$wpdb->get_var('SELECT CONNECTION_ID()'));self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
  self::assertSame('0',(string)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s','native_mutation_business_source_reviewed','native_mutation_association',$proposal['proposal_hash'])),'The original producer insertion rolled back on connection loss.');
  $inspection=Association::read($scope,$this->fixture->reviewer,$m,$s,$p);self::assertWPError($inspection,'Existing inspection must continue to reject an orphaned generic approval.');self::assertSame('printify_native_association_business_producer_required',$inspection->get_error_code());
  self::assertWPError($result,'The source-specific decision must not acknowledge APPROVED without its durable producer provenance.');self::assertSame('printify_native_association_approval_unknown',$result->get_error_code());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
}
