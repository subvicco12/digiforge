<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class GovernedCatalogStoredEvidenceTest extends TestCase
{
 protected function setUp():void {
  if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
  if(!class_exists('WP_Error'))eval('class WP_Error { public function __construct(private string $code,public string $message="",public mixed $data=null){} public function get_error_code():string{return $this->code;} }');
  if(!function_exists('current_time'))eval('function current_time($type,$utc=false){return "2026-10-11 00:00:00";}');
  if(!function_exists('is_wp_error'))eval('function is_wp_error($v){return $v instanceof WP_Error;}');
  require_once __DIR__.'/../../includes/POD/GovernedCatalogRepository.php';
  require_once __DIR__.'/../../includes/POD/MasterCatalogV2IngestionReadModel.php';
  require_once __DIR__.'/../../includes/POD/MasterCatalogV2AcceptanceRepository.php';
  $GLOBALS['wpdb']=new CatalogEvidenceFakeDb();$GLOBALS['wpdb']->parentItems=$this->parentItems();
 }
 public function testLookupFailureCannotStartIngestion():void {
  $GLOBALS['wpdb']->failHeader=true;
  $result=(new DigiForge\POD\GovernedCatalogRepository())->ingest($this->input(),'v2',DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,1,$this->metadata(),'MIGRATION_CANDIDATE');
  self::assertInstanceOf(WP_Error::class,$result);self::assertSame([],$GLOBALS['wpdb']->writes);
 }
 public function testBrowseFailureIsUnavailableNotEmpty():void {
  $GLOBALS['wpdb']->failItems=true;
  self::assertInstanceOf(WP_Error::class,(new DigiForge\POD\GovernedCatalogRepository())->browse(2));
 }
 public function testExactReplayRequiresActualCompleteRows():void {
  $db=$GLOBALS['wpdb'];$db->header=$this->header();$db->items=$this->items();array_pop($db->items);
  self::assertInstanceOf(WP_Error::class,(new DigiForge\POD\GovernedCatalogRepository())->ingest($this->input(),'v2',DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,1,$this->metadata(),'MIGRATION_CANDIDATE'));
  self::assertSame([],$db->writes);
 }
 public function testRehashedDifferentContentCannotReplayTheSameInput():void {
  $db=$GLOBALS['wpdb'];$db->header=$this->header();$db->items=$this->items();$db->items[0]['concept']='different';$p=json_decode($db->items[0]['attributes'],true);$p['concept']='different';$db->items[0]['attributes']=wp_json_encode($p);$db->items[0]['row_hash']=hash('sha256',$db->items[0]['attributes']);
  self::assertInstanceOf(WP_Error::class,(new DigiForge\POD\GovernedCatalogRepository())->ingest($this->input(),'v2',DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,1,$this->metadata(),'MIGRATION_CANDIDATE'));
 }
 public function testReadinessCannotTrustHeaderAfterChildDrift():void {
  $db=$GLOBALS['wpdb'];$db->parent=$this->parent();$db->header=$this->header(true);$db->items=$this->items();$db->items[0]['attributes']='{}';
  $result=(new DigiForge\POD\MasterCatalogV2IngestionReadModel())->snapshot();self::assertSame('BLOCKED',$result['ingestion_readiness']);
 }
 public function testAcceptanceCannotTrustHeaderAfterChildLoss():void {
  $db=$GLOBALS['wpdb'];$db->parent=$this->parent();$db->header=$this->header(true);$db->items=$this->items();array_pop($db->items);
  self::assertInstanceOf(WP_Error::class,(new DigiForge\POD\MasterCatalogV2AcceptanceRepository())->current(2));
  self::assertInstanceOf(WP_Error::class,(new DigiForge\POD\MasterCatalogV2AcceptanceRepository())->record(2,'ACCEPT',1));self::assertSame([],$db->writes);
 }
 public function testExactValidReplayRetainsNonAuthority():void {
  $db=$GLOBALS['wpdb'];$db->header=$this->header();$db->items=$this->items();$result=(new DigiForge\POD\GovernedCatalogRepository())->ingest($this->input(),'v2',DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,1,$this->metadata(),'MIGRATION_CANDIDATE');self::assertIsArray($result);self::assertTrue($result['idempotent_replay']);self::assertSame(0,$result['production_authority']);
 }
 public function testItemReadFailureBlocksReplayWithoutWriting():void {
  $db=$GLOBALS['wpdb'];$db->header=$this->header();$db->failItems=true;
  $result=(new DigiForge\POD\GovernedCatalogRepository())->ingest($this->input(),'v2',DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,1,$this->metadata(),'MIGRATION_CANDIDATE');self::assertInstanceOf(WP_Error::class,$result);self::assertSame('catalog_evidence_unavailable',$result->get_error_code());self::assertSame([],$db->writes);
 }
 public function testItemReadFailureBlocksReadinessAndAcceptance():void {
  $db=$GLOBALS['wpdb'];$db->parent=$this->parent();$db->header=$this->header(true);$db->failItems=true;
  $readiness=(new DigiForge\POD\MasterCatalogV2IngestionReadModel())->snapshot();self::assertSame('UNAVAILABLE',$readiness['query_state']);self::assertSame('BLOCKED',$readiness['ingestion_readiness']);
  $result=(new DigiForge\POD\MasterCatalogV2AcceptanceRepository())->record(2,'ACCEPT',1);self::assertInstanceOf(WP_Error::class,$result);self::assertSame('catalog_evidence_unavailable',$result->get_error_code());self::assertSame([],$db->writes);
 }
 public function testCanonicalAttributesCannotDisagreeWithIndexedColumns():void {
  $db=$GLOBALS['wpdb'];$db->items=$this->items();$db->items[0]['engine']='OTHER';self::assertInstanceOf(WP_Error::class,DigiForge\POD\GovernedCatalogRepository::verifyStoredItems(2));
 }
 public function testDuplicateIdentityCannotSupplyTheAdvertisedRowCount():void {
  $db=$GLOBALS['wpdb'];$db->items=$this->items();$db->items[1]=$db->items[0];self::assertInstanceOf(WP_Error::class,DigiForge\POD\GovernedCatalogRepository::verifyStoredItems(2));
 }
 public function testNonCanonicalJsonCannotUseARecomputedHash():void {
  $db=$GLOBALS['wpdb'];$db->items=$this->items();$db->items[0]['attributes']=json_encode(json_decode($db->items[0]['attributes'],true),JSON_PRETTY_PRINT);$db->items[0]['row_hash']=hash('sha256',$db->items[0]['attributes']);self::assertInstanceOf(WP_Error::class,DigiForge\POD\GovernedCatalogRepository::verifyStoredItems(2));
 }
 public function testCompleteStoredEvidenceRetainsPendingHumanDecisionAndNoAuthority():void {
  $db=$GLOBALS['wpdb'];$db->parent=$this->parent();$db->header=$this->header(true);$db->items=$this->items();
  $ready=(new DigiForge\POD\MasterCatalogV2IngestionReadModel())->snapshot();self::assertSame('ALREADY_PERSISTED',$ready['ingestion_readiness']);self::assertFalse($ready['production_authority']);self::assertFalse($ready['promotion_authorized']);
  $current=(new DigiForge\POD\MasterCatalogV2AcceptanceRepository())->current(2);self::assertIsArray($current);self::assertSame('PENDING',$current['decision_state']);self::assertFalse($current['external_execution_authorized']);self::assertSame([],$db->writes);
 }
 public function testRehashedContentCannotRetainHeaderFingerprintReadiness():void {
  $db=$GLOBALS['wpdb'];$db->parent=$this->parent();$db->header=$this->header(true);$db->items=$this->items();$payload=json_decode($db->items[0]['attributes'],true);$payload['concept']='changed but rehashed';$db->items[0]['concept']=$payload['concept'];$db->items[0]['attributes']=wp_json_encode($payload);$db->items[0]['row_hash']=hash('sha256',$db->items[0]['attributes']);
  $result=(new DigiForge\POD\MasterCatalogV2IngestionReadModel())->snapshot();self::assertSame('BLOCKED',$result['ingestion_readiness']);
 }
 public function testActualOriginalWorkbookChildrenReconstructExactNormalizedFingerprint():void {
  require_once __DIR__.'/../support/MasterCatalogStoredEvidenceFixture.php';require_once __DIR__.'/../../includes/POD/MasterCatalogV2IngestionService.php';
  $n=MasterCatalogStoredEvidenceFixture::originalCandidate();$map=new ReflectionMethod(DigiForge\POD\MasterCatalogV2IngestionService::class,'mapRows');$mapped=$map->invoke(new DigiForge\POD\MasterCatalogV2IngestionService(),$n);self::assertIsArray($mapped);
  $payloadMethod=new ReflectionMethod(DigiForge\POD\GovernedCatalogRepository::class,'rowPayload');$items=[];foreach($mapped as $r){$p=$payloadMethod->invoke(null,$r);$json=wp_json_encode($p);$items[]=array_merge($p,['catalog_version_id'=>2,'listing_id'=>$r['Listing ID'],'attributes'=>$json,'row_hash'=>hash('sha256',$json)]);}
  $db=$GLOBALS['wpdb'];$db->items=$items;$v=$this->header();$v['fingerprint']=$n['fingerprint'];$meta=$this->metadata();$meta['source_migration']=$n['migration'];$meta['fingerprint']=$n['fingerprint'];$v['migration_metadata']=wp_json_encode($meta);
  self::assertCount(500,DigiForge\POD\GovernedCatalogRepository::verifyVersionEvidence($v));$p=json_decode($db->items[0]['attributes'],true);$p['concept']='rehashed drift';$db->items[0]['concept']=$p['concept'];$db->items[0]['attributes']=wp_json_encode($p);$db->items[0]['row_hash']=hash('sha256',$db->items[0]['attributes']);self::assertInstanceOf(WP_Error::class,DigiForge\POD\GovernedCatalogRepository::verifyVersionEvidence($v));
 }
 public function testV2IngestionCannotReplayThroughRehashedCorruptParent():void {
  require_once __DIR__.'/../../includes/POD/MasterCatalogV2IngestionService.php';$db=$GLOBALS['wpdb'];$db->parent=$this->parent();$db->header=$this->header();$db->items=$this->items();$p=json_decode($db->parentItems[0]['attributes'],true);$p['concept']='parent drift';$db->parentItems[0]['concept']=$p['concept'];$db->parentItems[0]['attributes']=wp_json_encode($p);$db->parentItems[0]['row_hash']=hash('sha256',$db->parentItems[0]['attributes']);
  $result=(new DigiForge\POD\MasterCatalogV2IngestionService())->ingest($this->normalized(),DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'v2');self::assertInstanceOf(WP_Error::class,$result);self::assertSame([],$db->writes);
 }
 public function testStoredSourceOrderReconstructsAnUnsortedNormalizedParent():void {
  $db=$GLOBALS['wpdb'];$v=$this->parent();$n=$this->parentInput();$rows=$n['rows'];$first=array_shift($rows);$rows[]=$first;$normalized=DigiForge\POD\MasterCatalogImportContract::normalize(DigiForge\POD\MasterCatalogImportContract::REQUIRED,array_map('array_values',$rows));$v['fingerprint']=$normalized['fingerprint'];$v['migration_metadata']=wp_json_encode(['catalog_item_order'=>array_column($rows,'Listing ID')]);
  $result=DigiForge\POD\GovernedCatalogRepository::verifyVersionEvidence($v);self::assertIsArray($result);self::assertCount(500,$result);
 }
 private function normalized():array {
  $master=[];$migration=[];for($i=1;$i<=500;$i++){$n=sprintf('%03d',$i);$master[]=['DG2-'.$n,'DG-'.$n,'Family','Concept','FACE_REPEAT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','V1 retained','Re-score'];$migration[]=['DG-'.$n,'W1','Family','Concept','FACE_REPEAT','KEEP','','DG2-'.$n,'No'];}
  return DigiForge\POD\MasterCatalogV2MigrationContract::normalize(DigiForge\POD\MasterCatalogV2MigrationContract::MASTER_REQUIRED,$master,DigiForge\POD\MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$migration);
 }
 private function metadata():array {
  $n=$this->normalized();return ['source_migration'=>$n['migration'],'source_state'=>'MIGRATION_CANDIDATE','parent_catalog_key'=>DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,'parent_version_id'=>1,'source_file'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_FILE,'source_sha256'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'fingerprint'=>$n['fingerprint'],'production_authority'=>false,'promotion_authorized'=>false];
 }
 private function input():array {
  $n=$this->normalized();$rows=[];foreach($n['rows']as $r){$rows[]=['Listing ID'=>$r['V2 ID'],'Family'=>$r['Family'],'Concept'=>$r['Concept'],'Engine'=>$r['Engine'],'Physical Product'=>$r['Physical Product'],'Supplier Gate'=>$r['Supplier Gate'],'Template State'=>$r['Template State'],'Wave'=>'W1','US Route'=>'','EU Route'=>'','Priority'=>'','Notes'=>'','V2 Evidence'=>['source_dg_id'=>$r['Source DG ID'],'origin'=>$r['Origin'],'recommended_stage'=>$r['Recommended Stage'],'v1_family'=>'Family','v1_concept'=>'Concept','migration_engine'=>'FACE_REPEAT','disposition'=>'KEEP','migration_note'=>'','retain_as_overlay_test'=>'No']];}return ['catalog_key'=>$n['catalog_key'],'fingerprint'=>$n['fingerprint'],'rows'=>$rows];
 }
 private function items():array {
  $items=[];foreach($this->input()['rows']as $row){$p=['family'=>$row['Family'],'concept'=>$row['Concept'],'engine'=>$row['Engine'],'physical_product'=>$row['Physical Product'],'supplier_gate'=>$row['Supplier Gate'],'template_state'=>$row['Template State'],'wave'=>$row['Wave'],'us_route'=>'','eu_route'=>'','priority'=>'','notes'=>'','lineage'=>$row['V2 Evidence']];$json=wp_json_encode($p);$items[]=array_merge($p,['catalog_version_id'=>2,'listing_id'=>$row['Listing ID'],'attributes'=>$json,'row_hash'=>hash('sha256',$json)]);}return $items;
 }
 private function parentInput():array {
  $rows=[];for($i=1;$i<=500;$i++)$rows[]=[sprintf('DG-%03d',$i),'W1','Family','Concept','FACE_REPEAT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','','','',''];return DigiForge\POD\MasterCatalogImportContract::normalize(DigiForge\POD\MasterCatalogImportContract::REQUIRED,$rows);
 }
 private function parentItems():array {
  $items=[];foreach($this->parentInput()['rows']as $r){$p=['family'=>$r['Family'],'concept'=>$r['Concept'],'engine'=>$r['Engine'],'physical_product'=>$r['Physical Product'],'supplier_gate'=>$r['Supplier Gate'],'template_state'=>$r['Template State'],'wave'=>$r['Wave'],'us_route'=>'','eu_route'=>'','priority'=>'','notes'=>'','lineage'=>[]];$json=wp_json_encode($p);$items[]=array_merge($p,['catalog_version_id'=>1,'listing_id'=>$r['Listing ID'],'attributes'=>$json,'row_hash'=>hash('sha256',$json)]);}return $items;
 }
 private function parent():array{return ['id'=>1,'catalog_key'=>DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,'source_sha256'=>DigiForge\POD\PersonalizedCatalogReference::SOURCE_SHA256,'source_state'=>'IMMUTABLE_REFERENCE','row_count'=>500,'fingerprint'=>$this->parentInput()['fingerprint'],'production_authority'=>0];}
 private function header(bool $v2=false):array {
  return ['id'=>2,'catalog_key'=>DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY,'source_sha256'=>DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'source_state'=>'MIGRATION_CANDIDATE','parent_version_id'=>1,'row_count'=>500,'fingerprint'=>$this->normalized()['fingerprint'],'production_authority'=>0,'migration_metadata'=>wp_json_encode($this->metadata())];
 }

}
final class CatalogEvidenceFakeDb {
 public string $prefix='wp_';public string $last_error='';public bool $failHeader=false,$failItems=false;public array $header=[],$parent=[],$items=[],$parentItems=[],$writes=[];
 public function prepare(string $sql,...$args):string{return $sql." /*".implode(",",$args)."*/";}
 public function get_row(string $sql,$format):?array {if($this->failHeader){$this->last_error='failed';return null;}if(str_contains($sql,'catalog_acceptance_evidence'))return null;return str_contains($sql,'IMMUTABLE_REFERENCE')||str_contains($sql,'/*1*/')?$this->parent:($this->header?:null);}
 public function get_results(string $sql,$format):?array {if($this->failItems){$this->last_error='failed';return null;}if(str_contains($sql,'/*1*/'))return $this->parentItems;return $this->items;}
 public function query(string $sql):bool{$this->writes[]=$sql;return false;}
}
