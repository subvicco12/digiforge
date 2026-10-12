<?php
declare(strict_types=1);
final class FourShopDataIsolationTest extends WP_UnitTestCase {
 protected function setUp():void { parent::setUp(); DigiForge\Core\Activator::activate(); }
 public function testOperationalProjectionDoesNotLeakShopPolicyOrUsageAcrossFourShops():void {
  global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);$shops=['digital','personalized_pod','standard_pod','jewelry'];$fixtures=[];$key='isolation-'.wp_rand();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  foreach($shops as $i=>$shop){
   $policy=(new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$shop,'currency'=>'USD','marker'=>$key.'-'.$shop,'stages'=>['research'=>['limit'=>$i+1]]],'production');self::assertNotWPError($policy);
   $workflow=$key.'-'.$shop;self::assertSame(1,$wpdb->insert($t::shop_ai_usage(),['shop_key'=>$shop,'workflow'=>$workflow,'run_id'=>$key.'-'.$shop,'run_started_at'=>$now,'stage'=>'research','model_key'=>$key,'quantity'=>$i+1,'estimated_cost'=>'0.100000','actual_cost'=>'0.090000','currency'=>'USD','occurred_at'=>$now]));
   $fixtures[$shop]=['policy_id'=>(int)$policy['id'],'policy_hash'=>$policy['policy_hash'],'usage_id'=>(int)$wpdb->insert_id,'workflow'=>$workflow,'quantity'=>$i+1];
  }
  $rm=new DigiForge\Portal\ShopOperationsReadModel();
  foreach($shops as $shop){
   $s=$rm->snapshot($shop);self::assertSame($shop,$s['shop']);self::assertSame('AVAILABLE',$s['query_state']['ai_policies']);self::assertSame('AVAILABLE',$s['query_state']['ai_usage']);self::assertNotEmpty($s['ai_policies']);self::assertNotEmpty($s['ai_usage']);
   foreach($s['ai_policies']as $row)self::assertSame($shop,$row['shop_key']);foreach($s['ai_usage']as $row)self::assertSame($shop,$row['shop_key']);
   self::assertContains($fixtures[$shop]['policy_hash'],array_column($s['ai_policies'],'policy_hash'));self::assertSame((string)$fixtures[$shop]['policy_id'],(string)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$t::shop_ai_policies().' WHERE shop_key=%s AND policy_hash=%s',$shop,$fixtures[$shop]['policy_hash'])));
   $usage=array_values(array_filter($s['ai_usage'],static fn(array $row):bool=>$row['workflow']===$fixtures[$shop]['workflow']));self::assertCount(1,$usage);self::assertSame($fixtures[$shop]['quantity'],(int)$usage[0]['quantity']);self::assertSame($key,$usage[0]['model_key']);self::assertSame($shop,$wpdb->get_var($wpdb->prepare('SELECT shop_key FROM '.$t::shop_ai_usage().' WHERE id=%d',$fixtures[$shop]['usage_id'])));
   foreach($fixtures as $otherShop=>$fixture)if($otherShop!==$shop){self::assertNotContains($fixture['policy_hash'],array_column($s['ai_policies'],'policy_hash'));self::assertNotContains($fixture['workflow'],array_column($s['ai_usage'],'workflow'));}self::assertFalse($s['external_execution_performed']);
  }
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
