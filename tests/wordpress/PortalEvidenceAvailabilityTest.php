<?php
declare(strict_types=1);

final class PortalEvidenceAvailabilityTest extends WP_UnitTestCase
{
    public function testFailedReadsDoNotMasqueradeAsZeroCostOrZeroUnknownOutcomes(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public ?array $row=null;
            public ?array $rows=null;
            public function get_row(string $sql,mixed $format):?array{return $this->row;}
            public function get_results(string $sql,mixed $format):?array{return $this->rows;}
        };
        $GLOBALS['wpdb']=$db;
        try {
            $cost=(new DigiForge\AI\CostKpiReadModel())->snapshot();
            self::assertSame('UNAVAILABLE',$cost['query_state']);
            $unknown=(new DigiForge\POD\PrintifyUnknownOperatorReadModel())->summary();
            self::assertSame('UNAVAILABLE',$unknown['query_state']);
            self::assertNull($unknown['unknown_outcomes']);
            $provider=(new DigiForge\POD\ProviderStatusReadModel())->snapshot();
            self::assertSame('UNAVAILABLE',$provider['query_state']);
            self::assertNull($provider['unresolved_reconciliations']);

            $db->row=['quantity'=>0,'estimated_cost'=>0,'actual_cost'=>0];
            $db->rows=[];
            self::assertSame('AVAILABLE',(new DigiForge\AI\CostKpiReadModel())->snapshot()['query_state']);
            $provider=(new DigiForge\POD\ProviderStatusReadModel())->snapshot();
            self::assertSame('AVAILABLE',$provider['query_state']);
            self::assertSame(0,$provider['unknown_outcomes']);
            $db->last_error='read failed';
            self::assertSame('UNAVAILABLE',(new DigiForge\AI\CostKpiReadModel())->snapshot()['query_state']);
            self::assertSame('UNAVAILABLE',(new DigiForge\POD\ProviderStatusReadModel())->snapshot()['query_state']);
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }
}
