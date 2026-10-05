<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryOperationSupersessionContractTest extends TestCase
{
    public function testSupersessionIsHumanProofBoundAndNeverDeletesHistory(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOperationSupersession.php');
        foreach (['I_CONFIRM_STALE_RECOVERY_OPERATION_SUPERSESSION','RecoveryOrchestrator::safetyLocked()','RecoveryDispatchLedger::safetyLocked()','MANUAL_RESTORE_RECONCILED','RecoveryVerificationEvidence::read($operationKey)','RecoveryStagingClient','STALE_EXACT_VERSION_MISMATCH','RecoveryDispatchLedger::insert($archiveName,$receipt)','RecoveryOrchestrator::terminallySupersede($operationKey,$receipt)',"'retry_permitted'=>false","'external_execution_authorized'=>false"] as $needle) self::assertStringContainsString($needle,$code);
        self::assertStringNotContainsString('delete_option',$code);
        self::assertStringNotContainsString('RecoveryOrchestrator::execute',$code);
        self::assertStringNotContainsString('wp_remote_',$code);
    }

    public function testTerminalHandoffPreservesOldClaimAndRequiresAtomicCas(): void
    {
        $orchestrator=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOrchestrator.php');
        $ledger=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryDispatchLedger.php');
        self::assertStringContainsString('public static function terminallySupersede',$orchestrator);
        self::assertStringContainsString("(\$receipt['state'] ?? '') !== 'SUPERSEDED'",$orchestrator);
        self::assertStringContainsString("(\$receipt['retry_permitted'] ?? true) !== false",$orchestrator);
        self::assertStringContainsString("RecoveryDispatchLedger::compareAndSwap('digiforge_recovery_dispatch_interlock'",$orchestrator);
        self::assertStringContainsString('public static function compareAndSwap',$ledger);
        self::assertStringContainsString("['option_name' => \$name, 'option_value' => maybe_serialize(\$expected)]",$ledger);
        self::assertStringNotContainsString('$database->delete(', $ledger);
        self::assertStringNotContainsString('delete_option(', $ledger);
        self::assertStringContainsString("if (\$interlock === [])", $orchestrator);
        self::assertStringContainsString("RecoveryDispatchLedger::insert('digiforge_recovery_dispatch_interlock',\$terminal)", $orchestrator);
        self::assertStringContainsString("if ((\$interlock['state']??'')==='SUPERSEDED')", $orchestrator);
        self::assertStringContainsString("if (\$stored===\$terminal) return true;", $orchestrator);
    }

    public function testPersistedReceiptResumesFailedTerminalHandoff(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOperationSupersession.php');
        self::assertStringContainsString("if (\$existing!==[])",$code);
        self::assertStringContainsString("RecoveryOrchestrator::terminallySupersede(\$operationKey,\$existing)",$code);
        self::assertStringContainsString("return \$existing+['replayed'=>true]",$code);
        self::assertStringContainsString("hash_equals(\$observedVersion,(string)(\$existing['observed_plugin_version']??''))",$code);
        self::assertStringNotContainsString("hash_equals((string)(\$existing['evidence_hash']??''),(string)\$receipt['evidence_hash'])",$code);
    }

    public function testRestEndpointRequiresAutomationManagementCapability(): void
    {
        $controller=(string)file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString("'/recovery/orchestration/supersede-stale'",$controller);
        self::assertStringContainsString("'permission_callback' => [\$this, 'can_manage']",$controller);
        self::assertStringContainsString('wp_get_current_user()',$controller);
        self::assertStringContainsString('RecoveryOperationSupersession::supersede($operationKey,$confirmation,$performedBy)',$controller);
    }
}
