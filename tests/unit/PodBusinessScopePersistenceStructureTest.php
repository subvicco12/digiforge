<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PodBusinessScopePersistenceStructureTest extends TestCase
{
    public function testConfiguredScopeResolutionChecksRegistryOwnership(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/BusinessScope.php');
        self::assertIsString($source);
        self::assertStringContainsString('Tables::businesses()', $source);
        self::assertStringContainsString('Tables::stores()', $source);
        self::assertStringContainsString('business_id=%d', $source);
        self::assertStringContainsString('Tables::product_programs()', $source);
        self::assertStringContainsString('program_key=%s', $source);
        self::assertStringContainsString('DigiCraftifyGoods is restricted to PERSONALIZED_POD', $source);
    }

    public function testBusinessMappingWriteUsesResolvedScopeAndSharedProviderMapping(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/BusinessScopeRepository.php');
        self::assertIsString($source);
        self::assertStringContainsString('BusinessScope::resolveConfigured($input)', $source);
        self::assertStringContainsString('Tables::pod_business_mappings()', $source);
        self::assertStringContainsString('Tables::pod_mappings()', $source);
        self::assertStringContainsString("'business_id' => \$scope['business_id']", $source);
        self::assertStringContainsString("'store_id' => \$scope['store_id']", $source);
        self::assertStringContainsString("'product_program_id' => \$scope['product_program_id']", $source);
    }

    public function testSupplierCatalogRemainsGloballyShared(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/includes/Database/PodSchema.php');
        self::assertIsString($schema);
        $catalogStart = strpos($schema, 'Tables::pod_catalog()');
        $mappingStart = strpos($schema, 'Tables::pod_mappings()');
        self::assertNotFalse($catalogStart);
        self::assertNotFalse($mappingStart);
        $catalogDefinition = substr($schema, $catalogStart, $mappingStart - $catalogStart);
        self::assertStringNotContainsString('business_id', $catalogDefinition);
        self::assertStringNotContainsString('store_id', $catalogDefinition);
        self::assertStringNotContainsString('product_program', $catalogDefinition);
    }
}
