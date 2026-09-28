<?php
declare(strict_types=1);

final class OrderReconciliationEvidenceAvailabilityTest extends WP_UnitTestCase
{
    public function testReconciliationCountFailureIsExplicitlyUnavailable(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public int $countCall=0;
            public int $failAt=1;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_row(string $sql,mixed $format):array {
                $this->last_error='';
                return ['id'=>17,'external_order_reference'=>'EXT-17','shop_reference'=>'shop-1','state'=>'APPROVED'];
            }
            public function get_var(string $sql):?string {
                $this->countCall++;
                $failed=$this->countCall===$this->failAt;
                $this->last_error=$failed?'query failed':'';
                return $failed?null:'0';
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new DigiForge\Orders\ReconciliationReadModel();
            for($position=1;$position<=3;$position++){
                $db->countCall=0;
                $db->failAt=$position;
                $projection=$model->forOrder(17);
                self::assertSame('UNAVAILABLE',$projection['query_state']);
                self::assertFalse($projection['reconciled']);
                self::assertSame('RECONCILIATION_EVIDENCE_UNAVAILABLE',$projection['reason']);
                self::assertNull($projection['line_item_count']);
                self::assertFalse($projection['fulfillment_authorized']);
                self::assertFalse($projection['external_execution_performed']);
            }
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }

    public function testDiscrepancyFailureDoesNotMasqueradeAsZeroDiscrepancies(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public ?array $rows=[];
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_results(string $sql,mixed $format):?array{return $this->rows;}
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new DigiForge\Orders\ReconciliationReadModel();
            $db->rows=[];
            $available=$model->discrepancyProjection(17,25);
            self::assertSame('AVAILABLE',$available['query_state']);
            self::assertSame([],$available['items']);

            $db->rows=null;
            $db->last_error='query failed';
            $unavailable=$model->discrepancyProjection(17,25);
            self::assertSame('UNAVAILABLE',$unavailable['query_state']);
            self::assertSame([],$unavailable['items']);
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }
}
