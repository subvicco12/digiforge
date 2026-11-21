<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionTemplateContractStructureTest extends TestCase
{
    public function testContractLocksSupplierGeometryAndPersonalization(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php');
        self::assertIsString($source);
        foreach(['provider_blueprint_id','provider_id','variant_ids','print_areas','width_px','height_px','decoration_method','PRINTIFY_NATIVE','DIGIFORGE_RENDER','DIGIFORGE_AI'] as $needle) self::assertStringContainsString($needle,$source);
        self::assertStringContainsString("hash('sha256'",$source);
    }

    public function testCanonicalIdentityRejectsDuplicateAreasAndOrderDrift(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php');
        self::assertStringContainsString("strtolower(self::token(\$input['supplier']",$source);
        self::assertStringContainsString("strtolower(self::token(\$area['position']",$source);
        self::assertStringContainsString("strtolower(self::token(\$area['decoration_method']",$source);
        self::assertStringContainsString('duplicate normalized print_area identity',$source);
        self::assertStringContainsString('usort($normalizedAreas',$source);
        self::assertStringContainsString('fingerprint encoding failed',$source);
    }

    public function testPrintifyBridgeRequiresNormalizedCatalogIdentityAndExplicitGeometry(): void
    {
        require_once __DIR__.'/../fixtures/PrintifyGeometryFixture.php';
        $row=PrintifyGeometryFixture::catalog(321,77,88);
        $t=PrintifyGeometryFixture::candidate($row,'documented-bridge');
        self::assertSame('printify',$t['supplier']);self::assertSame(321,$t['provider_blueprint_id']);
        self::assertSame(77,$t['provider_id']);self::assertSame([88],$t['variant_ids']);
        self::assertSame(1200,$t['print_areas'][0]['width_px']);
        self::assertSame($row['attributes']['_digiforge_source_evidence']['variant_sha256'],$t['print_areas'][0]['_catalog_bindings'][0]['variant_sha256']);
        $this->expectException(\InvalidArgumentException::class);
        \DigiForge\POD\ProductionTemplateContract::fromPrintifyCatalog($row,$t['print_areas'],['template_id'=>'override','template_version'=>1,'supplier'=>'other','personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM']);
    }

    public function testRepositoryVersionsDriftWithoutMutatingExistingTemplates(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateRepository.php');
        self::assertIsString($source);
        self::assertStringContainsString('createDriftCandidate(array $input)',$source);
        self::assertStringContainsString("ORDER BY template_version DESC LIMIT 1",$source);
        self::assertStringContainsString("['template_version']+1",$source);
        self::assertStringContainsString("['template_status']='DRAFT'",$source);
        self::assertStringContainsString('digiforge_template_no_drift',$source);
        self::assertStringContainsString('digiforge_template_version_conflict',$source);
    }

    public function testMaterialDriftExcludesLifecycleIdentity(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php');
        self::assertStringContainsString('materialFingerprint(array $normalized)',$source);
        self::assertStringContainsString("['supplier','provider_blueprint_id','provider_id','variant_ids','print_areas','personalization_pipeline','personalization_engine']",$source);
        $repo=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateRepository.php');
        self::assertStringContainsString('ProductionTemplateContract::materialFingerprint($priorNormalized)',$repo);
        self::assertStringContainsString('ProductionTemplateContract::materialFingerprint($candidate)',$repo);
        self::assertStringContainsString('digiforge_template_no_drift',$repo);
    }

    public function testValidatedTemplatesAreImmutableAndContractHasNoExecution(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php');
        self::assertStringContainsString("['VALIDATED','RETIRED']",$source);
        self::assertStringContainsString('catalog drift creates a new candidate version',$source);
        self::assertStringNotContainsString('wp_remote_',$source);
        self::assertStringNotContainsString('createOrder',$source);
        self::assertStringNotContainsString('publish',$source);
    }
}
