<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExternalActionEvidenceContractTest extends TestCase
{
    public function testRuntimeSentinelUsesPersistedAttemptLifecycleOnly(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Operations/ExternalActionEvidence.php');
        foreach(['digiforge_etsy_operations','SENT','UNKNOWN','RECONCILIATION','RECONCILED','CONFIRMED_SUCCESS'] as $needle) self::assertStringContainsString($needle,$s);
        self::assertStringNotContainsString("'NOT_SENT'",$s);
    }
}
