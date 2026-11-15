<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/NativeBusinessOrderFixture.php';

final class FocusedEtsyOrderReadinessAvailabilityTest extends WP_UnitTestCase
{
    use NativeBusinessOrderFixture;
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
        DigiForge\Core\Activator::activate();$this->bootOrderFixture();
        try {
            [, $mapping,$version]=$this->product();
            $repo=new DigiForge\Orders\Repository();
            $order=PrintifyApiApprovalFixture::checked($repo->createOrder(['channel'=>'etsy','environment'=>'test','shop_reference'=>'personalized_pod','personalization_required'=>false]));
            PrintifyApiApprovalFixture::checked($repo->addLineItem(['order_id'=>$order['id'],'product_version_id'=>$version,'provider_mapping_id'=>$mapping]));
            foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)PrintifyApiApprovalFixture::checked($repo->transition('order',(int)$order['id'],$state));
            $baseline=$repo->readinessSnapshot((int)$order['id']);self::assertNotWPError($baseline);self::assertTrue($baseline['ready']);
            global $wpdb;
            // Real wpdb must exercise both the existing five count reads and the
            // newer current-input reads; a count-only double cannot model them.
            foreach(['count:1','count:2','count:3','count:4','count:5','lines','inputs'] as $position){
                $calls=0;$hit=false;
                $fault=static function(string $sql)use($position,&$calls,&$hit):string{
                    $selected=str_starts_with($sql,'SELECT COUNT(*) FROM ')&&$position==='count:'.(++$calls);
                    if($position==='lines')$selected=str_starts_with($sql,'SELECT li.id,li.product_version_id,li.provider_mapping_id,li.environment,li.validation_status,o.channel');
                    if($position==='inputs')$selected=str_starts_with($sql,'SELECT ps.id,ps.personalization_schema_id,ps.canonical_payload');
                    if(!$selected)return $sql;$hit=true;return 'SELECT * FROM synthetic_missing_readiness_evidence';
                };
                $prior=$wpdb->suppress_errors(true);add_filter('query',$fault);
                try {$result=$repo->readinessSnapshot((int)$order['id']);}
                finally {remove_filter('query',$fault);$wpdb->suppress_errors($prior);}
                self::assertTrue($hit,$position);self::assertWPError($result,$position);
                self::assertSame('order_readiness_evidence_unavailable',$result->get_error_code(),$position);
            }
        } finally {
            $this->restoreOrderFixture();
        }
    }
}
