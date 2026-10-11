<?php
declare(strict_types=1);
require_once __DIR__.'/PortalActionAcceptanceFixture.php';
require_once __DIR__.'/../support/MasterCatalogStoredEvidenceFixture.php';
use DigiForge\Database\Tables;
use DigiForge\POD\GovernedCatalogRepository;
use DigiForge\POD\MasterCatalogV2AcceptanceRepository;
use DigiForge\POD\MasterCatalogV2IngestionReadModel;
use DigiForge\POD\MasterCatalogV2IngestionService;
use DigiForge\POD\MasterCatalogV2Reference;
use DigiForge\POD\PersonalizedCatalogReference;
/** Original §13 migration and §32 local catalog acceptance; no promotion or external calls. */
final class MasterCatalogStoredSourceAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  MasterCatalogStoredEvidenceFixture::resetCatalogEvidence();
 }
 private function persist():array {
  $parent=MasterCatalogStoredEvidenceFixture::persistParent();self::assertNotWPError($parent);$label='original-v2-'.wp_generate_uuid4();$candidate=MasterCatalogStoredEvidenceFixture::persistV2($label);self::assertNotWPError($candidate);return [$parent,$candidate,$label];
 }
 public function testOriginalWorkbookMigrationPersistsReplaysAndAcceptsExactCompleteEvidenceOnly():void {
  $http=[];$guard=static function($pre,$args,$url)use(&$http){$http[]=$url;return new WP_Error('offline_catalog_acceptance','External HTTP forbidden.');};add_filter('pre_http_request',$guard,10,3);
  try{
   [$parent,$v2,$label]=$this->persist();$input=MasterCatalogStoredEvidenceFixture::originalCandidate();self::assertSame($parent['id'],$v2['parent_version_id']);self::assertSame($input['fingerprint'],$v2['fingerprint']);self::assertCount(500,$input['migration']);
   self::assertCount(500,GovernedCatalogRepository::verifyVersionEvidence($parent));self::assertCount(500,GovernedCatalogRepository::verifyVersionEvidence($v2));
   $meta=json_decode($v2['migration_metadata'],true);self::assertSame($input['migration'],$meta['source_migration']);self::assertSame(['KEEP','MERGE','DOWNGRADE'],array_values(array_unique(array_column($meta['source_migration'],'Disposition'))));
   $readiness=(new MasterCatalogV2IngestionReadModel())->snapshot();self::assertSame('ALREADY_PERSISTED',$readiness['ingestion_readiness']);self::assertFalse($readiness['promotion_authorized']);
   $acceptance=new MasterCatalogV2AcceptanceRepository();self::assertSame('PENDING',$acceptance->current((int)$v2['id'])['decision_state']);PortalActionAcceptanceFixture::invoke('MASTER500_ACCEPTANCE_ACTION',['catalog_version_id'=>$v2['id'],'decision'=>'ACCEPT']);$decision=$acceptance->record((int)$v2['id'],'ACCEPT',get_current_user_id());self::assertNotWPError($decision);self::assertFalse($decision['external_execution_authorized']);self::assertFalse($decision['production_authority']);self::assertFalse($decision['promotion_authorized']);
   self::assertTrue($acceptance->record((int)$v2['id'],'ACCEPT',get_current_user_id())['replayed']);self::assertWPError($acceptance->record((int)$v2['id'],'REJECT',get_current_user_id()));self::assertSame('RECORDED',$acceptance->current((int)$v2['id'])['decision_state']);
   $replay=(new MasterCatalogV2IngestionService())->ingest($input,MasterCatalogV2Reference::SOURCE_SHA256,$label);self::assertNotWPError($replay);self::assertTrue($replay['idempotent_replay']);self::assertSame($v2['id'],$replay['id']);self::assertSame([],$http);self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
  }finally{remove_filter('pre_http_request',$guard,10);}
 }
 public function testMissingCandidateChildBlocksReplayReadinessAndPreviouslyRecordedAcceptance():void {
  global $wpdb;[$parent,$v2,$label]=$this->persist();$id=(int)$v2['id'];$acceptance=new MasterCatalogV2AcceptanceRepository();self::assertNotWPError($acceptance->record($id,'ACCEPT',get_current_user_id()));
  self::assertSame(1,$wpdb->delete(Tables::catalog_items(),['catalog_version_id'=>$id,'listing_id'=>'DG2-001']));
  self::assertSame('BLOCKED',(new MasterCatalogV2IngestionReadModel())->snapshot()['ingestion_readiness']);self::assertWPError($acceptance->current($id));self::assertWPError($acceptance->record($id,'ACCEPT',get_current_user_id()));self::assertWPError((new MasterCatalogV2IngestionService())->ingest(MasterCatalogStoredEvidenceFixture::originalCandidate(),MasterCatalogV2Reference::SOURCE_SHA256,$label));
 }
 public function testCanonicalRehashedChildDriftCannotPreserveTheHeaderFingerprint():void {
  global $wpdb;[$parent,$v2,$label]=$this->persist();$id=(int)$v2['id'];$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_items().' WHERE catalog_version_id=%d AND listing_id=%s',$id,'DG2-001'),ARRAY_A);$p=json_decode($row['attributes'],true);$p['concept']='Different product concept';$json=wp_json_encode($p);
  self::assertSame(1,$wpdb->update(Tables::catalog_items(),['concept'=>$p['concept'],'attributes'=>$json,'row_hash'=>hash('sha256',$json)],['id'=>(int)$row['id']]));
  self::assertSame('BLOCKED',(new MasterCatalogV2IngestionReadModel())->snapshot()['ingestion_readiness']);self::assertWPError((new MasterCatalogV2AcceptanceRepository())->current($id));self::assertWPError((new MasterCatalogV2AcceptanceRepository())->record($id,'ACCEPT',get_current_user_id()));
 }
 public function testStoredItemSqlFailureIsUnavailableAndNeverAnEmptySuccessfulCatalog():void {
  global $wpdb;[$parent,$v2,$label]=$this->persist();$table=Tables::catalog_items();$guard=static fn(string $sql):string=>str_starts_with(ltrim($sql),'SELECT')&&str_contains($sql,$table)?str_replace($table,$table.'_offline_missing',$sql):$sql;$previous=$wpdb->suppress_errors(true);add_filter('query',$guard);
  try{
   $result=(new GovernedCatalogRepository())->browse((int)$v2['id']);self::assertWPError($result);self::assertSame('catalog_evidence_unavailable',$result->get_error_code());$readiness=(new MasterCatalogV2IngestionReadModel())->snapshot();self::assertSame('UNAVAILABLE',$readiness['query_state']);self::assertSame('BLOCKED',$readiness['ingestion_readiness']);self::assertWPError((new MasterCatalogV2AcceptanceRepository())->current((int)$v2['id']));self::assertWPError((new MasterCatalogV2IngestionService())->ingest(MasterCatalogStoredEvidenceFixture::originalCandidate(),MasterCatalogV2Reference::SOURCE_SHA256,$label));
  }finally{remove_filter('query',$guard);$wpdb->suppress_errors($previous);}
 }
}
