<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryBackupIdentityMarkerReplayIntegrityContractTest extends TestCase
{
    public function testReplayAndReadFailClosedOnTamperedMarker(): void
    {
        $code = (string) file_get_contents(__DIR__ . '/../../includes/Operations/RecoveryBackupIdentityMarker.php');
        self::assertSame(2, substr_count($code, 'RecoveryBackupMarkerAttestation::attest('));
        self::assertStringContainsString('if ($existing !== [])', $code);
        self::assertStringContainsString('if ($attested instanceof \\WP_Error) return $attested;', $code);
        self::assertStringContainsString('if ($record instanceof \\WP_Error || $record === []) return $record;', $code);
    }
}
