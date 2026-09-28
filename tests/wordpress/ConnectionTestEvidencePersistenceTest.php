<?php
declare(strict_types=1);

final class ConnectionTestEvidencePersistenceTest extends WP_UnitTestCase
{
    public function testSuccessfulProviderResponseCannotClaimDurableEvidenceWhenMetadataOrWriteFails(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public bool $hasMetadata=false;
            public int $updates=0;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_row(string $sql,mixed $format):array {
                return ['config'=>'{}','status'=>'DISCONNECTED'];
            }
            public function get_results(string $sql,mixed $format):array {
                return $this->hasMetadata
                    ? [['secret_name'=>'api_key','fingerprint'=>'abcdef0123456789']]
                    : [];
            }
            public function update(string $table,array $data,array $where):bool {
                $this->updates++;
                return false;
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $record=new ReflectionMethod(DigiForge\Integrations\ConnectionTester::class,'record');
            $tester=new DigiForge\Integrations\ConnectionTester();
            self::assertFalse($record->invoke($tester,23,true,['http_status'=>200],true));
            self::assertSame(0,$db->updates);
            $db->hasMetadata=true;
            self::assertFalse($record->invoke($tester,23,true,['http_status'=>200],true));
            self::assertSame(1,$db->updates);
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }
}
