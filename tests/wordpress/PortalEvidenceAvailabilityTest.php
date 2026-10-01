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
            $db->row=null;
            $db->rows=null;
            self::assertSame('UNAVAILABLE',(new DigiForge\AI\CostKpiReadModel())->snapshot()['query_state']);
            $db->last_error='read failed';
            self::assertSame('UNAVAILABLE',(new DigiForge\POD\ProviderStatusReadModel())->snapshot()['query_state']);
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }

    public function testCostQueriesResetStaleErrorsAndExposeIndependentStates(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='stale';
            public int $calls=0;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_row(string $sql,mixed $format):?array{$this->calls++;return ['quantity'=>2,'estimated_cost'=>1.5,'actual_cost'=>1.0];}
            public function get_results(string $sql,mixed $format):?array{$this->calls++;return [];}
        };
        $GLOBALS['wpdb']=$db;
        try {
            $cost=(new DigiForge\AI\CostKpiReadModel())->snapshot('digital');
            self::assertSame('AVAILABLE',$cost['query_state']);
            self::assertSame(['totals'=>'AVAILABLE','breakdown'=>'AVAILABLE'],$cost['query_states']);
            self::assertSame(2,$cost['quantity']);
        } finally {$GLOBALS['wpdb']=$previous;}
    }

    public function testCostBreakdownFailureCannotMasqueradeAsAvailableTotals(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_row(string $sql,mixed $format):?array{return ['quantity'=>2,'estimated_cost'=>1.5,'actual_cost'=>1.0];}
            public function get_results(string $sql,mixed $format):?array{$this->last_error='breakdown failed';return null;}
        };
        $GLOBALS['wpdb']=$db;
        try {
            $cost=(new DigiForge\AI\CostKpiReadModel())->snapshot('digital');
            self::assertSame('UNAVAILABLE',$cost['query_state']);
            self::assertSame(['totals'=>'AVAILABLE','breakdown'=>'UNAVAILABLE'],$cost['query_states']);
            self::assertSame(0,$cost['quantity']);
            self::assertSame([],$cost['breakdown']);
        } finally {$GLOBALS['wpdb']=$previous;}
    }
}
