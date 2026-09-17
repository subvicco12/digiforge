<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PodBusinessScopePersistenceStructureTest extends TestCase
{
    public function testConfiguredScopeResolutionChecksActiveRegistryOwnership(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/BusinessScope.php');
        self::assertIsString($source);
        self::assertStringContainsString('Tables::businesses()', $source);
        self::assertStringContainsString('Tables::stores()', $source);
        self::assertStringContainsString('business_id=%d', $source);
        self::assertStringContainsString('Tables::product_programs()', $source);
        self::assertStringContainsString('program_key=%s', $source);
        self::assertStringContainsString('status=%s', $source);
        self::assertStringContainsString("private const ACTIVE = 'ACTIVE'", $source);
        self::assertStringContainsString('DigiCraftifyGoods is restricted to PERSONALIZED_POD', $source);
    }

    public function testBusinessMappingWriteLocksActiveScopeAndRejectsCrossScopeOwnership(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/BusinessScopeRepository.php');
        self::assertIsString($source);
        self::assertStringContainsString('BusinessScope::resolveConfigured($input)', $source);
        self::assertStringContainsString("START TRANSACTION", $source);
        self::assertStringContainsString('FOR UPDATE', $source);
        self::assertStringContainsString("status=%s", $source);
        self::assertStringContainsString('digiforge_scope_ownership_conflict', $source);
        self::assertStringContainsString('digiforge_idempotency_conflict', $source);
        self::assertStringContainsString('product_version_id=%d AND provider_mapping_id=%d', $source);
        self::assertStringContainsString('Tables::pod_business_mappings()', $source);
        self::assertStringContainsString('Tables::pod_mappings()', $source);
        self::assertStringContainsString("'business_id' => \$scope['business_id']", $source);
        self::assertStringContainsString("'store_id' => \$scope['store_id']", $source);
        self::assertStringContainsString("'product_program_id' => \$scope['product_program_id']", $source);
    }

    public function testBusinessScopeSchemaMakesProductProviderOwnerUnique(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/includes/Database/BusinessScopeSchema.php');
        self::assertIsString($schema);
        self::assertStringContainsString('UNIQUE KEY product_provider_owner (product_version_id,provider_mapping_id)', $schema);
        self::assertStringNotContainsString('UNIQUE KEY scope_product_mapping', $schema);
    }

    public function testV14InstallerAlwaysReconcilesSchemaStatements(): void
    {
        $installer = file_get_contents(dirname(__DIR__, 2) . '/includes/Database/BusinessScopeInstaller.php');
        self::assertIsString($installer);
        self::assertStringContainsString('BusinessScopeSchema::statements($charset)', $installer);
        self::assertStringNotContainsString('$allPresent', $installer);
        self::assertStringContainsString('BUSINESS_SCOPE_SCHEMA_UPDATE_FAILED', $installer);
        self::assertStringContainsString('BUSINESS_SCOPE_SCHEMA_VERIFY_FAILED', $installer);
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
