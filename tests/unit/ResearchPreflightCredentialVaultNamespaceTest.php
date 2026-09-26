<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ResearchPreflightCredentialVaultNamespaceTest extends TestCase
{
    public function test_preflight_uses_the_runtime_integration_credential_vault(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Launch/ResearchActivationPreflight.php');
        self::assertIsString($source);
        self::assertStringContainsString('use DigiForge\\Integrations\\CredentialVault;', $source);
        self::assertStringNotContainsString('use DigiForge\\Security\\CredentialVault;', $source);
    }
}
