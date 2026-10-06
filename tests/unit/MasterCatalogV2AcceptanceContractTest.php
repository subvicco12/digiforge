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
  foreach(['MIGRATION_CANDIDATE','MasterCatalogV2Reference::SOURCE_SHA256','MasterCatalogV2Reference::ROW_COUNT','PersonalizedCatalogReference::CATALOG_KEY','PersonalizedCatalogReference::SOURCE_SHA256','IMMUTABLE_REFERENCE','INSERT IGNORE','acknowledgement_hash',"'production_authority'=>false","'promotion_authorized'=>false","'external_execution_authorized'=>false","'external_execution_performed'=>false",'catalog_acceptance_readback_failed','already has different immutable human acceptance evidence'] as $n)self::assertStringContainsString($n,$s);
  foreach(['Etsy','Printify','Gelato','wp_remote_','production_authority=1'] as $n)self::assertStringNotContainsString($n,$s);
 }
 public function testPortalReadsExistingAcceptanceAndSuppressesConflictingControls():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(["->current((int)\$v2Ingestion['v2_version_id'])",'Human migration decision','Review actions are blocked until reconciliation','The decision is already recorded, so conflicting ACCEPT/REJECT controls are suppressed.','Promotion authority</span><b>NO'] as $n)self::assertStringContainsString($n,$s);
 }
}