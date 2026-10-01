<?php
declare(strict_types=1);
final class MasterCatalogV2IngestionReadModelTest extends WP_UnitTestCase
{
 public function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();}
 private function insertParent():int{
  global $wpdb;$versions=DigiForge\Database\Tables::catalog_versions();
  $wpdb->insert($versions,['catalog_key'=>DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,'version_label'=>'v1-readiness-'.wp_rand(1000,9999),'source_sha256'=>DigiForge\POD\PersonalizedCatalogReference::SOURCE_SHA256,'source_state'=>'IMMUTABLE_REFERENCE','parent_version_id'=>0,'migration_metadata'=>wp_json_encode([]),'row_count'=>500,'fingerprint'=>hash('sha256','valid-parent-'.wp_rand()),'production_authority'=>0,'created_by'=>0,'created_at'=>current_time('mysql',true)]);
  self::assertSame(1,$wpdb->rows_affected);return (int)$wpdb->insert_id;
 }
 private function insertV2(int $parentId,array $override=[]):int{
  global $wpdb;$versions=DigiForge\Database\Tables::catalog_versions();$fingerprint=hash('sha256','v2-'.wp_rand());
  $meta=['source_state'=>'MIGRATION_CANDIDATE','parent_catalog_key'=>DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,'parent_version_id'=>$parentId,'source_file'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_FILE,'source_sha256'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'fingerprint'=>$fingerprint,'production_authority'=>false,'promotion_authorized'=>false];
  $row=['catalog_key'=>DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY,'version_label'=>'v2-readiness-'.wp_rand(1000,9999),'source_sha256'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'source_state'=>'MIGRATION_CANDIDATE','parent_version_id'=>$parentId,'migration_metadata'=>wp_json_encode($meta),'row_count'=>500,'fingerprint'=>$fingerprint,'production_authority'=>0,'created_by'=>0,'created_at'=>current_time('mysql',true)];
  $wpdb->insert($versions,array_merge($row,$override));self::assertSame(1,$wpdb->rows_affected);return (int)$wpdb->insert_id;
 }
 public function testReadinessFailsClosedWithoutExactParentAndBecomesReadyWithValidParent():void{
  global $wpdb;$versions=DigiForge\Database\Tables::catalog_versions();
  $wpdb->query($wpdb->prepare('DELETE FROM '.$versions.' WHERE catalog_key IN (%s,%s)',DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY));
  $model=new DigiForge\POD\MasterCatalogV2IngestionReadModel();$blocked=$model->snapshot();self::assertSame('BLOCKED',$blocked['ingestion_readiness']);
  $parentId=$this->insertParent();$ready=$model->snapshot();self::assertSame('READY_FOR_VALIDATED_INPUT',$ready['ingestion_readiness']);self::assertSame($parentId,$ready['parent_version_id']);self::assertFalse($ready['external_execution_authorized']);
 }
 public function testExactPersistedV2IsReportedWithoutGrantingAuthority():void{
  global $wpdb;$versions=DigiForge\Database\Tables::catalog_versions();$wpdb->query($wpdb->prepare('DELETE FROM '.$versions.' WHERE catalog_key IN (%s,%s)',DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY));
  $parentId=$this->insertParent();$v2Id=$this->insertV2($parentId);$s=(new DigiForge\POD\MasterCatalogV2IngestionReadModel())->snapshot();
  self::assertSame('ALREADY_PERSISTED',$s['ingestion_readiness']);self::assertSame($v2Id,$s['v2_version_id']);self::assertTrue($s['checks']['existing_v2_exact']);self::assertFalse($s['production_authority']);self::assertFalse($s['promotion_authorized']);self::assertFalse($s['external_execution_authorized']);
 }
 public function testFingerprintMismatchBetweenStoredRowAndMigrationMetadataFailsClosed():void{
  global $wpdb;$versions=DigiForge\Database\Tables::catalog_versions();$wpdb->query($wpdb->prepare('DELETE FROM '.$versions.' WHERE catalog_key IN (%s,%s)',DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY));
  $parentId=$this->insertParent();$this->insertV2($parentId,['fingerprint'=>hash('sha256','divergent-stored-fingerprint')]);$s=(new DigiForge\POD\MasterCatalogV2IngestionReadModel())->snapshot();
  self::assertSame('BLOCKED',$s['ingestion_readiness']);self::assertSame('EXISTING_V2_EVIDENCE_CONFLICT',$s['blocker']);self::assertFalse($s['checks']['existing_v2_exact']);self::assertFalse($s['external_execution_authorized']);
 }
 public function testConflictingPersistedV2FailsClosed():void{
  global $wpdb;$versions=DigiForge\Database\Tables::catalog_versions();$wpdb->query($wpdb->prepare('DELETE FROM '.$versions.' WHERE catalog_key IN (%s,%s)',DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY));
  $parentId=$this->insertParent();$this->insertV2($parentId,['source_sha256'=>hash('sha256','wrong-source')]);$s=(new DigiForge\POD\MasterCatalogV2IngestionReadModel())->snapshot();
  self::assertSame('BLOCKED',$s['ingestion_readiness']);self::assertSame('EXISTING_V2_EVIDENCE_CONFLICT',$s['blocker']);self::assertFalse($s['checks']['existing_v2_exact']);self::assertFalse($s['external_execution_authorized']);
 }
}
