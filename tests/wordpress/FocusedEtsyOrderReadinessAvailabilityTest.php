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
            $orderSql=$wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::orders().' WHERE id=%d',$order['id']);
            $linesSql=$wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::order_line_items().' WHERE order_id=%d ORDER BY id',$order['id']);
            $storedOrder=$wpdb->get_row($orderSql,ARRAY_A);$storedLines=$wpdb->get_results($linesSql,ARRAY_A);
            // Target all five readiness counts by purpose: nested current-input
            // evidence queries may add counts without replacing any of these reads.
            foreach(['count:lines','count:invalid','count:digital','count:physical','count:unmapped','managed_history','managed_head','lines','inputs'] as $position){
                $hit=false;
                $fault=static function(string $sql)use($position,&$hit):string{
                    $count=str_starts_with($sql,'SELECT COUNT(*) FROM '.DigiForge\Database\Tables::order_line_items());
                    $selected=$count&&match($position){
                        'count:lines'=>str_contains($sql,' WHERE order_id='),
                        'count:invalid'=>str_contains($sql,"li.validation_status<>'VALIDATED'"),
                        'count:digital'=>str_contains($sql,' AND EXISTS (SELECT 1 FROM '.DigiForge\Database\Tables::digital_products()),
                        'count:physical'=>str_contains($sql,' AND (NOT EXISTS ')&&!str_contains($sql,'li.provider_mapping_id=0'),
                        'count:unmapped'=>str_contains($sql,'li.provider_mapping_id=0'),
                        default=>false,
                    };
                    if($position==='managed_history')$selected=str_starts_with($sql,'SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log())&&str_contains($sql,"event_type IN ('photo_input_selected','photo_editor_changed')");
                    if($position==='managed_head')$selected=str_starts_with($sql,'SELECT option_value FROM ')&&str_contains($sql,"'df_photo_head_");
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
            self::assertSame($storedOrder,$wpdb->get_row($orderSql,ARRAY_A));self::assertSame($storedLines,$wpdb->get_results($linesSql,ARRAY_A));
            self::assertSame($baseline,$repo->readinessSnapshot((int)$order['id']));
        } finally {
            $this->restoreOrderFixture();
        }
    }
}
