<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyWebhookDeduplicator;
use DigiForge\Listings\EtsyWebhookIntake;
use DigiForge\Orders\Repository;

/** Synthetic signed local events and historical listing evidence; no live execution. */
final class EtsyWebhookPersistenceAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(0);
    }

    private function signed(array $payload): array
    {
        $body=(string)wp_json_encode($payload);
        $event='persistence-'.wp_generate_uuid4();$now=time();$key='synthetic-persistence-secret';
        return [$event,$body,['webhook-id'=>$event,'webhook-timestamp'=>(string)$now,'webhook-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$event.'.'.$now.'.'.$body,$key,true))],'whsec_'.base64_encode($key),$now];
    }

    private function accept(array $signed): array|WP_Error
    {
        return (new EtsyWebhookIntake(new EtsyWebhookDeduplicator()))->accept($signed[1],$signed[2],$signed[3],$signed[4]);
    }

    private function assertLedger(string $event,string $status): void
    {
        global $wpdb;
        self::assertSame($status,$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event)));
        self::assertSame($status,$wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}digiforge_idempotency WHERE operation_key=%s",'etsy_webhook:'.hash('sha256',$event))));
    }

    /** @dataProvider malformedItems */
    public function testMalformedPaidEntriesNeverProduceValidatedZeroLineOrders(array $items): void
    {
        $signed=$this->signed(['event_type'=>'order.paid','order_id'=>(string)wp_rand(100000,999999),'shop_id'=>'712002','line_items'=>$items]);
        $result=$this->accept($signed);self::assertNotWPError($result);
        self::assertSame('ETSY_ORDER_REVIEW_REQUIRED',$result['state']);
        self::assertSame('REVIEW_REQUIRED',$result['persisted_order_state']);
        self::assertSame(0,$result['line_item_count']);self::assertTrue($result['unmatched_line_items']);
        self::assertSame('REVIEW_REQUIRED',(new Repository())->findByExternalReference(json_decode($signed[1],true)['order_id'],'712002')['state']);
        $this->assertLedger($signed[0],'PROCESSED');self::assertFalse($result['fulfillment_authorized']);
    }

    public function malformedItems(): array
    {
        return ['null'=>[[null]],'scalar'=>[['bad']],'mixed scalars'=>[[false,7,'bad']]];
    }

    private function historicalDigitalListing(): int
    {
        global $wpdb;$now=current_time('mysql',true);$times=['created_at'=>$now,'updated_at'=>$now];
        self::assertSame(1,$wpdb->insert(Tables::product_versions(),$times+['product_id'=>1,'version_label'=>'webhook-'.wp_generate_uuid4(),'state'=>'APPROVED']));$version=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::digital_products(),$times+['product_id'=>1,'product_version_id'=>$version,'name'=>'Synthetic historical digital','category'=>'fixture']));
        self::assertSame(1,$wpdb->insert(Tables::listings(),$times+['product_version_id'=>$version,'channel'=>'etsy','environment'=>'production','shop_reference'=>'712002','title'=>'Synthetic historical listing','state'=>'APPROVED']));$listing=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::etsy_intents(),$times+['listing_id'=>$listing,'environment'=>'production','intent_type'=>'PREPARE_DRAFT','state'=>'APPROVED_INTENT']));$intent=(int)$wpdb->insert_id;$hash=hash('sha256','synthetic historical webhook lookup');
        self::assertSame(1,$wpdb->insert($wpdb->prefix.'digiforge_etsy_operations',$times+['shop_reference'=>'712002','intent_id'=>$intent,'draft_package_id'=>0,'operation_type'=>'CREATE_DRAFT','state'=>'CONFIRMED_SUCCESS','idempotency_key'=>wp_generate_uuid4(),'request_fingerprint'=>$hash,'authorization_hash'=>$hash,'evidence_hash'=>$hash,'external_reference'=>'712003']));
        return $listing;
    }

    public function testMixedValidAndMalformedPaidEntriesPreserveValidLineAndRequireReview(): void
    {
        $listing=$this->historicalDigitalListing();
        $signed=$this->signed(['event_type'=>'order.paid','order_id'=>'712004','shop_id'=>'712002','line_items'=>[['listing_id'=>'712003','quantity'=>2,'price'=>12,'currency'=>'USD'],null]]);
        $result=$this->accept($signed);self::assertNotWPError($result);
        self::assertSame('REVIEW_REQUIRED',$result['persisted_order_state']);self::assertSame(1,$result['line_item_count']);self::assertTrue($result['unmatched_line_items']);
        global $wpdb;$line=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE order_id=%d',$result['order_id']),ARRAY_A);
        self::assertSame($listing,(int)$line['listing_id']);self::assertSame(2,(int)$line['quantity']);self::assertSame('VALIDATED',$line['validation_status']);
        $this->assertLedger($signed[0],'PROCESSED');self::assertFalse($result['fulfillment_authorized']);
    }

    public function testCompletedPaidOrderRemainsIdempotentAcrossSignedEventIds(): void
    {
        $this->historicalDigitalListing();$payload=['event_type'=>'order.paid','order_id'=>'712006','shop_id'=>'712002','line_items'=>[['listing_id'=>'712003','quantity'=>1]]];
        $first=$this->signed($payload);$result=$this->accept($first);self::assertNotWPError($result);self::assertSame('VALIDATED',$result['persisted_order_state']);self::assertSame(1,$result['line_item_count']);$this->assertLedger($first[0],'PROCESSED');
        $second=$this->signed($payload);$again=$this->accept($second);self::assertNotWPError($again);self::assertSame('ETSY_ORDER_ALREADY_RECEIVED',$again['state']);self::assertSame($result['order_id'],$again['order_id']);$this->assertLedger($second[0],'PROCESSED');
        global $wpdb;self::assertSame(1,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::order_line_items().' WHERE order_id=%d',$result['order_id'])));
        self::assertSame('VALIDATED',(new Repository())->findByExternalReference('712006','712002')['state']);
    }

    public function testChangedSignedBodyCannotReuseFailedEventIdentity(): void
    {
        $original=$this->signed(['event_type'=>'order.paid','order_id'=>'712008','shop_id'=>'712002','line_items'=>[]]);
        global $wpdb;$table=Tables::orders();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'UPDATE `'.$table.'`')?str_replace('`'.$table.'`','`'.$table.'_synthetic_missing`',$sql):$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$failed=$this->accept($original);}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        self::assertWPError($failed);$this->assertLedger($original[0],'FAILED');
        $before=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$original[0]),ARRAY_A);
        $dedupBefore=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}digiforge_idempotency WHERE operation_key=%s",'etsy_webhook:'.hash('sha256',$original[0])),ARRAY_A);
        $changed=$original;$changed[1]=(string)wp_json_encode(['event_type'=>'order.paid','order_id'=>'712009','shop_id'=>'712010','line_items'=>[]]);$changed[2]['webhook-signature']='v1,'.base64_encode(hash_hmac('sha256',$changed[0].'.'.$changed[4].'.'.$changed[1],'synthetic-persistence-secret',true));
        $result=$this->accept($changed);self::assertWPError($result);self::assertSame('webhook_evidence_conflict',$result->get_error_code());
        self::assertNull((new Repository())->findByExternalReference('712009','712010'));
        self::assertSame($before,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$original[0]),ARRAY_A));
        self::assertSame($dedupBefore,$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}digiforge_idempotency WHERE operation_key=%s",'etsy_webhook:'.hash('sha256',$original[0])),ARRAY_A));
        self::assertSame(hash('sha256',$original[1]),$before['body_sha256']);
        self::assertSame('712002',$before['shop_reference']);
        $renewed=$original;$renewed[4]++;$renewed[2]['webhook-timestamp']=(string)$renewed[4];$renewed[2]['webhook-signature']='v1,'.base64_encode(hash_hmac('sha256',$renewed[0].'.'.$renewed[4].'.'.$renewed[1],'synthetic-persistence-secret',true));
        $verified=DigiForge\Listings\EtsyWebhookVerifier::verify($renewed[1],$renewed[2],$renewed[3],$renewed[4]);self::assertNotWPError($verified);
        $replay=(new DigiForge\Listings\WebhookEvidenceRepository())->recordVerified($verified,$renewed[1],$renewed[2]);self::assertNotWPError($replay);self::assertTrue($replay['idempotent_replay']);
        unset($replay['idempotent_replay']);self::assertSame($before,$replay);
    }

    public function testRepeatedFailedLedgerWriteRequiresExactDurableFailedEvidence(): void
    {
        $signed=$this->signed(['event_type'=>'order.paid','order_id'=>'712007','shop_id'=>'712002','line_items'=>[]]);
        $verified=DigiForge\Listings\EtsyWebhookVerifier::verify($signed[1],$signed[2],$signed[3],$signed[4]);self::assertNotWPError($verified);
        $ledger=new DigiForge\Listings\WebhookEvidenceRepository();self::assertNotWPError($ledger->recordVerified($verified,$signed[1],$signed[2]));
        self::assertTrue($ledger->markFailed($signed[0]));self::assertTrue($ledger->markFailed($signed[0]));self::assertFalse($ledger->markFailed('synthetic-missing-event'));
        global $wpdb;$table=Tables::webhook_evidence();$event=$signed[0];$unchangedUpdates=0;$failedReads=0;
        $fault=static function(string $sql)use($table,$event,&$unchangedUpdates,&$failedReads):string{
            if(!str_contains($sql,$table)||!str_contains($sql,"'etsy'")||!str_contains($sql,"'".$event."'"))return $sql;
            if(str_starts_with(ltrim($sql),'UPDATE `'.$table.'`')&&str_contains($sql,"`processing_status` = 'FAILED'")){
                // Keep this already-FAILED row's timestamp unchanged so the real
                // update affects zero rows even when the wall clock advances.
                $sql=preg_replace("/`processed_at` = '[^']*'/",'`processed_at` = `processed_at`',$sql,1,$count);
                $unchangedUpdates+=$count;return $sql;
            }
            if(str_starts_with(ltrim($sql),'SELECT')){++$failedReads;return str_replace($table,$table.'_synthetic_missing',$sql);}
            return $sql;
        };
        $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{self::assertFalse($ledger->markFailed($event));}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        self::assertSame(1,$unchangedUpdates);self::assertSame(1,$failedReads);
    }

    public function testPaidRetryCannotMarkIncompleteReceivedOrderProcessed(): void
    {
        $signed=$this->signed(['event_type'=>'order.paid','order_id'=>'712005','shop_id'=>'712002','line_items'=>[]]);
        global $wpdb;$table=Tables::orders();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'UPDATE `'.$table.'`')?str_replace('`'.$table.'`','`'.$table.'_synthetic_missing`',$sql):$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$failed=$this->accept($signed);}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        self::assertWPError($failed);self::assertSame('transition_failed',$failed->get_error_code());
        self::assertSame('RECEIVED',(new Repository())->findByExternalReference('712005','712002')['state']);$this->assertLedger($signed[0],'FAILED');
        $retry=$this->accept($signed);self::assertWPError($retry);
        self::assertSame('digiforge_etsy_order_webhook_incomplete_order',$retry->get_error_code());
        self::assertTrue($retry->get_error_data()['reconciliation_required']);$this->assertLedger($signed[0],'FAILED');
        self::assertSame('RECEIVED',(new Repository())->findByExternalReference('712005','712002')['state']);
    }

    /** @dataProvider cancellationStates */
    public function testSuccessfulCancellationPersistsAppropriateState(string $from,string $to): void
    {
        $order=$this->orderInState($from);$signed=$this->signed(['event_type'=>'order.canceled','order_id'=>$order['external_order_reference'],'shop_id'=>'712002']);
        $result=$this->accept($signed);self::assertNotWPError($result);self::assertSame('ETSY_ORDER_CANCELLATION_RECORDED',$result['state']);
        self::assertSame($to,(new Repository())->findByExternalReference($order['external_order_reference'],'712002')['state']);
        $this->assertLedger($signed[0],'PROCESSED');self::assertFalse($result['fulfillment_authorized']);self::assertFalse($result['external_execution_performed']);
    }

    public function cancellationStates(): array
    {
        return ['received'=>['RECEIVED','REJECTED'],'validated'=>['VALIDATED','REJECTED'],'review'=>['REVIEW_REQUIRED','REJECTED'],'hold'=>['ON_HOLD','REJECTED'],'approved'=>['APPROVED','ON_HOLD']];
    }

    private function orderInState(string $state): array
    {
        $order=(new Repository())->createOrder(['channel'=>'etsy','environment'=>'production','shop_reference'=>'712002','external_order_reference'=>(string)wp_rand(1000000,9999999)]);self::assertNotWPError($order);
        if($state!=='RECEIVED'){global $wpdb;self::assertSame(1,$wpdb->update(Tables::orders(),['state'=>$state],['id'=>$order['id']]));}
        return $order;
    }

    /** @dataProvider cancellationStates */
    public function testFailedCancellationTransitionCannotBeMarkedProcessed(string $from,string $to): void
    {
        $order=$this->orderInState($from);$signed=$this->signed(['event_type'=>'order.canceled','order_id'=>$order['external_order_reference'],'shop_id'=>'712002']);
        global $wpdb;$table=Tables::orders();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'UPDATE `'.$table.'`')?str_replace('`'.$table.'`','`'.$table.'_synthetic_missing`',$sql):$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$result=$this->accept($signed);}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        self::assertWPError($result);self::assertSame('transition_failed',$result->get_error_code());
        self::assertSame($from,(new Repository())->findByExternalReference($order['external_order_reference'],'712002')['state']);$this->assertLedger($signed[0],'FAILED');
        $retry=$this->accept($signed);self::assertNotWPError($retry);self::assertSame($to,(new Repository())->findByExternalReference($order['external_order_reference'],'712002')['state']);$this->assertLedger($signed[0],'PROCESSED');
    }
}
