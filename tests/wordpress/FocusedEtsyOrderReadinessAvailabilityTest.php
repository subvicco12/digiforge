<?php
declare(strict_types=1);

final class FocusedEtsyOrderReadinessAvailabilityTest extends WP_UnitTestCase
{
    public function testFocusedEtsyReadDistinguishesQueryFailureFromMissingRecord(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public ?array $row=null;
            public bool $failQuery=false;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_row(string $sql,mixed $format):?array{
                $this->last_error=$this->failQuery?'query failed':'';
                return $this->row;
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new DigiForge\Listings\EtsyReconciliationOperatorReadModel();
            $state=null;
            self::assertNull($model->byId(17,$state));
            self::assertSame('NOT_FOUND',$state);
            $db->last_error='stale error';
            $db->failQuery=true;
            $state=null;
            self::assertNull($model->byId(17,$state));
            self::assertSame('UNAVAILABLE',$state);
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }

    public function testEveryRequiredReadinessCountFailsClosedOnDatabaseFailure(): void
    {
        $previous=$GLOBALS['wpdb'];
        $db=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public int $countCall=0;
            public int $failAt=1;
            public function prepare(string $sql,mixed ...$args):string{return $sql;}
            public function get_row(string $sql,mixed $format):array {
                return ['id'=>17,'state'=>'APPROVED','approved_by'=>1,
                    'approved_at'=>'2026-09-28 00:00:00','personalization_required'=>1];
            }
            public function get_var(string $sql):?string {
                $this->countCall++;
                $failed=$this->countCall===$this->failAt;
                $this->last_error=$failed?'query failed':'';
                return $failed?null:($this->countCall===1?'1':'0');
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $repo=new DigiForge\Orders\Repository();
            for($position=1;$position<=5;$position++){
                $db->failAt=$position;
                $db->countCall=0;
                $result=$repo->readiness(17);
                self::assertWPError($result);
                self::assertSame('order_readiness_evidence_unavailable',$result->get_error_code());
            }
        } finally {
            $GLOBALS['wpdb']=$previous;
        }
    }
}
