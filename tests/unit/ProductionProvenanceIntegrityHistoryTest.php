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
            $unavailable=$model->byAuthorizationHash(str_repeat('a',64));
            self::assertSame('QUERY_UNAVAILABLE',$unavailable['lookup_state']);
            self::assertArrayNotHasKey('recorded_count',$unavailable);
            self::assertCount(1,$db->queries);
            self::assertFalse($unavailable['retry_permitted']);
            self::assertFalse($unavailable['external_execution_authorized']);
        } finally { $GLOBALS['wpdb']=$previous; }
    }
}
