<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionProvenanceIntegrityFocusTest extends TestCase
{
    public function testMalformedReferenceNeverQueriesAndDatabaseErrorFailsClosed(): void
    {
        require_once __DIR__.'/../../includes/Database/Tables.php';
        require_once __DIR__.'/../../includes/POD/ProductionProvenanceIntegrityFocusReadModel.php';
        $previous=$GLOBALS['wpdb']??null;
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public array $queries=[];
            public function prepare(string $sql,string $value):string{return str_replace('%s',"'".$value."'",$sql);}
            public function get_row(string $sql,mixed $format):?array {
                $this->queries[]=$sql;
                $this->last_error='fixture query failure';
                return null;
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new \DigiForge\POD\ProductionProvenanceIntegrityFocusReadModel();
            $invalid=$model->byAuthorizationHash('not-a-hash');
            self::assertSame('INVALID_REFERENCE',$invalid['lookup_state']);
            self::assertArrayNotHasKey('open_count',$invalid);
            self::assertSame([],$db->queries);
            $unavailable=$model->byAuthorizationHash(str_repeat('a',64));
            self::assertSame('QUERY_UNAVAILABLE',$unavailable['lookup_state']);
            self::assertArrayNotHasKey('open_count',$unavailable);
            self::assertSame([], $unavailable['items']);
            self::assertCount(1,$db->queries);
            self::assertFalse($unavailable['retry_permitted']);
            self::assertFalse($unavailable['external_execution_authorized']);
        } finally { $GLOBALS['wpdb']=$previous; }
    }
}
