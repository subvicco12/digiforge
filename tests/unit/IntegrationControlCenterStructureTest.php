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
        self::assertStringContainsString('MAX_PROVIDER_SLUG_LENGTH = 32', $catalog);
        self::assertStringContainsString('OAuth 2.0 (PKCE) + app API key', $catalog);
        self::assertStringContainsString('X-API-KEY', $catalog);
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

    public function testRestIntegrationMutationsSupportSafeWordPressBatchTransport(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../../includes/REST/IntegrationsController.php');

        self::assertStringContainsString("private const ALLOW_BATCH = ['v1' => true]", $controller);
        self::assertGreaterThanOrEqual(3, substr_count($controller, "'allow_batch' => self::ALLOW_BATCH"));
        self::assertStringContainsString("get_header('Idempotency-Key')", $controller);
        self::assertStringContainsString('missing_idempotency_key', $controller);
    }

    public function testIntegrationCreateReportsDuplicateAndSafeFailureReference(): void
    {
        $repository = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Repository.php');

        self::assertStringContainsString('integration_exists', $repository);
        self::assertStringContainsString('error_reference', $repository);
        self::assertStringContainsString("stripos(\$dbError, 'Duplicate entry')", $repository);
        self::assertStringContainsString('invalid_connection_key', $repository);
        self::assertStringContainsString('invalid_display_name', $repository);
    }
}
