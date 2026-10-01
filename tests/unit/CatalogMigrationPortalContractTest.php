<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class CatalogMigrationPortalContractTest extends TestCase {
 public function testReadModelForcesMigrationEvidenceNonAuthorizing():void{$s=(string)file_get_contents(__DIR__.'/../../includes/Portal/ShopOperationsReadModel.php');self::assertStringContainsString("'is_migration_candidate'", $s);self::assertStringContainsString("'migration_metadata_valid'", $s);self::assertStringContainsString("'production_authority']=false", $s);self::assertStringContainsString("'promotion_authorized']=false", $s);self::assertStringContainsString('WHERE catalog_key=%s',$s);foreach(['MasterCatalogV2Reference::SOURCE_SHA256','PersonalizedCatalogReference::CATALOG_KEY','MasterCatalogV2Reference::SOURCE_FILE'] as $n)self::assertStringContainsString($n,$s);}
 public function testPortalDistinguishesCandidateFromPromotion():void{$s=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');foreach(['Migration candidate','Promotion authorized','Lineage evidence','never replaces the immutable v1 baseline automatically','never grants Etsy publication or POD provider execution authority'] as $n)self::assertStringContainsString($n,$s);}
}
