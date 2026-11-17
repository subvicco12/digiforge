<?php
declare(strict_types=1);
final class MasterCatalogV2IngestionReadModelTest extends WP_UnitTestCase
{
 public function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();}
 private function insertParent():int{
  global $wpdb;$parent=PackageAcceptanceCatalogFixture::parent();self::assertSame(1,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::catalog_versions().' WHERE id=%d',(int)$parent['id'])));return (int)$parent['id'];
 }
 private function insertV2(int $parentId,array $override=[]):int{
  global $wpdb;$saved=(new DigiForge\POD\MasterCatalogV2IngestionService())->ingest(PackageAcceptanceCatalogFixture::candidate(),DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'acceptance-v2-'.wp_generate_uuid4());
  self::assertFalse(is_wp_error($saved),is_wp_error($saved)?$saved->get_error_message():'');self::assertSame($parentId,(int)$saved['parent_version_id']);$id=(int)$saved['id'];self::assertSame(1,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::catalog_versions().' WHERE id=%d',$id)));
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
 public function testMissingParentChildEvidenceStillFailsClosed():void{
  global $wpdb;$versions=DigiForge\Database\Tables::catalog_versions();
  $wpdb->query($wpdb->prepare('DELETE FROM '.$versions.' WHERE catalog_key IN (%s,%s)',DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY));
  $parentId=$this->insertParent();self::assertSame(1,$wpdb->delete(DigiForge\Database\Tables::catalog_items(),['catalog_version_id'=>$parentId,'listing_id'=>'DG-001']));
  $s=(new DigiForge\POD\MasterCatalogV2IngestionReadModel())->snapshot();self::assertSame('BLOCKED',$s['ingestion_readiness']);self::assertSame('PARENT_ITEMS_CONFLICT',$s['blocker']);self::assertFalse($s['external_execution_authorized']);
 }

}

/** Complete synthetic local evidence; no original-workbook or supplier certification. */
if (!class_exists('PackageAcceptanceCatalogFixture')) {
 final class PackageAcceptanceCatalogFixture {
  public static function parent():array {
   // Catalog ingestion commits; explicit cleanup is required between WP test transactions.
   global $wpdb;foreach([DigiForge\Database\Tables::catalog_acceptance_evidence(),DigiForge\Database\Tables::catalog_items(),DigiForge\Database\Tables::catalog_versions()] as $table)if($wpdb->query('DELETE FROM '.$table)===false)throw new RuntimeException('Catalog fixture isolation failed.');
   $rows=[];for($i=1;$i<=500;$i++)$rows[]=[sprintf('DG-%03d',$i),'W1','Family','Concept '.$i,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','','','','Offline fixture'];
   $normalized=DigiForge\POD\MasterCatalogImportContract::normalize(DigiForge\POD\MasterCatalogImportContract::REQUIRED,$rows);
   $saved=(new DigiForge\POD\GovernedCatalogRepository())->ingest($normalized,'acceptance-parent-'.wp_generate_uuid4(),DigiForge\POD\PersonalizedCatalogReference::SOURCE_SHA256);
   if(is_wp_error($saved))throw new RuntimeException($saved->get_error_message());return $saved;
  }
  public static function candidate():array {
   $masters=[];$migration=[];
   for($i=1;$i<=500;$i++){$n=sprintf('%03d',$i);$retained=$i<=428;$origin=$retained?'V1 retained':($i<=458?'V2 addition':'V2 new family');
    $masters[]=['DG2-'.$n,$retained?'DG-'.$n:'','Family','Concept '.$n,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH',$origin,$retained?'Re-score':($i<=458?'Deep research':'Provider + demand research')];
    $migration[]=['DG-'.$n,'W1','Family','Concept '.$n,'PHOTO_TEXT',$retained?'KEEP':($i<=465?'MERGE':'DOWNGRADE'),'Preserve source decision',$retained?'DG2-'.$n:'','No'];
   }
   return DigiForge\POD\MasterCatalogV2MigrationContract::normalize(DigiForge\POD\MasterCatalogV2MigrationContract::MASTER_REQUIRED,$masters,DigiForge\POD\MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$migration);
  }
 }
}
