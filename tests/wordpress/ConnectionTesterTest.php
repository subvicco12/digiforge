<?php

declare(strict_types=1);

final class ConnectionTesterTest extends WP_UnitTestCase
{
    public function testPrintifyReadOnlyConnectionTestUsesStoredCredentialAndRecordsSuccess(): void
    {
        DigiForge\Core\Activator::activate();
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
    }
}
