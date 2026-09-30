<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ManualRecoveryReconciliationContractTest extends TestCase
{
    public function testManualRestoreReconciliationIsFailClosedAndNeverDispatches(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOrchestrator.php');
        foreach ([
            'reconcileManualRestore',
            'I_CONFIRM_MANUAL_STAGING_RESTORE_COMPLETED',
            'RecoveryOrchestrator',
            "'MANUAL_RESTORE_RECONCILED'",
            "'manual_restore_reconciled' => true",
            "'reconciliation_required' => true",
            "'external_actions_performed' => true",
            "['PLANNED', 'PROVIDER_REQUIRED']",
            'self::claim($operationKey) !== []',
            'self::artifactEvidenceHash($artifacts)',
            "'provider' => 'hostinger'",
        ] as $needle) self::assertStringContainsString($needle,$code);

        $start=strpos($code,'public static function reconcileManualRestore');
        $end=strpos($code,'/** Permanent operation identity',$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method=substr($code,$start,$end-$start);
        self::assertStringNotContainsString('->execute(', $method);
        self::assertStringNotContainsString('wp_remote_', $method);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $method);
        self::assertStringNotContainsString("'passed' => true", $method);
    }

    public function testManagementApiRequiresAuthenticatedExplicitReconciliation(): void
    {
        $controller=(string)file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString("'/recovery/orchestration/reconcile-manual'",$controller);
        self::assertStringContainsString("'permission_callback' => [\$this, 'can_manage']",$controller);
        self::assertStringContainsString('RecoveryOrchestrator::reconcileManualRestore',$controller);
        self::assertStringContainsString('recovery_orchestration_manual_restore_reconciled',$controller);
        self::assertStringContainsString("'external_action_performed_outside_digiforge' => true",$controller);
    }
}
