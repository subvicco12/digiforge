<?php
declare(strict_types=1);

final class PodConfiguredScopeRegistryFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testMissingActiveBusinessDoesNotAuthorizeScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('active configured business is required');
        \DigiForge\POD\BusinessScope::resolveConfigured([
            'business_id' => 'digiforge_test_nonexistent_business',
            'store_id' => 'digiforge_test_nonexistent_store',
            'product_program' => 'PERSONALIZED_POD',
        ]);
    }

    public function testUnsupportedProgramIsRejectedBeforeRegistryLookup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported product_program');
        \DigiForge\POD\BusinessScope::resolveConfigured([
            'business_id' => 'digiforge_test_nonexistent_business',
            'store_id' => 'digiforge_test_nonexistent_store',
            'product_program' => 'UNSUPPORTED',
        ]);
    }
}
