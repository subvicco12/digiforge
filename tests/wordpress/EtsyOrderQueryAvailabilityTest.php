<?php
declare(strict_types=1);

final class EtsyOrderQueryAvailabilityTest extends WP_UnitTestCase
{
    public function testFailedRecentReadsAreDistinctFromEmptyEtsyAndOrderWindows(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public ?array $rows=null;
            public bool $fail=false;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_results(string $sql,mixed $format):?array{if($this->fail){$this->last_error='read failed';return null;}return $this->rows;}
        };
        $GLOBALS['wpdb']=$db;
        try {
            $etsy=new DigiForge\Listings\EtsyReconciliationOperatorReadModel();
            $orders=new DigiForge\Orders\OperationsReadModel();
            $state=null;
            self::assertSame([],$etsy->recent(50,$state));
            self::assertSame('UNAVAILABLE',$state);
            $state=null;
            self::assertSame([],$orders->recent(50,$state));
            self::assertSame('UNAVAILABLE',$state);

            $db->rows=[];
            $state=null;
            self::assertSame([],$etsy->recent(50,$state));
            self::assertSame('AVAILABLE',$state);
            $state=null;
            self::assertSame([],$orders->recent(50,$state));
            self::assertSame('AVAILABLE',$state);

            $db->fail=true;
            $state=null;
            self::assertSame([],$etsy->recent(50,$state));
            self::assertSame('UNAVAILABLE',$state);
            $state=null;
            self::assertSame([],$orders->recent(50,$state));
            self::assertSame('UNAVAILABLE',$state);

            $db->fail=false;$db->last_error='stale prior failure';
            $state=null;
            self::assertSame([],$orders->recent(50,$state));
            self::assertSame('AVAILABLE',$state);
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }
}
