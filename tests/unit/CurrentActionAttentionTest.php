<?php
declare(strict_types=1);

use DigiForge\Portal\AttentionReadModel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/Portal/AttentionReadModel.php';

final class CurrentActionAttentionTest extends TestCase
{
    public function testResolvedHistoryNeverInflatesCurrentAttention(): void
    {
        $items = [
            'open_operational_alerts' => 2,
            'orders_needing_reconciliation' => 1,
            'production_permit_persistence_unknown' => 1,
            'production_permit_persistence_observed' => 100,
            'production_provenance_integrity' => 1,
            'production_provenance_integrity_historical' => 200,
            'production_provenance_integrity_acknowledged' => 300,
        ];
        self::assertSame(5, AttentionReadModel::currentTotal($items));
        self::assertSame(0, AttentionReadModel::currentTotal(array_replace($items, [
            'open_operational_alerts' => 0,
            'orders_needing_reconciliation' => 0,
            'production_permit_persistence_unknown' => 0,
            'production_provenance_integrity' => 0,
        ])));
    }

    public function testCurrentActionQueriesExcludeTerminalOrdersAlertsAndTaxReviews(): void
    {
        $attention = file_get_contents(__DIR__ . '/../../includes/Portal/AttentionReadModel.php');
        $portal = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        $exceptions = file_get_contents(__DIR__ . '/../../includes/Operations/OperationalExceptionReadModel.php');
        self::assertStringContainsString("o.state NOT IN ('CLOSED','REJECTED','SUPERSEDED') AND (li.id IS NULL", $attention);
        self::assertStringContainsString("state NOT IN ('RESOLVED','DISMISSED','SUPERSEDED','CLOSED')", $attention);
        self::assertStringContainsString("['RESOLVED', 'DISMISSED', 'SUPERSEDED', 'CLOSED']", $portal);
        self::assertSame(2, substr_count($portal, "state NOT IN ('RESOLVED','DISMISSED','SUPERSEDED','CLOSED')"));
        self::assertStringContainsString("review_status NOT IN ('APPROVED','REVIEWED','REJECTED','SUPERSEDED')", $exceptions);
        self::assertStringContainsString("review_status='APPROVED' AND classification='REVIEW_REQUIRED'", $exceptions);
    }
}
