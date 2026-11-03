<?php
declare(strict_types=1);
require_once __DIR__.'/../support/MasterCatalogStoredEvidenceFixture.php';
final class MasterCatalogV2IngestionReadModelTest extends WP_UnitTestCase
{
 public function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();MasterCatalogStoredEvidenceFixture::resetCatalogEvidence();}
 private function insertParent():int{
  $parent=MasterCatalogStoredEvidenceFixture::persistParent();self::assertNotWPError($parent);return (int)$parent['id'];
 }
 private function insertV2(int $parentId,array $override=[]):int{
  global $wpdb;$v2=MasterCatalogStoredEvidenceFixture::persistV2();self::assertNotWPError($v2);self::assertSame($parentId,(int)$v2['parent_version_id']);$id=(int)$v2['id'];
  if($override!==[])self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::catalog_versions(),$override,['id'=>$id]));return $id;
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
