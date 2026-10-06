<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class MasterCatalogV2AcceptanceContractTest extends TestCase {
 public function testV24IsAdditiveAndBootedOnActivationAndNormalUpgrade():void{
  $plan=(string)file_get_contents(__DIR__.'/../../includes/Database/MigrationPlan.php');self::assertStringContainsString('LATEST = 24',$plan);
  $schema=(string)file_get_contents(__DIR__.'/../../includes/Database/CatalogAcceptanceSchema.php');foreach(['VERSION=24','catalog_acceptance_evidence','UNIQUE KEY catalog_version','UNIQUE KEY acknowledgement_hash','CATALOG_ACCEPTANCE_SCHEMA_UPDATE_FAILED'] as $n)self::assertStringContainsString($n,$schema);
  foreach(['Plugin.php','Activator.php'] as $f){$s=(string)file_get_contents(__DIR__.'/../../includes/Core/'.$f);self::assertStringContainsString('CatalogAcceptanceSchema::migrateIfNeeded()',$s);}
 }
 public function testAcceptanceIsExactImmutableAndNonAuthorizing():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/MasterCatalogV2AcceptanceRepository.php');
  foreach(['MIGRATION_CANDIDATE','MasterCatalogV2Reference::SOURCE_SHA256','MasterCatalogV2Reference::ROW_COUNT','INSERT IGNORE','acknowledgement_hash',"'production_authority'=>false","'promotion_authorized'=>false","'external_execution_authorized'=>false",'catalog_acceptance_readback_failed','already has different immutable human acceptance evidence'] as $n)self::assertStringContainsString($n,$s);
  foreach(['Etsy','Printify','Gelato','wp_remote_','production_authority=1'] as $n)self::assertStringNotContainsString($n,$s);
 }
}