<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryBackupIdentityBindingContractTest extends TestCase
{
    public function testBindingRequiresExactFreshPostMarkerBackupEvidence(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryBackupIdentityBinding.php');
        foreach (['RecoveryOrchestrator::safetyLocked()','RecoveryDispatchLedger::safetyLocked()','RecoveryBackupIdentityMarker::read($operationKey)','RecoveryEvidence::snapshot()',"database_backup_retrievable","database_backup_verification_fresh",'$captured < $prepared',"'database_identity_proof_available' => true",'RecoveryDispatchLedger::insert($name,$record)','digiforge_recovery_backup_binding_conflict'] as $needle) self::assertStringContainsString($needle,$code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store',$code);
        self::assertStringNotContainsString('wp_remote_',$code);
    }
}
