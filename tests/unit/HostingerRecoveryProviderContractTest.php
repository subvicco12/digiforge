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
        foreach (["status = %s AND enabled = %d","'CONFIGURED'","'api_token'","'hosting_account'","'staging_domain'",'digiforge_hostinger_target_mismatch','digiforge_hostinger_current_site_refused','digiforge_hostinger_artifact_paths_missing','digiforge_hostinger_result_unknown','digiforge_hostinger_reference_missing','digiforge_hostinger_reconcile_requires_site_verification'] as $needle) {
            self::assertStringContainsString($needle, $code);
        }
        self::assertStringContainsString('wp_remote_post(', $code);
        self::assertStringContainsString('CredentialVault::decrypt(', $code);
        self::assertStringContainsString("'redirection' => 0", $code);
        self::assertStringContainsString("'reject_unsafe_urls' => true", $code);
        self::assertStringContainsString("'sslverify' => true", $code);
        self::assertStringNotContainsString("'Idempotency-Key'", $code);
        self::assertStringContainsString("'state' => 'UNKNOWN'", $code);
        self::assertStringContainsString("'reconciliation_required' => true", $code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $code);
        self::assertStringNotContainsString("'passed' => true", $code);
    }

    public function testProviderIsExplicitlyRegisteredAtBoot(): void
    {
        $plugin=(string)file_get_contents(__DIR__.'/../../includes/Core/Plugin.php');
        self::assertStringContainsString('RecoveryProviderRegistry::register(new \\DigiForge\\Operations\\HostingerRecoveryProvider())', $plugin);
    }
}
