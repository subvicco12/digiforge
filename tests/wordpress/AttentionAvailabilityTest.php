<?php
declare(strict_types=1);

final class AttentionAvailabilityTest extends WP_UnitTestCase
{
    public function testIncompleteSourcesCannotProduceAnObservedAttentionTotal(): void
    {
        $items=[
            'listing_decisions'=>2,
            'printify_unknown_reconciliations'=>null,
            'production_provenance_integrity_historical'=>5,
            'production_permit_persistence_observed'=>7,
        ];
        self::assertNull(DigiForge\Portal\AttentionReadModel::completeTotal(
            $items,['printify_unknown_reconciliations']
        ));
        $items['printify_unknown_reconciliations']=1;
        self::assertSame(3,DigiForge\Portal\AttentionReadModel::completeTotal($items,[]));
    }
}
