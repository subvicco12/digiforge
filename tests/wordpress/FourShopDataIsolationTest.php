<?php
declare(strict_types=1);
final class FourShopDataIsolationTest extends WP_UnitTestCase {
 protected function setUp():void { parent::setUp(); DigiForge\Core\Activator::activate(); }
 public function testOperationalProjectionDoesNotLeakShopPolicyOrUsageAcrossFourShops():void {
  global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);$shops=['digital','personalized_pod','standard_pod','jewelry'];
  foreach($shops as $i=>$shop){
   $policy=wp_json_encode(['marker'=>'policy-'.$shop]);
   $wpdb->insert($t::shop_ai_policies(),['shop_key'=>$shop,'environment'=>'production','currency'=>'USD','policy'=>$policy,'policy_hash'=>hash('sha256',(string)$policy),'state'=>'ACTIVE','created_by'=>0,'created_at'=>$now,'updated_at'=>$now]);
   $wpdb->insert($t::shop_ai_usage(),['shop_key'=>$shop,'workflow'=>'wf-'.$shop,'run_id'=>'run-'.$shop,'run_started_at'=>$now,'stage'=>'research','model_key'=>'model','quantity'=>$i+1,'estimated_cost'=>'0.100000','actual_cost'=>'0.090000','currency'=>'USD','occurred_at'=>$now]);
  }
  $rm=new DigiForge\Portal\ShopOperationsReadModel();
  foreach($shops as $shop){$s=$rm->snapshot($shop);self::assertSame($shop,$s['shop']);self::assertSame('AVAILABLE',$s['query_state']['ai_policies']);self::assertSame('AVAILABLE',$s['query_state']['ai_usage']);self::assertCount(1,$s['ai_policies']);self::assertCount(1,$s['ai_usage']);self::assertSame($shop,$s['ai_policies'][0]['shop_key']);self::assertSame($shop,$s['ai_usage'][0]['shop_key']);self::assertFalse($s['external_execution_performed']);}
 }
 public function testPersonalizedPodCatalogProjectionIgnoresNewerUnrelatedCatalog():void {
  global $wpdb;$v=DigiForge\Database\Tables::catalog_versions();$now=current_time('mysql',true);
  $fingerprint=hash('sha256','v2-scoped-catalog');
  $meta=['source_state'=>'MIGRATION_CANDIDATE','parent_catalog_key'=>DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,'parent_version_id'=>77,'source_file'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_FILE,'source_sha256'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'fingerprint'=>$fingerprint,'production_authority'=>false,'promotion_authorized'=>false];
  $wpdb->insert($v,['catalog_key'=>DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY,'version_label'=>'v2-scope-test','source_sha256'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'source_state'=>'MIGRATION_CANDIDATE','parent_version_id'=>77,'migration_metadata'=>wp_json_encode($meta),'row_count'=>500,'fingerprint'=>$fingerprint,'production_authority'=>0,'created_by'=>0,'created_at'=>$now]);
  $expected=(int)$wpdb->insert_id;
  $wpdb->insert($v,['catalog_key'=>'unrelated-shop-catalog','version_label'=>'later','source_sha256'=>hash('sha256','other'),'source_state'=>'REFERENCE','parent_version_id'=>0,'migration_metadata'=>wp_json_encode([]),'row_count'=>1,'fingerprint'=>hash('sha256','other-fingerprint'),'production_authority'=>0,'created_by'=>0,'created_at'=>$now]);
  $s=(new DigiForge\Portal\ShopOperationsReadModel())->snapshot('personalized_pod');
  self::assertSame($expected,(int)$s['catalog']['id']);self::assertSame(DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY,$s['catalog']['catalog_key']);self::assertTrue($s['catalog']['migration_metadata_valid']);
 }
 public function testMalformedV2LineageIsVisibleButNeverReportedVerified():void {
  global $wpdb;$v=DigiForge\Database\Tables::catalog_versions();$now=current_time('mysql',true);$fingerprint=hash('sha256','bad-v2');
  $wpdb->insert($v,['catalog_key'=>DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY,'version_label'=>'bad-v2-'.wp_rand(),'source_sha256'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'source_state'=>'MIGRATION_CANDIDATE','parent_version_id'=>88,'migration_metadata'=>wp_json_encode(['parent_version_id'=>999]),'row_count'=>500,'fingerprint'=>$fingerprint,'production_authority'=>0,'created_by'=>0,'created_at'=>$now]);
  $s=(new DigiForge\Portal\ShopOperationsReadModel())->snapshot('personalized_pod');self::assertFalse($s['catalog']['migration_metadata_valid']);self::assertFalse($s['catalog']['production_authority']);self::assertFalse($s['catalog']['promotion_authorized']);
 }
 public function testUnknownShopNormalizesToAllRatherThanInventingScope():void { self::assertSame(DigiForge\Portal\ShopOperationsReadModel::ALL,DigiForge\Portal\ShopOperationsReadModel::normalize('not-a-shop')); }
}
