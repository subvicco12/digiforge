<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class ConnectionTesterStructureTest extends TestCase
{
    public function testKnownProviderTestersAreReadOnlyAndHardCoded(): void
    {
        $tester = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/ConnectionTester.php');
        $admin = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Admin.php');

        self::assertSame(1, substr_count($tester, 'wp_remote_get'));
        foreach (
            [
                'https://api.printify.com/v1/shops.json',
                'https://api.etsy.com/v3/application/openapi-ping',
                'https://api.etsy.com/v3/application/users/',
                'https://product.gelatoapis.com/v3/catalogs',
                'https://api.openai.com/v1/models',
            ] as $url
        ) {
            self::assertStringContainsString($url, $tester);
        }
        foreach (["'printify'", "'etsy'", "'gelato'", "'ai'"] as $provider) {
            self::assertStringContainsString($provider, $tester);
        }
        self::assertStringContainsString("'redirection' => 0", $tester);
        self::assertStringContainsString("'sslverify' => true", $tester);
        self::assertStringContainsString("'reject_unsafe_urls' => true", $tester);
        self::assertStringNotContainsString('wp_remote_post', $tester);
        self::assertStringNotContainsString('wp_remote_request', $tester);
        self::assertStringNotContainsString('DELETE', $tester);
        self::assertStringContainsString('admin_post_digiforge_integration_test', $admin);
        self::assertStringContainsString("__('Test %s', 'digiforge')", $admin);
        self::assertStringContainsString('provider-specific, read-only', $admin);
    }

    public function testGelatoLegacyCredentialCanBeNormalizedWithoutReentry(): void
    {
        $tester = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/ConnectionTester.php');
        $repository = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Repository.php');

        self::assertStringContainsString("migrateSecretName(\$integrationId, 'personal_access_token', 'api_key')", $tester);
        self::assertStringContainsString('migrateSecretName', $repository);
        self::assertStringContainsString('integration_secret_name_migrated', $repository);
    }
}
