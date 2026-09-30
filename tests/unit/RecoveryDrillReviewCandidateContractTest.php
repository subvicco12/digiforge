<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryDrillReviewCandidateContractTest extends TestCase
{
    public function testCandidateRequiresImmutableReceiptAndCannotRecordPass(): void
    {
        $code=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryDrillReviewCandidate.php');
        foreach (['RecoveryOrchestrator::safetyLocked()','RecoveryVerificationEvidence::read($operationKey)',"'review_required' => true","\$databaseIdentityVerified = (\$checks['database_identity_verified'] ?? false) === true","'acceptance_blocked' => ! \$databaseIdentityVerified","'restore_verified' => \$databaseIdentityVerified","'database_identity_verified' => \$databaseIdentityVerified","'drill_evidence_recorded' => false","'passed' => false","'external_execution_authorized' => false","'commerce_execution_authorized' => false"] as $needle) self::assertStringContainsString($needle,$code);
        self::assertStringNotContainsString('RecoveryDrillEvidence::store', $code);
        self::assertStringNotContainsString('RecoveryOrchestrator::execute', $code);
        self::assertStringNotContainsString('wp_remote_', $code);
    }

    public function testReviewEndpointIsReadOnlyManagementSurface(): void
    {
        $controller=(string)file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString("'/recovery/orchestration/drill-review'", $controller);
        self::assertStringContainsString("'methods' => 'GET'", $controller);
        self::assertStringContainsString('RecoveryDrillReviewCandidate::build($operationKey)', $controller);
    }
}
