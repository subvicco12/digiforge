<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryProviderContractTest extends TestCase
{
    public function testProviderContractSeparatesExecutionAndReconciliation(): void
    {
        $contract=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryProviderAdapter.php');
        self::assertStringContainsString('public function capability(): array;', $contract);
        self::assertStringContainsString('public function execute(array $plan)', $contract);
        self::assertStringContainsString('public function reconcile(array $plan)', $contract);
        self::assertStringNotContainsString('RecoveryDrillEvidence', $contract);
    }

    public function testRegistryRequiresExplicitAdapterObjects(): void
    {
        $registry=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryProviderRegistry.php');
        self::assertStringContainsString('register(RecoveryProviderAdapter $adapter)', $registry);
        self::assertStringContainsString('get(string $slug): ?RecoveryProviderAdapter', $registry);
        self::assertStringNotContainsString('wp_remote_', $registry);
        self::assertStringNotContainsString('get_option(', $registry);
    }

    public function testOrchestratorNoLongerUsesOpenEndedExecutionFilter(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOrchestrator.php');
        self::assertStringContainsString('RecoveryProviderRegistry::capabilities()', $code);
        self::assertStringContainsString('RecoveryProviderRegistry::get(', $code);
        self::assertStringContainsString('$provider->execute($record)', $code);
        self::assertStringNotContainsString("apply_filters('digiforge_recovery_provider_execute'", $code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $code);
    }
}
