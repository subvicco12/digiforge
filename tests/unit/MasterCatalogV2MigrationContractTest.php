<?php
declare(strict_types=1);
use DigiForge\POD\MasterCatalogV2MigrationContract;
use PHPUnit\Framework\TestCase;

final class MasterCatalogV2MigrationContractTest extends TestCase {
 private function master(int $i):array{$n=str_pad((string)$i,3,'0',STR_PAD_LEFT);return ['DG2-'.$n,'DG-'.$n,'Family','Concept '.$n,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','V1 retained','Re-score'];}
 private function migration(int $i):array{$n=str_pad((string)$i,3,'0',STR_PAD_LEFT);return ['DG-'.$n,'W1','Family','Concept '.$n,'PHOTO_TEXT','KEEP','Retain as active v2 concept.','DG2-'.$n,'No'];}
 private function rows(string $type):array{$out=[];for($i=1;$i<=500;$i++)$out[]=$type==='master'?$this->master($i):$this->migration($i);return $out;}
 public function testNormalizesCompleteOneToOneMigrationWithoutAuthority():void{$r=MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$this->rows('master'),MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));self::assertSame(500,$r['row_count']);self::assertCount(500,$r['migration']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$r['fingerprint']);self::assertFalse($r['production_authority']);self::assertFalse($r['promotion_authorized']);}
 public function testRejectsDuplicateV2Identity():void{$m=$this->rows('master');$m[499][0]='DG2-001';$this->expectException(InvalidArgumentException::class);MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$m,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));}
 public function testRejectsDuplicateSourceIdentity():void{$m=$this->rows('master');$m[499][1]='DG-001';$this->expectException(InvalidArgumentException::class);MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$m,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));}
 public function testRejectsCrossSheetLineageMismatch():void{$x=$this->rows('migration');$x[0][7]='DG2-002';$this->expectException(InvalidArgumentException::class);MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$this->rows('master'),MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$x);}
 public function testRejectsIncompleteCatalog():void{$m=$this->rows('master');array_pop($m);$this->expectException(InvalidArgumentException::class);MasterCatalogV2MigrationContract::normalize(MasterCatalogV2MigrationContract::MASTER_REQUIRED,$m,MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$this->rows('migration'));}
}
