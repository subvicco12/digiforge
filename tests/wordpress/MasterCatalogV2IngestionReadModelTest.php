<?php
declare(strict_types=1);
final class MasterCatalogV2IngestionReadModelTest extends WP_UnitTestCase
{
 public function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();}
 public function testReadinessFailsClosedWithoutExactParentAndBecomesReadyWithValidParent():void{
  global $wpdb;$versions=DigiForge\Database\Tables::catalog_versions();
  $wpdb->query($wpdb->prepare('DELETE FROM '.$versions.' WHERE catalog_key=%s',DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY));
  $model=new DigiForge\POD\MasterCatalogV2IngestionReadModel();
  $blocked=$model->snapshot();self::assertSame('BLOCKED',$blocked['ingestion_readiness']);self::assertFalse($blocked['production_authority']);self::assertFalse($blocked['promotion_authorized']);
  $wpdb->insert($versions,['catalog_key'=>DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,'version_label'=>'v1-readiness-test','source_sha256'=>DigiForge\POD\PersonalizedCatalogReference::SOURCE_SHA256,'source_state'=>'IMMUTABLE_REFERENCE','parent_version_id'=>0,'migration_metadata'=>wp_json_encode([]),'row_count'=>500,'fingerprint'=>hash('sha256','valid-parent'),'production_authority'=>0,'created_by'=>0,'created_at'=>current_time('mysql',true)]);
  self::assertSame(1,$wpdb->rows_affected);
  $ready=$model->snapshot();self::assertSame('READY_FOR_VALIDATED_INPUT',$ready['ingestion_readiness']);self::assertGreaterThan(0,$ready['parent_version_id']);self::assertFalse($ready['production_authority']);self::assertFalse($ready['promotion_authorized']);self::assertFalse($ready['external_execution_authorized']);
 }
}
