<?php
declare(strict_types=1);
use DigiForge\POD\GovernedCatalogRepository;
use DigiForge\POD\MasterCatalogImportContract;
use DigiForge\POD\MasterCatalogV2MigrationContract;
use DigiForge\POD\MasterCatalogV2IngestionService;
use DigiForge\POD\MasterCatalogV2Reference;
use DigiForge\POD\PersonalizedCatalogReference;
/** Complete local V1 fixture; actual original V2 sheet data. No live supplier certification. */
final class MasterCatalogStoredEvidenceFixture {
 /** Ingestion commits, so WP test transaction rollback cannot isolate catalog fixtures. */
 public static function resetCatalogEvidence():void {
  global $wpdb;
  foreach([DigiForge\Database\Tables::catalog_acceptance_evidence(),DigiForge\Database\Tables::catalog_items(),DigiForge\Database\Tables::catalog_versions()] as $table){
   if($wpdb->query('DELETE FROM '.$table)===false)throw new RuntimeException('Catalog fixture isolation failed.');
  }
 }
 public static function parent():array {
  $rows=[];for($i=1;$i<=500;$i++)$rows[]=[sprintf('DG-%03d',$i),'W1','Local fixture family','Local fixture concept '.$i,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','','','','Offline parent contract fixture, not original V1 workbook certification.'];
  return MasterCatalogImportContract::normalize(MasterCatalogImportContract::REQUIRED,$rows);
 }
 public static function persistParent():array|WP_Error {
  return (new GovernedCatalogRepository())->ingest(self::parent(),'complete-v1-'.wp_generate_uuid4(),PersonalizedCatalogReference::SOURCE_SHA256);
 }
 public static function originalCandidate():array {
  $book=json_decode((string)file_get_contents(dirname(__DIR__,2).'/docs/engineering/original-source/workbook-original-complete.json'),true,32,JSON_THROW_ON_ERROR);
  $master=$book['Master_500_v2']['rows'];$migration=$book['Source_Migration_500']['rows'];$mh=array_shift($master);$xh=array_shift($migration);
  $convert=static fn(array $r):array=>array_map(static fn($v):string=>$v===null?'':(string)$v,$r);
  return MasterCatalogV2MigrationContract::normalize($convert(array_slice($mh,0,10)),array_map(static fn($r)=>$convert(array_slice($r,0,10)),$master),$convert(array_slice($xh,0,9)),array_map(static fn($r)=>$convert(array_slice($r,0,9)),$migration));
 }
 public static function persistV2(string $label=''):array|WP_Error {
  return (new MasterCatalogV2IngestionService())->ingest(self::originalCandidate(),MasterCatalogV2Reference::SOURCE_SHA256,$label!==''?$label:'complete-v2-'.wp_generate_uuid4());
 }
}
