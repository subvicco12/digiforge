<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryBackupMarkerAttestationIntegrityContractTest extends TestCase
{
    public function testRestoredMarkerIsContentVerifiedBeforeAttestation(): void
    {
        $code = (string) file_get_contents(__DIR__ . '/../../includes/Operations/RecoveryBackupMarkerAttestation.php');
        self::assertStringContainsString("unset(\$markerContents['marker_hash'])", $code);
        self::assertStringContainsString("hash_equals(hash('sha256', \$markerJson)", $code);
        self::assertStringContainsString('hash_equals($operationKeyHash', $code);
        self::assertStringContainsString('digiforge_recovery_marker_attestation_integrity', $code);
        self::assertStringNotContainsString('RecoveryDispatchLedger::insert', $code);
        self::assertStringNotContainsString('wp_remote_', $code);
    }
}
