<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3HostCronRecoveryWindowTest extends TestCase
{
    public function testRecoveryDeadlineAndActiveLeaseAreIndependent(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/ApprovalAutomation.php');
        self::assertIsString($source);
        self::assertStringContainsString('MAX_RECOVERY_SECONDS = 21600', $source);
        self::assertStringContainsString('ACTIVE_LEASE_SECONDS = 1200', $source);
        self::assertStringContainsString('(time()-$createdAt)>self::MAX_RECOVERY_SECONDS', str_replace(' ', '', $source));
        self::assertStringContainsString('self::ACTIVE_LEASE_SECONDS', $source);
        self::assertStringNotContainsString('MAX_RUNTIME_SECONDS = 1200', $source);
        self::assertStringContainsString('digiforge_u3_runtime_expired', $source);
        self::assertStringContainsString("'external_actions'=>false", str_replace(' ', '', $source));
    }
}
