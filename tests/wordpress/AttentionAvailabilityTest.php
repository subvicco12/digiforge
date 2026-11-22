<?php
declare(strict_types=1);

final class AttentionAvailabilityTest extends WP_UnitTestCase
{
    public function testPermitPersistenceReadFailureCannotBecomeZeroAttention(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $table=DigiForge\Database\Tables::pod_permit_persistence_observations();
        $fault=static fn(string $sql):string=>str_contains($sql,'SELECT COUNT(*) FROM '.$table)
            ? 'SELECT * FROM digiforge_synthetic_absent_persistence_counts' : $sql;
        $previous=$wpdb->suppress_errors(true);
        add_filter('query',$fault);
        try {
            $summary=(new DigiForge\Portal\AttentionReadModel())->summary();
            self::assertSame('UNAVAILABLE',$summary['production_permit_persistence_projection']['query_state']);
            self::assertNull($summary['production_permit_persistence_unknown']);
            self::assertNull($summary['production_permit_persistence_observed']);
            self::assertContains('production_permit_persistence_unknown',$summary['unavailable_signals']);
            self::assertContains('production_permit_persistence_observed',$summary['unavailable_signals']);
            self::assertSame('PARTIAL_UNAVAILABLE',$summary['query_state']);
            self::assertNull($summary['total_attention']);
            self::assertSame('READ_ONLY_NO_EXECUTION',$summary['external_execution_state']);
        } finally {
            remove_filter('query',$fault);
            $wpdb->suppress_errors($previous);
        }
    }

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

    public function testPreflightSourceReadFailureCannotBecomeZeroAttention(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $table=DigiForge\Database\Tables::pod_authorization_packages();
        $fault=static fn(string $sql):string=>str_contains($sql,'SELECT id FROM '.$table." WHERE state='APPROVED_PACKAGE'")
            ? 'SELECT * FROM digiforge_synthetic_absent_preflight_counts' : $sql;
        $previous=$wpdb->suppress_errors(true);
        add_filter('query',$fault);
        try {
            $summary=(new DigiForge\Portal\AttentionReadModel())->summary();
            self::assertNull($summary['production_revalidation_reviews']);
            self::assertSame('UNAVAILABLE',$summary['production_preflight']['query_state']);
            self::assertContains('production_revalidation_reviews',$summary['unavailable_signals']);
            self::assertSame('PARTIAL_UNAVAILABLE',$summary['query_state']);
            self::assertNull($summary['total_attention']);
        } finally {
            remove_filter('query',$fault);
            $wpdb->suppress_errors($previous);
        }
    }
}
