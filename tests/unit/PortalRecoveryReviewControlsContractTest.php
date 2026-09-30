<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalRecoveryReviewControlsContractTest extends TestCase
{
    public function testPortalUsesImmutableRecoveryReviewEvidenceAndExplicitHumanAcceptance(): void
    {
        $portal=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        foreach ([
            'RecoveryVerificationEvidence::read($recoveryOperationKey)',
            'RecoveryDrillReviewCandidate::build($recoveryOperationKey)',
            '$blocked=($reviewCandidate[\'acceptance_blocked\']??true)!==false',
            'RECOVERY_ACCEPT_ACTION',
            'verification_evidence_hash',
            'RecoveryDrillAcceptance::accept($operationKey, $evidenceHash, $performedBy)',
            'wp_get_current_user()',
            'Accept verified recovery drill',
            'No drill PASS can be recorded',
        ] as $needle) self::assertStringContainsString($needle,$portal);
    }

    public function testPortalAcceptanceDoesNotInvokeRecoveryExecution(): void
    {
        $portal=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        $start=strpos($portal,'public function recoveryAccept(): void');
        $end=strpos($portal,'public function listingReview(): void',$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method=substr($portal,$start,$end-$start);
        self::assertStringNotContainsString('RecoveryOrchestrator::execute',$method);
        self::assertStringNotContainsString('wp_remote_',$method);
        self::assertStringNotContainsString('Settings::set',$method);
        self::assertStringContainsString('check_admin_referer(self::RECOVERY_ACCEPT_ACTION)',$method);
    }
}
