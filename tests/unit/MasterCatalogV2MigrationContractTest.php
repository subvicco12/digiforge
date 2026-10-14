<?php
declare(strict_types=1);
use DigiForge\POD\MasterCatalogV2MigrationContract;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../support/MasterCatalogCandidateFixture.php';
final class MasterCatalogV2MigrationContractTest extends TestCase {
 private function master(int $i):array{$n=str_pad((string)$i,3,'0',STR_PAD_LEFT);return ['DG2-'.$n,'DG-'.$n,'Family','Concept '.$n,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','V1 retained','Re-score'];}
 private function migration(int $i):array{$n=str_pad((string)$i,3,'0',STR_PAD_LEFT);return ['DG-'.$n,'W1','Family','Concept '.$n,'PHOTO_TEXT','KEEP','Retain as active v2 concept.','DG2-'.$n,'No'];}
 private function rows(string $type):array{$out=[];for($i=1;$i<=500;$i++)$out[]=$type==='master'?$this->master($i):$this->migration($i);return $out;}
 public function testNormalizesCompleteOneToOneMigrationWithoutAuthority():void{$r=MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$this->rows('master'),MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));self::assertSame(500,$r['row_count']);self::assertCount(500,$r['migration']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$r['fingerprint']);self::assertFalse($r['production_authority']);self::assertFalse($r['promotion_authorized']);}
 public function testRejectsDuplicateV2Identity():void{$m=$this->rows('master');$m[499][0]='DG2-001';$this->expectException(InvalidArgumentException::class);MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$m,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));}
 public function testRejectsDuplicateSourceIdentity():void{$m=$this->rows('master');$m[499][1]='DG-001';$this->expectException(InvalidArgumentException::class);MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$m,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));}
 public function testRejectsCrossSheetLineageMismatch():void{$x=$this->rows('migration');$x[0][7]='DG2-002';$this->expectException(InvalidArgumentException::class);MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$this->rows('master'),MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$x);}
 public function testRejectsIncompleteCatalog():void{$m=$this->rows('master');array_pop($m);$this->expectException(InvalidArgumentException::class);MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$m,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));}
 public static function mixedRows():array {
  return MasterCatalogCandidateFixture::rows();
 }
 public function testMixedCandidatePreservesAllSourceDecisionsWithoutInventingLineage():void {
  [$master,$migration]=self::mixedRows();
  $r=MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$master,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$migration);
  self::assertCount(500,$r['rows']);self::assertCount(500,$r['migration']);
  self::assertSame('', $r['rows'][428]['Source DG ID']);
  self::assertSame('MERGE',$r['migration'][428]['Disposition']);
  self::assertSame('DOWNGRADE',$r['migration'][499]['Disposition']);
  self::assertFalse($r['production_authority']);self::assertFalse($r['promotion_authorized']);
 }
 public function testUnknownSourceDispositionIsRejected():void {
  $migration=$this->rows('migration');$migration[0][5]='APPROVED';
  $this->expectException(InvalidArgumentException::class);
  MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$this->rows('master'),MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$migration);
 }
 public function testUnknownRecommendedStageIsRejected():void {
  $master=$this->rows('master');$master[0][9]='AUTO_PUBLISH';
  $this->expectException(InvalidArgumentException::class);
  MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$master,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));
 }
 public function testMixedCandidateRejectsContradictoryOrAmbiguousEvidence():void {
  foreach(['retained_without_source','addition_with_source','unknown_origin','nonkeep_successor','missing_keep_successor','unknown_supplier','malformed_row','wrong_catalog','array_cell'] as $case){
   [$m,$x]=self::mixedRows();$key='digicraftifygoods-master-500-v2';
   switch($case){
    case 'retained_without_source':$m[0][1]='';break;
    case 'addition_with_source':$m[428][1]='DG-429';break;
    case 'unknown_origin':$m[428][8]='UNKNOWN';break;
    case 'nonkeep_successor':$x[428][7]='DG2-429';break;
    case 'missing_keep_successor':$x[0][7]='';break;
    case 'unknown_supplier':$m[0][6]='UNKNOWN';break;
    case 'malformed_row':array_pop($m[0]);break;
    case 'wrong_catalog':$key='another-catalog';break;
    case 'array_cell':$m[0][3]=['nested'];break;
   }
   try{MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$m,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$x,$key);self::fail('Accepted '.$case);}catch(InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}
  }
 }
}
