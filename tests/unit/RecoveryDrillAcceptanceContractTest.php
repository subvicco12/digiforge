<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryDrillAcceptanceContractTest extends TestCase
{
    public function testAcceptanceIsProofBoundAndCannotExecuteRecovery(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryDrillAcceptance.php');
        foreach (['RecoveryOrchestrator::safetyLocked()','RecoveryDispatchLedger::safetyLocked()','RecoveryDrillReviewCandidate::build($operationKey)','verification_evidence_hash','database_identity_verified','RecoveryDrillEvidence::store($record)',"'external_actions_performed'=>false","'external_execution_authorized'=>false"] as $needle) self::assertStringContainsString($needle,$code);
        self::assertStringNotContainsString('RecoveryOrchestrator::execute',$code);
        self::assertStringNotContainsString('wp_remote_',$code);
    }

    public function testLegacyCallerSuppliedDrillEvidencePostIsRemoved(): void
    {
        $controller=(string)file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringNotContainsString("'callback' => [\$this, 'record_recovery_drill_evidence']", $controller);
        self::assertStringContainsString("'/recovery/orchestration/drill-accept'", $controller);
        self::assertStringContainsString('wp_get_current_user()', $controller);
        self::assertStringContainsString('RecoveryDrillAcceptance::accept($operationKey,$evidenceHash,$performedBy)', $controller);
    }
}
