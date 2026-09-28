<?php
declare(strict_types=1);

use DigiForge\Portal\OperationalDepthReadModel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/Portal/OperationalDepthReadModel.php';

final class FulfillmentPlanPortalEvidenceTest extends TestCase
{
    public function testApprovedPlanNeedsCurrentMatchingReadinessAndValidApprovalEvidence(): void
    {
        $hash = str_repeat('a', 64);
        $plan = [
            'state' => 'APPROVED', 'order_id' => 8, 'environment' => 'production',
            'order_environment' => 'production', 'approved_by' => 3,
            'approved_at' => '2026-09-28 10:00:00', 'payload_hash' => str_repeat('b', 64),
            'readiness_hash' => $hash,
        ];
        $current = ['order_id' => 8, 'ready' => true, 'hash' => $hash];
        self::assertSame('CURRENT_EVIDENCE_MATCH', OperationalDepthReadModel::planEvidenceState($plan, $current));
        self::assertSame('STALE_RECHECK', OperationalDepthReadModel::planEvidenceState($plan, array_replace($current, ['hash' => str_repeat('c', 64)])));
        self::assertSame('STALE_RECHECK', OperationalDepthReadModel::planEvidenceState($plan, array_replace($current, ['ready' => false])));
        self::assertSame('CURRENT_READINESS_UNAVAILABLE', OperationalDepthReadModel::planEvidenceState($plan, null));
        foreach ([['payload_hash' => ''], ['approved_by' => 0], ['order_environment' => 'sandbox']] as $change) {
            self::assertSame('EVIDENCE_INVALID_REVIEW', OperationalDepthReadModel::planEvidenceState(array_replace($plan, $change), $current));
        }
        self::assertSame('HISTORICAL_OR_DRAFT', OperationalDepthReadModel::planEvidenceState(array_replace($plan, ['state' => 'DRAFT']), null));
    }

    public function testPortalShowsCurrentActionWithoutPromisingProduction(): void
    {
        $model = file_get_contents(__DIR__ . '/../../includes/Portal/OperationalDepthReadModel.php');
        $portal = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertStringContainsString('Repository as OrderRepository', $model);
        self::assertStringContainsString('planEvidenceState($r,$current)', $model);
        self::assertStringNotContainsString('canonical_payload', $model);
        self::assertStringContainsString('Recent approved plans needing evidence review', $portal);
        self::assertStringContainsString('Draft and historical states are excluded', $portal);
        self::assertStringContainsString('Current evidence never authorizes provider production', $portal);
    }
}
