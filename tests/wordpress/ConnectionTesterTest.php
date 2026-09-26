<?php

declare(strict_types=1);

final class ConnectionTesterTest extends WP_UnitTestCase
{
    public function testPrintifyReadOnlyConnectionTestUsesStoredCredentialAndRecordsSuccess(): void
    {
        DigiForge\Core\Activator::activate();
        self::assertTrue(DigiForge\Core\Settings::set('research', true));
        self::assertTrue(DigiForge\Core\Settings::set('ai', true));
        self::assertTrue(DigiForge\Core\Settings::set('product_development', true));
        self::assertTrue(DigiForge\Core\Settings::set('printify', true));
        self::assertTrue(DigiForge\Core\Settings::activateResearch());
        self::assertTrue(DigiForge\Core\Settings::activateAi());
        self::assertTrue(DigiForge\Core\Settings::activateProductDevelopment());
        self::assertTrue(DigiForge\Core\Settings::activatePrintify());
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'printify',
            'environment' => 'production',
            'connection_key' => 'test_printify',
            'display_name' => 'Test Printify',
            'status' => 'DISCONNECTED',
            'enabled' => false,
            'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];
        self::assertTrue($repository->storeSecret($id, 'personal_access_token', 'unit-test-printify-token') === true);

        $seenAuthorization = '';
        $seenUrl = '';
        $filter = static function ($preempt, array $args, string $url) use (&$seenAuthorization, &$seenUrl) {
            $seenUrl = $url;
            $seenAuthorization = (string) ($args['headers']['Authorization'] ?? '');
            return [
                'headers' => [],
                'body' => wp_json_encode([
                    ['id' => 12345, 'title' => 'DigiCraftifyGoods', 'sales_channel' => 'etsy'],
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try {
            $result = (new DigiForge\Integrations\ConnectionTester())->test($id);
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        self::assertFalse(is_wp_error($result));
        self::assertSame('https://api.printify.com/v1/shops.json', $seenUrl);
        self::assertSame('Bearer unit-test-printify-token', $seenAuthorization);
        self::assertTrue((bool) $result['ok']);
        self::assertCount(1, $result['shops']);
        self::assertSame(12345, $result['shops'][0]['id']);

        $updated = $repository->find($id);
        self::assertIsArray($updated);
        self::assertSame('CONFIGURED', $updated['status']);
        self::assertFalse((bool) $updated['enabled']);
        self::assertTrue((bool) ($updated['config']['_connection_test']['ok'] ?? false));
        self::assertSame(1, (int) ($updated['config']['_connection_test']['details']['shop_count'] ?? 0));
        DigiForge\Core\Settings::protectProduction();
    }

    public function testEtsyAuthenticatedConnectionUsesUsersMeAndRecordsConfigured(): void
    {
        DigiForge\Core\Activator::activate();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy',
            'environment' => 'production',
            'connection_key' => 'test_etsy',
            'display_name' => 'Test Etsy',
            'status' => 'DISCONNECTED',
            'enabled' => false,
            'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];
        self::assertTrue($repository->storeSecret($id, 'keystring', 'unit-test-keystring') === true);
        self::assertTrue($repository->storeSecret($id, 'shared_secret', 'unit-test-shared-secret') === true);
        self::assertTrue($repository->storeSecret($id, 'access_token', 'unit-test-access-token') === true);

        $seen = [];
        $filter = static function ($preempt, array $args, string $url) use (&$seen) {
            $seen[] = [
                'url' => $url,
                'x_api_key' => (string) ($args['headers']['x-api-key'] ?? ''),
                'authorization' => (string) ($args['headers']['Authorization'] ?? ''),
            ];
            $body = $url === 'https://api.etsy.com/v3/application/users/me'
                ? ['user_id' => 1290867258]
                : (str_contains($url, '/users/1290867258/shops')
                    ? ['shop_id' => 24681012, 'user_id' => 1290867258, 'shop_name' => 'DigiCraftifyDigital']
                    : ['application_id' => 1]);
            return [
                'headers' => [],
                'body' => wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try {
            $result = (new DigiForge\Integrations\ConnectionTester())->test($id);
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        self::assertFalse(is_wp_error($result));
        self::assertCount(3, $seen);
        self::assertSame('https://api.etsy.com/v3/application/openapi-ping', $seen[0]['url']);
        self::assertSame('unit-test-keystring:unit-test-shared-secret', $seen[0]['x_api_key']);
        self::assertSame('https://api.etsy.com/v3/application/users/me', $seen[1]['url']);
        self::assertSame('Bearer unit-test-access-token', $seen[1]['authorization']);
        self::assertSame('unit-test-keystring:unit-test-shared-secret', $seen[1]['x_api_key']);
        self::assertSame('https://api.etsy.com/v3/application/users/1290867258/shops', $seen[2]['url']);
        self::assertSame('unit-test-keystring:unit-test-shared-secret', $seen[2]['x_api_key']);
        self::assertSame('Bearer unit-test-access-token', $seen[2]['authorization']);
        self::assertTrue((bool) $result['ok']);

        $updated = $repository->find($id);
        self::assertIsArray($updated);
        self::assertSame('CONFIGURED', $updated['status']);
        self::assertFalse((bool) $updated['enabled']);
        self::assertTrue((bool) ($updated['config']['_connection_test']['ok'] ?? false));
        self::assertSame(1290867258, (int) ($updated['config']['_connection_test']['details']['user_id'] ?? 0));
        self::assertSame(24681012, (int) ($updated['config']['_connection_test']['details']['shop_id'] ?? 0));
        self::assertSame('DigiCraftifyDigital', (string) ($updated['config']['_connection_test']['details']['shop_name'] ?? ''));
    }
    public function testEtsyConnectionFailsClosedWhenShopIdentityIsMissing(): void
    {
        DigiForge\Core\Activator::activate();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy', 'environment' => 'production', 'connection_key' => 'test_etsy_missing_shop',
            'display_name' => 'Test Etsy Missing Shop', 'status' => 'DISCONNECTED', 'enabled' => false, 'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];
        self::assertTrue($repository->storeSecret($id, 'keystring', 'unit-test-keystring') === true);
        self::assertTrue($repository->storeSecret($id, 'shared_secret', 'unit-test-shared-secret') === true);
        self::assertTrue($repository->storeSecret($id, 'access_token', 'unit-test-access-token') === true);

        $filter = static function ($preempt, array $args, string $url) {
            $body = $url === 'https://api.etsy.com/v3/application/users/me' ? ['user_id' => 1290867258] : (str_contains($url, '/shops') ? ['shop_id' => 0, 'shop_name' => ''] : ['application_id' => 1]);
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try { $result = (new DigiForge\Integrations\ConnectionTester())->test($id); }
        finally { remove_filter('pre_http_request', $filter, 10); }

        self::assertTrue(is_wp_error($result));
        self::assertSame('etsy_shop_identity_unverified', $result->get_error_code());
        $updated = $repository->find($id);
        self::assertIsArray($updated);
        self::assertSame('ERROR', $updated['status']);
        self::assertFalse((bool) ($updated['config']['_connection_test']['ok'] ?? true));
        self::assertSame('shop_identity_unverified', (string) ($updated['config']['_connection_test']['details']['reason'] ?? ''));
    }

}
