<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class IntegrationControlCenterStructureTest extends TestCase
{
    public function testControlCenterSupportsKnownAndFutureProviders(): void
    {
        $catalog = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/ProviderCatalog.php');
        $admin = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Admin.php');
        $repository = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Repository.php');

        foreach (['etsy', 'printify', 'gelato', 'ai'] as $provider) {
            self::assertStringContainsString("'$provider'", $catalog);
        }

        self::assertStringContainsString('Custom / future API', $admin);
        self::assertStringContainsString('provider_custom', $admin);
        self::assertStringContainsString('ProviderCatalog::validProviderSlug', $repository);
    }

    public function testCredentialEntryRemainsWriteOnlyAndFailClosed(): void
    {
        $admin = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Admin.php');
        $repository = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Repository.php');

        self::assertStringContainsString('type="password"', $admin);
        self::assertStringContainsString('autocomplete="new-password"', $admin);
        self::assertStringContainsString('fingerprint', $admin);
        self::assertStringNotContainsString("['ciphertext']", $admin);
        self::assertStringContainsString('Secrets are rejected from configuration JSON', $admin);
        self::assertStringContainsString('integration_credentials_required', $repository);
        self::assertStringContainsString('integration_not_configured', $repository);
    }

    public function testAdminMutationsRequireCapabilityAndNonces(): void
    {
        $admin = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Admin.php');

        self::assertStringContainsString('manage_digiforge_connections', $admin);
        self::assertStringContainsString('check_admin_referer', $admin);
        self::assertStringContainsString('wp_nonce_field', $admin);
        self::assertStringContainsString('admin_post_digiforge_integration_secret', $admin);
    }
}
