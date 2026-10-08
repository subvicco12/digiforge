<?php
declare(strict_types=1);

final class PodReplayCanonicalFingerprintTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testCanonicalizedScopeReferencesProduceIdenticalFingerprint(): void
    {
        $repository = new \DigiForge\POD\BusinessScopeRepository();
        $method = new ReflectionMethod($repository, 'requestFingerprint');
        $base = [
            'business_id' => 'other_business', 'store_id' => 'other_store',
            'product_program' => 'PERSONALIZED_POD',
            'product_version_id' => 2147483646, 'provider_mapping_id' => 2147483646,
        ];
        $canonical = $method->invoke($repository, $base);
        self::assertIsString($canonical);
        $variant = $base;
        $variant['business_id'] = 'Other_Business';
        $variant['store_id'] = 'Other_Store';
        $variant['product_program'] = ' personalized_pod ';
        self::assertSame($canonical, $method->invoke($repository, $variant));
    }
}
