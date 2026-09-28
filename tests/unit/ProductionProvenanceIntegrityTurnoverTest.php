<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionProvenanceIntegrityTurnoverTest extends TestCase
{
    public function testInvalidReferenceAndQueryErrorDoNotInferHistoryOrResolution(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
        require_once __DIR__.'/../../includes/Database/Tables.php';
        require_once __DIR__.'/../../includes/POD/ProductionProvenanceIntegrityTurnoverReadModel.php';
        $previous=$GLOBALS['wpdb']??null;
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public array $queries=[];
            public function prepare(string $sql,string $hash):string{return $sql;}
            public function get_row(string $sql,mixed $format):?array {
                $this->queries[]=$sql;
                $this->last_error='fixture failure';
                return null;
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new \DigiForge\POD\ProductionProvenanceIntegrityTurnoverReadModel();
            $invalid=$model->byAuthorizationHash('invalid');
            self::assertSame('INVALID_REFERENCE',$invalid['lookup_state']);
            self::assertSame([],$db->queries);
            $unavailable=$model->byAuthorizationHash(str_repeat('a',64));
            self::assertSame('QUERY_UNAVAILABLE',$unavailable['lookup_state']);
            self::assertArrayNotHasKey('distinct_anomaly_types',$unavailable);
            self::assertCount(1,$db->queries);
            self::assertFalse($unavailable['resolution_inferred']);
            self::assertFalse($unavailable['external_execution_authorized']);
        } finally { $GLOBALS['wpdb']=$previous; }
    }
}
