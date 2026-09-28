<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionProvenanceIntegrityHistoryTest extends TestCase
{
    public function testInvalidReferenceAndCountQueryFailureNeverReportZero(): void
    {
        require_once __DIR__.'/../../includes/Database/Tables.php';
        require_once __DIR__.'/../../includes/POD/ProductionProvenanceIntegrityHistoryReadModel.php';
        $previous=$GLOBALS['wpdb']??null;
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public array $queries=[];
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_var(string $sql):?string {
                $this->queries[]=$sql;
                $this->last_error='fixture failure';
                return null;
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new \DigiForge\POD\ProductionProvenanceIntegrityHistoryReadModel();
            $invalid=$model->byAuthorizationHash('invalid');
            self::assertSame('INVALID_REFERENCE',$invalid['lookup_state']);
            self::assertArrayNotHasKey('recorded_count',$invalid);
            self::assertSame([],$db->queries);
            $badCursor=$model->byAuthorizationHash(str_repeat('a',64),null,-1);
            self::assertSame('INVALID_CURSOR',$badCursor['lookup_state']);
            self::assertArrayNotHasKey('recorded_count',$badCursor);
            self::assertSame([],$db->queries);
            $unavailable=$model->byAuthorizationHash(str_repeat('a',64));
            self::assertSame('QUERY_UNAVAILABLE',$unavailable['lookup_state']);
            self::assertArrayNotHasKey('recorded_count',$unavailable);
            self::assertCount(1,$db->queries);
            self::assertFalse($unavailable['retry_permitted']);
            self::assertFalse($unavailable['external_execution_authorized']);
        } finally { $GLOBALS['wpdb']=$previous; }
    }

    public function testComparisonQueryFailureOmitsComparisonCounts(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
        require_once __DIR__.'/../../includes/Database/Tables.php';
        require_once __DIR__.'/../../includes/POD/ProductionProvenanceIntegrityHistoryReadModel.php';
        $previous=$GLOBALS['wpdb']??null;
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            private int $calls=0;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_var(string $sql):?string {
                $this->calls++;
                if ($this->calls===1) return '2';
                $this->last_error='comparison query failure';
                return null;
            }
            public function get_results(string $sql,mixed $format):array{return [];}
        };
        $GLOBALS['wpdb']=$db;
        try {
            $result=(new \DigiForge\POD\ProductionProvenanceIntegrityHistoryReadModel())->byAuthorizationHash(
                str_repeat('a',64),[str_repeat('b',64)]
            );
            self::assertSame('QUERY_UNAVAILABLE',$result['comparison_state']);
            self::assertSame(2,$result['recorded_count']);
            self::assertArrayNotHasKey('recorded_live_overlap_count',$result);
            self::assertArrayNotHasKey('recorded_without_live_correlation_count',$result);
            self::assertFalse($result['retry_permitted']);
        } finally { $GLOBALS['wpdb']=$previous; }
    }
}
