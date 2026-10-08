<?php
declare(strict_types=1);

final class PodReplaySanitizedKeyTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testSanitizedIdempotencyKeyMatchesPersistedKey(): void
    {
        global $wpdb;
        $input = [
            'business_id' => 'other_business', 'store_id' => 'other_store',
            'product_program' => 'PERSONALIZED_POD',
            'product_version_id' => 2147483646, 'provider_mapping_id' => 2147483646,
        ];
        $repository = new \DigiForge\POD\BusinessScopeRepository();
        $fingerprint = (new ReflectionMethod($repository, 'requestFingerprint'))->invoke($repository, $input);
        self::assertIsString($fingerprint);
        $key = 'sanitized-replay-' . wp_rand(100000, 999999);
        $now = current_time('mysql', true);
        self::assertSame(1, $wpdb->insert(\DigiForge\Database\Tables::pod_business_mappings(), [
            'business_id' => 2147483647, 'store_id' => 2147483647,
            'product_program_id' => 2147483647, 'product_version_id' => 2147483647,
            'provider_mapping_id' => 2147483647, 'state' => 'DRAFT',
            'request_fingerprint' => $fingerprint, 'idempotency_key' => $key,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $id = (int) $wpdb->insert_id;
        $result = $repository->replay($input, '<b>' . $key . '</b>');
        self::assertIsArray($result);
        self::assertSame($id, (int) $result['id']);
    }
}
