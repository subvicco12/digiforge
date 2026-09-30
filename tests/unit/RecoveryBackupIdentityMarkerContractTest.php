<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryBackupIdentityMarkerContractTest extends TestCase
{
    public function testMarkerIsPreCaptureImmutableAndFailClosed(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryBackupIdentityMarker.php');
        foreach (['RecoveryOrchestrator::safetyLocked()','RecoveryDispatchLedger::safetyLocked()','RecoveryDispatchLedger::read($name)','RecoveryDispatchLedger::insert($name, $record)','random_bytes(32)',"'backup_identifier' => ''","'backup_certified' => false","'external_actions_performed' => false","'external_execution_authorized' => false"] as $needle) self::assertStringContainsString($needle,$code);
        self::assertStringNotContainsString('RecoveryEvidence::storeDatabaseBackup', $code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $code);
        self::assertStringNotContainsString('wp_remote_', $code);
    }

    public function testPreparationEndpointRequiresManagementAndRecordsNoExternalAction(): void
    {
        $controller=(string)file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString("'/recovery/evidence/database-backup/prepare'", $controller);
        self::assertStringContainsString('RecoveryBackupIdentityMarker::prepare($operationKey)', $controller);
        self::assertStringContainsString("'backup_certified' => false", $controller);
    }
}
