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
    public function testProvenanceClearsStaleErrorsAcrossIndependentSources(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='stale';
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_results(string $sql,mixed $format):?array{return [];}
        };
        $GLOBALS['wpdb']=$db;
        try {
            $projection=(new DigiForge\POD\ProductionProvenanceIntegrityReadModel())->recent(20);
            self::assertSame('AVAILABLE',$projection['query_state']);
            self::assertSame('AVAILABLE',$projection['query_states']['bindings']);
            self::assertSame('AVAILABLE',$projection['query_states']['closures']);
            self::assertSame('AVAILABLE',$projection['query_states']['disagreements']);
            self::assertSame('AVAILABLE',$projection['query_states']['legacy']);
            self::assertSame([],$projection['items']);
        } finally {$GLOBALS['wpdb']=$previous;}
    }

    public function testProvenanceCurrentSourceFailureDoesNotBlockLaterIndependentReadsOrBecomeZero(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public int $resultCalls=0;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_results(string $sql,mixed $format):?array{
                $this->resultCalls++;
                if($this->resultCalls===1){$this->last_error='bindings failed';return null;}
                return [];
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $projection=(new DigiForge\POD\ProductionProvenanceIntegrityReadModel())->recent(20);
            self::assertSame('PARTIAL_UNAVAILABLE',$projection['query_state']);
            self::assertSame('UNAVAILABLE',$projection['query_states']['bindings']);
            self::assertSame('AVAILABLE',$projection['query_states']['closures']);
            self::assertSame('AVAILABLE',$projection['query_states']['disagreements']);
            self::assertSame('AVAILABLE',$projection['query_states']['legacy']);
            self::assertContains('bindings',$projection['unavailable_sources']);
            self::assertSame([],$projection['items']);
        } finally {$GLOBALS['wpdb']=$previous;}
    }

    public function testProvenanceCorrelationReadFailureMarksEvidenceUnavailableAndNeverObservesFalseCorrelation(): void
    {
        $previous=$GLOBALS['wpdb'];
        $auth=hash('sha256','correlation-query-failure');
        $db=new class($auth) {
            public string $prefix='wp_';
            public string $last_error='';
            public int $resultCalls=0;
            public int $rowCalls=0;
            public function __construct(private string $auth){}
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_results(string $sql,mixed $format):?array{
                $this->resultCalls++;
                if($this->resultCalls===1)return [['authorization_hash'=>$this->auth,'package_id'=>7,'package_hash'=>'bad','actual_package_hash'=>'good']];
                return [];
            }
            public function get_row(string $sql,mixed $format):?array{
                $this->rowCalls++;
                if($this->rowCalls===1){$this->last_error='outcome failed';return null;}
                return null;
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $projection=(new DigiForge\POD\ProductionProvenanceIntegrityReadModel())->recent(20);
            self::assertSame('PARTIAL_UNAVAILABLE',$projection['query_state']);
            self::assertSame('UNAVAILABLE',$projection['query_states']['outcome_correlation']);
            self::assertSame('EVIDENCE_UNAVAILABLE',$projection['items'][0]['operator_state']);
            self::assertSame('UNAVAILABLE',$projection['items'][0]['evidence_state']);
            self::assertNull($projection['items'][0]['correlation_hash']);
            self::assertSame(0,$projection['open_count']);
            self::assertFalse($projection['retry_permitted']);
            self::assertFalse($projection['external_execution_authorized']);
        } finally {$GLOBALS['wpdb']=$previous;}
    }

}
