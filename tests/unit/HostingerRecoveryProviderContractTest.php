<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HostingerRecoveryProviderContractTest extends TestCase
{
    public function testHostingerIsGovernedInfrastructureConnector(): void
    {
        $catalog=(string)file_get_contents(__DIR__.'/../../includes/Integrations/ProviderCatalog.php');
        self::assertStringContainsString("'hostinger' => [", $catalog);
        self::assertStringContainsString("'api_token' => 'Hostinger API token'", $catalog);
        self::assertStringContainsString("'hosting_account' => 'Hosting account username'", $catalog);
        self::assertStringContainsString("'staging_domain' => 'Approved isolated staging domain'", $catalog);
        self::assertStringContainsString("'archive_path' => 'Pre-uploaded WordPress archive path on staging'", $catalog);
        self::assertStringContainsString("'database_path' => 'Pre-uploaded SQL file path on staging'", $catalog);
    }

    public function testProviderTransportIsTargetBoundAndReconciliationFirst(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/HostingerRecoveryProvider.php');
        foreach (["status = %s AND enabled = %d","'CONFIGURED'","'api_token'","'hosting_account'","'staging_domain'",'digiforge_hostinger_target_mismatch','digiforge_hostinger_current_site_refused','digiforge_hostinger_import_not_enabled'] as $needle) {
            self::assertStringContainsString($needle, $code);
        }
        self::assertStringContainsString('wp_remote_post(', $code);
        self::assertStringContainsString('CredentialVault::decrypt(', $code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $code);
        self::assertStringNotContainsString("'passed' => true", $code);
    }

    public function testProviderIsExplicitlyRegisteredAtBoot(): void
    {
        $plugin=(string)file_get_contents(__DIR__.'/../../includes/Core/Plugin.php');
        self::assertStringContainsString('RecoveryProviderRegistry::register(new \\DigiForge\\Operations\\HostingerRecoveryProvider())', $plugin);
    }
}
