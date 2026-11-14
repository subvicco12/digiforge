<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeBusinessMutationFixture.php';
use DigiForge\Connector\Printify\{NativeBusinessMutationEvidence as Evidence,NativeHeldJournalSchema,NativeHeldJournal,NativeBridge};
use DigiForge\Database\Tables;
final class NativeBusinessSourceMaterialTest extends WP_UnitTestCase {
 use NativeBusinessMutationFixture;
 private PrintifyApiApprovalFixture $fixture;private array $snapshots=[];
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();global $wpdb;foreach([Tables::opportunities(),Tables::product_families(),Tables::products(),Tables::product_versions(),Tables::production_plans(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::asset_specs(),Tables::production_plan_assets()]as $t)$this->snapshots[$t]=$wpdb->get_results('SELECT * FROM '.$t,ARRAY_A);DigiForge\Core\Settings::protectProduction();}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');foreach(NativeHeldJournalSchema::tables()as $t)$wpdb->query('DROP TABLE IF EXISTS '.$t);foreach($this->snapshots as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $r)$wpdb->insert($t,$r,array_fill(0,count($r),'%s'));}$this->fixture->restore();}finally{parent::tearDown();}}
 public function testSharedCurrentSourceMaterialPreservesOriginalResolverAndRejectsStaleClaims():void {
  self::assertTrue(method_exists(Evidence::class,'sourceMaterial'),'Reuse the exact final validated source material instead of repeating expensive source reads.');
  $f=$this->businessMutationFixture($this->fixture);$r=PrintifyApiApprovalFixture::checked(Evidence::resolve($f['scope'],1,$f['mapping_id'],$f['spec_id'],$f['plan_hash']));$m=PrintifyApiApprovalFixture::checked(Evidence::sourceMaterial($f['scope'],1,$f['mapping_id'],$f['spec_id'],$f['plan_hash']));self::assertSame($r,$m['evidence']);self::assertCount(15,$m['business_dependencies']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$m['artwork_dependency_hash']);self::assertArrayNotHasKey('body',$m);self::assertArrayNotHasKey('contents',$m);
  global $wpdb;self::assertSame(1,$wpdb->update(Tables::product_versions(),['notes'=>'changed source material'],['id'=>$f['business_version_id']],['%s'],['%d']));$again=PrintifyApiApprovalFixture::checked(Evidence::sourceMaterial($f['scope'],1,$f['mapping_id'],$f['spec_id'],$f['plan_hash']));self::assertNotSame($m['business_dependencies'],$again['business_dependencies']);self::assertNotSame($r['evidence_hash'],$again['evidence']['evidence_hash']);self::assertWPError(Evidence::resolve($f['scope'],1,$f['mapping_id'],$f['spec_id'],$f['plan_hash'],$r['evidence_hash']));self::assertFalse($again['evidence']['external_execution_authorized']);
 }
}
