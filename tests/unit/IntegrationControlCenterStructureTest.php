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
        self::assertStringContainsString('Secrets must be stored in the credential vault', $repository);
        self::assertStringContainsString('integration_credentials_required', $repository);
        self::assertStringContainsString('integration_not_configured', $repository);
    }

    public function testProfessionalControlsRequireConfirmationAndFailClosed(): void
    {
        $admin = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Admin.php');
        $repository = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Repository.php');

        self::assertStringContainsString('admin_post_digiforge_integration_toggle', $admin);
        self::assertStringContainsString('admin_post_digiforge_integration_delete', $admin);
        self::assertStringContainsString('admin_post_digiforge_integration_secret_delete', $admin);
        self::assertStringContainsString('return confirm(', $admin);
        self::assertStringContainsString('Delete this connector and all stored encrypted credentials?', $admin);
        self::assertStringContainsString('Delete credential %s?', $admin);
        self::assertStringContainsString('deleteSecret', $repository);
        self::assertStringContainsString("'status' => 'DISCONNECTED'", $repository);
        self::assertStringContainsString("'enabled' => 0", $repository);
        self::assertStringContainsString('setEnabled', $repository);
        self::assertStringContainsString('integration_delete_enabled', $repository);
        self::assertStringContainsString('START TRANSACTION', $repository);
        self::assertStringContainsString('ROLLBACK', $repository);
        self::assertStringContainsString('COMMIT', $repository);
    }

    public function testGelatoLegacyCredentialCanBeRepairedWithoutRepastingSecret(): void
    {
        $admin = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Admin.php');
        $repository = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Repository.php');
        $tester = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/ConnectionTester.php');
        $adminMigration = "migrateSecretName(\$id, 'personal_access_token', 'api_key')";
        $testerMigration = "migrateSecretName(\$integrationId, 'personal_access_token', 'api_key')";

        self::assertStringContainsString('admin_post_digiforge_gelato_normalize_key', $admin);
        self::assertStringContainsString('Repair Gelato key label', $admin);
        self::assertStringContainsString($adminMigration, $admin);
        self::assertStringContainsString('migrateSecretName', $repository);
        self::assertStringContainsString($testerMigration, $tester);
    }

    public function testCredentialVaultCanProvisionEncryptedManagedMasterKey(): void
    {
        $vault = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/CredentialVault.php');

        self::assertStringContainsString("MANAGED_KEY_OPTION = 'digiforge_credential_key_envelope'", $vault);
        self::assertStringContainsString("defined('DIGIFORGE_CREDENTIAL_KEY')", $vault);
        self::assertStringContainsString('managedMasterKey()', $vault);
        self::assertStringContainsString("wp_salt('auth')", $vault);
        self::assertStringContainsString("wp_salt('secure_auth')", $vault);
        self::assertStringContainsString('random_bytes(32)', $vault);
        self::assertStringContainsString('add_option(self::MANAGED_KEY_OPTION', $vault);
        self::assertStringContainsString('wrapManagedKey', $vault);
        self::assertStringContainsString('unwrapManagedKey', $vault);
        self::assertStringNotContainsString("update_option(self::MANAGED_KEY_OPTION, \$master", $vault);
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
        self::assertStringContainsString('get_json_params()', $controller);
        self::assertStringContainsString('get_body_params()', $controller);
        self::assertStringContainsString('$this->payload($request)', $controller);
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
    public function testGovernedConnectionTestRouteAndEtsyShopIdentityEvidenceExist(): void
    {
        $controller=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/IntegrationsController.php');
        $tester=(string)file_get_contents(dirname(__DIR__,2).'/includes/Integrations/ConnectionTester.php');
        self::assertStringContainsString("/integrations/(?P<id>\\d+)/test",$controller);
        self::assertStringContainsString('ConnectionTester',$controller);
        self::assertStringContainsString('ETSY_USER_SHOP_URL',$tester);
        self::assertStringContainsString("'shop_id' => \$shopId",$tester);
        self::assertStringContainsString("'shop_name' => \$shopName",$tester);
    }

    public function testConnectionPreflightSupportsAuthenticatedBodyIdempotencyFallbackOnly(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../../includes/REST/IntegrationsController.php');
        self::assertStringContainsString('200, true', $controller);
        self::assertStringContainsString('$allowBodyIdempotencyKey', $controller);
        self::assertStringContainsString("['idempotency_key']", $controller);
        self::assertStringContainsString('strlen($header) > 191', $controller);
        self::assertSame(1, substr_count($controller, '}, 200, true);'));
    }

}
