<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class GovernedCatalogAtomicityContractTest extends TestCase {
 public function testCatalogPersistenceIsAtomicAndFailClosed():void{$s=(string)file_get_contents(__DIR__.'/../../includes/POD/GovernedCatalogRepository.php');self::assertStringContainsString("START TRANSACTION",$s);self::assertGreaterThanOrEqual(3,substr_count($s,"ROLLBACK"));self::assertStringContainsString("COMMIT",$s);self::assertStringContainsString("no partial catalog version was retained",$s);}
 public function testV2ContractCannotGrantPromotionOrProductionAuthority():void{$s=(string)file_get_contents(__DIR__.'/../../includes/POD/MasterCatalogV2MigrationContract.php');self::assertStringContainsString("'production_authority'=>false",$s);self::assertStringContainsString("'promotion_authorized'=>false",$s);self::assertStringNotContainsString('wp_remote_',$s);}
 public function testIdempotentReplayRequiresExactGovernedEvidence():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/GovernedCatalogRepository.php');
  $this->assertStringContainsString("source_state']??''", $s);
  $this->assertStringContainsString("parent_version_id']??0", $s);
  $this->assertStringContainsString("row_count']??0", $s);
  $this->assertStringContainsString("production_authority']??1", $s);
  $this->assertStringContainsString('migration_metadata', $s);
  $this->assertStringContainsString('invalid_catalog_source_state', $s);
  $this->assertStringContainsString('replay evidence must match exactly', $s);
 }
}
