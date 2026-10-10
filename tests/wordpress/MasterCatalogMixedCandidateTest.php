<?php
declare(strict_types=1);
require_once __DIR__.'/../support/MasterCatalogCandidateFixture.php';
use DigiForge\POD\MasterCatalogV2MigrationContract as Contract;
use DigiForge\POD\MasterCatalogV2IngestionService as Service;
use DigiForge\POD\MasterCatalogV2Reference as Reference;
use DigiForge\POD\PersonalizedCatalogReference as ParentReference;
use DigiForge\Database\Tables;
final class MasterCatalogMixedCandidateTest extends WP_UnitTestCase {
 public function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  global $wpdb;
  $wpdb->insert(Tables::catalog_versions(),['catalog_key'=>ParentReference::CATALOG_KEY,'version_label'=>'mixed-parent-'.wp_rand(),'source_sha256'=>ParentReference::SOURCE_SHA256,'source_state'=>'IMMUTABLE_REFERENCE','parent_version_id'=>0,'migration_metadata'=>'{}','row_count'=>500,'fingerprint'=>hash('sha256','mixed-parent-'.wp_rand()),'production_authority'=>0,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)]);
  self::assertSame(1,$wpdb->rows_affected);
 }
 private function candidate():array {
  [$m,$x]=MasterCatalogCandidateFixture::rows();
  return Contract::normalize(Contract::MASTER_REQUIRED,$m,Contract::MIGRATION_REQUIRED,$x);
 }
 public function testMixedCandidatePersistsAllDispositionsAndReplaysExactEvidence():void {
  global $wpdb;$service=new Service();$candidate=$this->candidate();$label='mixed-candidate-'.wp_rand();
  $saved=$service->ingest($candidate,Reference::SOURCE_SHA256,$label);
  self::assertFalse(is_wp_error($saved),is_wp_error($saved)?$saved->get_error_message():'');
  $id=(int)$saved['id'];
  self::assertSame(500,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::catalog_items().' WHERE catalog_version_id=%d',$id)));
  $meta=json_decode($saved['migration_metadata'],true);
  self::assertCount(500,$meta['source_migration']);
  self::assertSame('MERGE',$meta['source_migration'][428]['Disposition']);
  self::assertSame('DOWNGRADE',$meta['source_migration'][499]['Disposition']);
  $addition=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_items().' WHERE catalog_version_id=%d AND listing_id=%s',$id,'DG2-429'),ARRAY_A);
  $attributes=json_decode($addition['attributes'],true);
  self::assertSame('',$attributes['lineage']['source_dg_id']);self::assertSame('V2 addition',$attributes['lineage']['origin']);
  self::assertSame(0,(int)$saved['production_authority']);self::assertFalse($meta['promotion_authorized']);
  $replay=$service->ingest($candidate,Reference::SOURCE_SHA256,$label);
  self::assertFalse(is_wp_error($replay));self::assertTrue($replay['idempotent_replay']);
  $candidate['migration'][428]['Migration Note']='Changed exclusion decision';
  $candidate['fingerprint']=Contract::normalize(Contract::MASTER_REQUIRED,array_map('array_values',$candidate['rows']),Contract::MIGRATION_REQUIRED,array_map('array_values',$candidate['migration']))['fingerprint'];
  $conflict=$service->ingest($candidate,Reference::SOURCE_SHA256,$label);
  self::assertTrue(is_wp_error($conflict));self::assertSame('immutable_version_conflict',$conflict->get_error_code());
 }
 public function testForgedNormalizedEvidenceIsRejectedBeforePersistence():void {
  global $wpdb;$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::catalog_versions());
  $candidate=$this->candidate();$candidate['rows'][0]['Concept']='Tampered after normalization';
  $result=(new Service())->ingest($candidate,Reference::SOURCE_SHA256,'forged-'.wp_rand());
  self::assertTrue(is_wp_error($result));self::assertSame('v2_contract_invalid',$result->get_error_code());
  self::assertSame($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::catalog_versions()));
 }
}
