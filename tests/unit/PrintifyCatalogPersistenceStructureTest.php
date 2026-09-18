<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrintifyCatalogPersistenceStructureTest extends TestCase
{
    private function source(): string
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyCatalogPersistence.php');
        self::assertIsString($source);return $source;
    }

    public function testPersistenceUsesNormalizedSharedCatalogIdentity(): void
    {
        $source=$this->source();self::assertStringContainsString('PrintifyCatalogContract::normalizeCatalogVariant',$source);self::assertStringContainsString('Tables::pod_catalog()',$source);self::assertStringContainsString('provider_product_key',$source);self::assertStringContainsString('provider_variant_key',$source);self::assertStringContainsString('catalog_change_detected',$source);self::assertStringContainsString("'idempotent'=>true",$source);
    }

    public function testFreshObservationDoesNotCountAsMaterialSupplierChange(): void
    {
        $source=$this->source();self::assertStringContainsString('materialChanged',$source);self::assertStringContainsString("'observed_at'=>\$data['observed_at']??null",$source);
        $material="foreach(['title','variant_label','attributes','currency','base_cost','shipping_profile','availability_state','source_revision']";self::assertStringContainsString($material,$source);
        self::assertStringNotContainsString("'source_revision','observed_at'] as \$field",$source);
    }

    public function testRefreshPreservesReviewedLifecycleState(): void
    {
        $source=$this->source();self::assertStringContainsString("\$data['state']=(string)(\$existing['state']??'DRAFT')",$source);self::assertStringNotContainsString("\$data['state']='DRAFT'",$source);
    }

    public function testConcurrentPhysicalIdentityInsertConvergesByRefetch(): void
    {
        $source=$this->source();self::assertStringContainsString('findPhysical',$source);self::assertStringContainsString("if(\$ok===false)",$source);self::assertStringContainsString('persistExisting($winner,$data)',$source);self::assertStringContainsString("SELECT * FROM '.\$table.' WHERE id=%d",$source);self::assertStringContainsString("(is_array(\$fresh)?\$fresh:\$existing)+['catalog_change_detected'=>false,'idempotent'=>true]",$source);self::assertStringNotContainsString('return $this->persistVariant($raw)',$source);
    }

    public function testCatalogPersistenceCannotMutateProductionTemplatesOrExecuteProviderActions(): void
    {
        $source=$this->source();self::assertStringNotContainsString('ProductionTemplate',$source);self::assertStringNotContainsString('pod_mappings()',$source);self::assertStringNotContainsString('wp_remote_',$source);self::assertStringNotContainsString('createOrder',$source);self::assertStringNotContainsString('publish',$source);
    }
}



