<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrintifyCatalogPersistenceStructureTest extends TestCase
{
    public function testPersistenceUsesNormalizedSharedCatalogIdentity(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyCatalogPersistence.php');
        self::assertIsString($source);
        self::assertStringContainsString('PrintifyCatalogContract::normalizeCatalogVariant',$source);
        self::assertStringContainsString('Tables::pod_catalog()',$source);
        self::assertStringContainsString('provider_product_key',$source);
        self::assertStringContainsString('provider_variant_key',$source);
        self::assertStringContainsString('catalog_change_detected',$source);
        self::assertStringContainsString("'idempotent'=>true",$source);
    }

    public function testCatalogPersistenceCannotMutateProductionTemplatesOrExecuteProviderActions(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyCatalogPersistence.php');
        self::assertStringNotContainsString('ProductionTemplate',$source);
        self::assertStringNotContainsString('pod_mappings()',$source);
        self::assertStringNotContainsString('wp_remote_',$source);
        self::assertStringNotContainsString('createOrder',$source);
        self::assertStringNotContainsString('publish',$source);
    }
}
