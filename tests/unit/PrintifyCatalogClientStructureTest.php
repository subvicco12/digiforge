<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrintifyCatalogClientStructureTest extends TestCase
{
    public function testClientIsReadOnlyAndOriginPinned(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/PrintifyCatalogClient.php');
        self::assertIsString($source);
        self::assertStringContainsString("private const V1 = 'https://api.printify.com/v1/'", $source);
        self::assertStringContainsString("private const V2 = 'https://api.printify.com/v2/'", $source);
        self::assertStringContainsString('wp_remote_get', $source);
        self::assertStringNotContainsString('wp_remote_post', $source);
        self::assertStringNotContainsString('wp_remote_request', $source);
        self::assertStringNotContainsString('/products.json', $source);
        self::assertStringNotContainsString('/orders.json', $source);
        self::assertStringContainsString("'redirection' => 0", $source);
    }

    public function testClientRequiresBearerCredentialAndUserAgent(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/PrintifyCatalogClient.php');
        self::assertIsString($source);
        self::assertStringContainsString("'Authorization' => 'Bearer ' . \$token", $source);
        self::assertStringContainsString("'User-Agent' => 'DigiForge/PrintifyCatalogSync'", $source);
        self::assertStringContainsString('digiforge_printify_credentials', $source);
        self::assertStringNotContainsString('error_log', $source);
        self::assertStringNotContainsString('Logger::', $source);
    }

    public function testCatalogUsesV1AndShippingUsesV2Methods(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/PrintifyCatalogClient.php');
        self::assertIsString($source);
        self::assertStringContainsString("catalog/blueprints.json", $source);
        self::assertStringContainsString("/print_providers.json", $source);
        self::assertStringContainsString("/variants.json", $source);
        self::assertStringContainsString("['standard', 'priority', 'express', 'economy']", $source);
    }

    public function testRateLimitHandlingIsBounded(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/PrintifyCatalogClient.php');
        self::assertIsString($source);
        self::assertStringContainsString('private const MAX_ATTEMPTS = 3', $source);
        self::assertStringContainsString('$status === 429', $source);
        self::assertStringContainsString("'retry-after'", $source);
        self::assertStringContainsString('min($retry, 60)', $source);
    }
}
