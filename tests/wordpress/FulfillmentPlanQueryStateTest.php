<?php
declare(strict_types=1);

final class FulfillmentPlanQueryStateTest extends WP_UnitTestCase
{
    public function testDatabaseFailureIsDistinguishedFromAnEmptyPlanList(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public ?array $rows=null;
            public function prepare(string $query,mixed ...$args):string{return $query;}
            public function get_results(string $query,mixed $format):?array{return $this->rows;}
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new DigiForge\Portal\OperationalDepthReadModel();
            $state=null;
            self::assertSame([],$model->fulfillmentProviders(50,$state));
            self::assertSame('UNAVAILABLE',$state);
            $db->rows=[];
            $state=null;
            self::assertSame([],$model->fulfillmentProviders(50,$state));
            self::assertSame('AVAILABLE',$state);
            $db->last_error='query failed';
            $state=null;
            self::assertSame([],$model->fulfillmentProviders(50,$state));
            self::assertSame('UNAVAILABLE',$state);
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }
}
