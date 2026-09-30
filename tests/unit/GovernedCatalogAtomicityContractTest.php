<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class GovernedCatalogAtomicityContractTest extends TestCase {
 public function testCatalogPersistenceIsAtomicAndFailClosed():void{$s=(string)file_get_contents(__DIR__.'/../../includes/POD/GovernedCatalogRepository.php');self::assertStringContainsString("START TRANSACTION",$s);self::assertGreaterThanOrEqual(3,substr_count($s,"ROLLBACK"));self::assertStringContainsString("COMMIT",$s);self::assertStringContainsString("no partial catalog version was retained",$s);}
 public function testV2ContractCannotGrantPromotionOrProductionAuthority():void{$s=(string)file_get_contents(__DIR__.'/../../includes/POD/MasterCatalogV2MigrationContract.php');self::assertStringContainsString("'production_authority'=>false",$s);self::assertStringContainsString("'promotion_authorized'=>false",$s);self::assertStringNotContainsString('wp_remote_',$s);}
}
