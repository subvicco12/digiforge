<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryRestoredDatabaseAttestationContractTest extends TestCase
{
    public function testAttestationExposesOnlyReadOnlyMarkerIdentity(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryBackupMarkerAttestation.php');
        foreach (['RecoveryDispatchLedger::read','marker_version','operation_key_hash','marker_hash','prepared_at',"'external_actions_performed'=>false","'read_only'=>true"] as $needle) self::assertStringContainsString($needle,$code);
        self::assertStringContainsString("(\$record['backup_certified']??false)===true",$code);
        self::assertStringNotContainsString('nonce_sha256',$code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store',$code);
    }

    public function testVerifierRequiresObservedMarkerToMatchImmutableBinding(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryStagingVerifier.php');
        foreach (['RecoveryBackupIdentityBinding::read($identityOperationKey)','RecoveryBackupIdentityMarker::read($identityOperationKey)','database-backup/marker/','database_identity_verified','hash_equals',"(\$plan['backup_identity_binding_hash'] ?? '')"] as $needle) self::assertStringContainsString($needle,$code);
    }
}
