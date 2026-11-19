<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeBusinessMutationFixture.php';
use DigiForge\Connector\Printify\{NativeBusinessArtworkEvidence as Artwork,NativeBusinessEvidence as Business,NativeHeldJournalSchema};
use DigiForge\Database\Tables;
final class NativeBusinessArtworkSourceLockTest extends WP_UnitTestCase {
 use NativeBusinessMutationFixture;
 private PrintifyApiApprovalFixture $fixture;private array $snapshots=[];
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();global $wpdb;foreach([Tables::opportunities(),Tables::product_families(),Tables::products(),Tables::product_versions(),Tables::production_plans(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::asset_specs(),Tables::production_plan_assets()]as $t)$this->snapshots[$t]=$wpdb->get_results('SELECT * FROM '.$t,ARRAY_A);DigiForge\Core\Settings::protectProduction();}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');foreach(NativeHeldJournalSchema::tables()as $t)$wpdb->query('DROP TABLE IF EXISTS '.$t);foreach($this->snapshots as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $r)$wpdb->insert($t,$r,array_fill(0,count($r),'%s'));}$this->fixture->restore();}finally{parent::tearDown();}}
 public function testArtworkRowsAndApprovalInsertRangesStayLockedUntilCallerRollback():void {
  self::assertTrue(method_exists(Artwork::class,'lockCurrent'),'Artwork dependency verifier must join the existing caller-owned review transaction.');
  $f=$this->businessMutationFixture($this->fixture);$scope=$f['scope'];$mapping=$f['mapping_id'];$spec=$f['spec_id'];
  $b=PrintifyApiApprovalFixture::checked(Business::product($scope,1,$mapping));$a=PrintifyApiApprovalFixture::checked(Artwork::resolve($scope,1,$mapping,$spec));
  global $wpdb;self::assertNotFalse($wpdb->query('START TRANSACTION'));$lost=false;$hook=static function($q)use(&$lost,$wpdb){if(!$lost&&str_contains($q,'SELECT * FROM '.Tables::asset_specs())&&str_contains($q,'FOR UPDATE')){$lost=true;$wpdb->query('COMMIT');}return $q;};add_filter('query',$hook);try{$lostFence=Artwork::lockCurrent($scope,1,$mapping,$spec,$b['dependency_hashes'],$a['dependency_hash']);}finally{remove_filter('query',$hook);}self::assertTrue($lost);self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));self::assertWPError($lostFence,'Lost caller transaction cannot produce a successful artwork SQL fence.');
  self::assertWPError(Artwork::lockCurrent($scope,1,$mapping,$spec,$b['dependency_hashes'],$a['dependency_hash']));
  global $wpdb;self::assertNotFalse($wpdb->query('START TRANSACTION'));
  $locked=PrintifyApiApprovalFixture::checked(Artwork::lockCurrent($scope,1,$mapping,$spec,$b['dependency_hashes'],$a['dependency_hash']));
  self::assertTrue($locked['business_dependencies_locked']);self::assertTrue($locked['artwork_sql_dependencies_locked']);self::assertFalse($locked['artwork_option_dependencies_locked']);self::assertFalse($locked['source_approval_cas_verified']);self::assertFalse($locked['dispatch_permitted']);self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
  $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->suppress_errors(true);$other->query('SET innodb_lock_wait_timeout=1');
  self::assertFalse($other->query($other->prepare('UPDATE '.Tables::asset_specs().' SET width_px=width_px+1 WHERE id=%d',$spec)));self::assertStringContainsString('Lock wait timeout',$other->last_error);
  self::assertFalse($other->query($other->prepare('UPDATE '.Tables::production_plan_assets().' SET is_required=0 WHERE production_plan_id=%d AND asset_spec_id=%d',$b['production_plan_id'],$spec)));self::assertStringContainsString('Lock wait timeout',$other->last_error);
  foreach([['production_state_changed','spec',(string)$spec,['to'=>'APPROVED']],['production_asset_linked','production_plan',(string)$b['production_plan_id'],['asset_spec_id'=>$spec,'required'=>true]]]as[$event,$type,$id,$context]){
   self::assertFalse($other->insert(Tables::audit_log(),['event_type'=>$event,'object_type'=>$type,'object_id'=>$id,'actor_id'=>1,'context'=>wp_json_encode($context),'created_at'=>gmdate('Y-m-d H:i:s')],['%s','%s','%s','%d','%s','%s']));self::assertStringContainsString('Lock wait timeout',$other->last_error);
  }
  self::assertNotFalse($wpdb->query('ROLLBACK'));self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));self::assertSame(1,$other->query($other->prepare('UPDATE '.Tables::asset_specs().' SET width_px=width_px+1 WHERE id=%d',$spec)));$other->close();
  self::assertNotFalse($wpdb->query('START TRANSACTION'));self::assertWPError(Artwork::lockCurrent($scope,1,$mapping,$spec,$b['dependency_hashes'],$a['dependency_hash']));self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));self::assertNotFalse($wpdb->query('ROLLBACK'));
  self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
}
