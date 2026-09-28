<?php
declare(strict_types=1);

final class OperationalExceptionAvailabilityTest extends WP_UnitTestCase
{
    public function testFailedExceptionReadsRemainDistinctFromEmptyResults(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public bool $failAll=true;
            public bool $failLedger=false;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_results(string $sql,mixed $format):?array {
                $failed=$this->failAll||($this->failLedger&&str_contains($sql,'finance_ledger'));
                $this->last_error=$failed?'read failed':'';
                return $failed?null:[];
            }
            public function get_var(string $sql):?string {
                $this->last_error=$this->failAll?'read failed':'';
                return $this->failAll?null:'0';
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new DigiForge\Operations\OperationalExceptionReadModel();
            $failed=$model->snapshot();
            self::assertNull($failed['fulfillment_total']);
            self::assertSame(['fulfillment'=>'UNAVAILABLE','finance_ledger'=>'UNAVAILABLE','tax'=>'UNAVAILABLE'],$failed['query_state']);
            $db->failAll=false;
            $empty=$model->snapshot();
            self::assertSame(0,$empty['fulfillment_total']);
            self::assertSame(['fulfillment'=>'AVAILABLE','finance_ledger'=>'AVAILABLE','tax'=>'AVAILABLE'],$empty['query_state']);
            $db->failLedger=true;
            $partial=$model->snapshot();
            self::assertSame('UNAVAILABLE',$partial['query_state']['finance_ledger']);
            self::assertSame('AVAILABLE',$partial['query_state']['tax']);
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }
}
