<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionProvenanceIntegrityTypeTest extends TestCase
{
    public function testInvalidHashAndQueryFailureNeverShowZeroBreakdown(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
        require_once __DIR__.'/../../includes/Database/Tables.php';
        require_once __DIR__.'/../../includes/POD/ProductionProvenanceIntegrityTypeReadModel.php';
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
            $model=new \DigiForge\POD\ProductionProvenanceIntegrityTypeReadModel();
            $invalid=$model->byAuthorizationHash('invalid');
            self::assertSame('INVALID_REFERENCE',$invalid['lookup_state']);
            self::assertSame([],$db->queries);
            $failed=$model->byAuthorizationHash(str_repeat('a',64));
            self::assertSame('QUERY_UNAVAILABLE',$failed['lookup_state']);
            self::assertArrayNotHasKey('recorded_count',$failed);
            self::assertArrayNotHasKey('type_counts',$failed);
            self::assertCount(1,$db->queries);
            self::assertFalse($failed['retry_permitted']);
        } finally { $GLOBALS['wpdb']=$previous; }
    }

    public function testInconsistentAggregateResponseFailsClosedInsteadOfDisplayingNegativeOtherCount(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
        require_once __DIR__.'/../../includes/Database/Tables.php';
        require_once __DIR__.'/../../includes/POD/ProductionProvenanceIntegrityTypeReadModel.php';
        $previous=$GLOBALS['wpdb']??null;
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public function prepare(string $sql,string $hash):string{return $sql;}
            public function get_row(string $sql,mixed $format):array {
                return ['recorded_count'=>'2','type_0'=>'3','type_1'=>'0','type_2'=>'0','type_3'=>'0'];
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $result=(new \DigiForge\POD\ProductionProvenanceIntegrityTypeReadModel())
                ->byAuthorizationHash(str_repeat('a',64));
            self::assertSame('INCONSISTENT_EVIDENCE',$result['lookup_state']);
            self::assertArrayNotHasKey('recorded_count',$result);
            self::assertArrayNotHasKey('type_counts',$result);
            self::assertFalse($result['retry_permitted']);
            self::assertFalse($result['external_execution_authorized']);
        } finally { $GLOBALS['wpdb']=$previous; }
    }
}
