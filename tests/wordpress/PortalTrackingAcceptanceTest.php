<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Portal\Portal;

/** Signed event receipt visibility, never live carrier or authoritative fulfillment state. */
final class PortalTrackingAcceptanceTest extends WP_UnitTestCase
{
    private static array $fixtureIds=[];
    private int $httpCalls=0;
    private Closure $http;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        // Clean only exact signed synthetic event IDs captured by this class in this process.
        global $wpdb;foreach(self::$fixtureIds as $eventId)$wpdb->delete(Tables::etsy_order_event_evidence(),['event_id'=>$eventId]);self::$fixtureIds=[];
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);wp_create_nonce('tracking-local');$this->http=function(){++$this->httpCalls;return new WP_Error('offline_tracking','HTTP forbidden.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown():void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all',true));self::assertFalse(Settings::get('automation_armed',false));parent::tearDown();
    }
    private function order(string $shop):array
    {
        $row=(new DigiForge\Orders\Repository())->createOrder(['channel'=>'etsy','environment'=>'production','shop_reference'=>$shop,'external_order_reference'=>'tracking-'.wp_generate_uuid4()]);self::assertNotWPError($row);return $row;
    }
    private function event(array $order,string $type='order.shipped'):array
    {
        $id='tracking-'.wp_generate_uuid4();self::$fixtureIds[]=$id;$now=time();$key='synthetic-tracking-key';$body=(string)wp_json_encode(['event_type'=>$type,'order_id'=>$order['external_order_reference'],'shop_id'=>$order['shop_reference'],'tracking_code'=>'private-tracking-code','carrier_name'=>'<script>alert(1)</script> Synthetic Carrier']);$headers=['webhook-id'=>$id,'webhook-timestamp'=>(string)$now,'webhook-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$id.'.'.$now.'.'.$body,$key,true))];$result=(new DigiForge\Listings\EtsyWebhookIntake(new DigiForge\Listings\EtsyWebhookDeduplicator()))->accept($body,$headers,'whsec_'.base64_encode($key),$now);return compact('id','now','key','body','headers','result');
    }
    private function render(string $shop='digital'):string
    {
        $prior=$_GET;try{$_GET=['df_view'=>'fulfillment','df_shop'=>$shop];return (new Portal())->render();}finally{$_GET=$prior;}
    }
    public function testSignedShipmentAndDeliveryReceiptsReachOnlyTheirStoredOrderShopWithoutChangingFulfillment():void
    {
        $own=$this->order('digital');$foreign=$this->order('jewelry');global $wpdb;$orderBefore=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$own['id']),ARRAY_A);$shipped=$this->event($own);$delivered=$this->event($own,'order.delivered');$other=$this->event($foreign);foreach([$shipped,$delivered,$other]as $event)self::assertNotWPError($event['result']);
        $html=$this->render();self::assertStringContainsString('Shipment / delivery event receipts',$html);self::assertStringContainsString($shipped['id'],$html);self::assertStringContainsString($delivered['id'],$html);self::assertStringNotContainsString($other['id'],$html);self::assertStringContainsString('#'.$own['id'],$html);self::assertStringContainsString('ORDER.SHIPPED',$html);self::assertStringContainsString('ORDER.DELIVERED',$html);self::assertStringContainsString(hash('sha256','private-tracking-code'),$html);self::assertStringNotContainsString('private-tracking-code',$html);self::assertStringNotContainsString('<script>alert(1)</script>',$html);self::assertStringContainsString('not live carrier tracking',$html);self::assertStringContainsString('authoritative fulfillment state',$html);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::etsy_order_event_evidence().' WHERE order_id=%d ORDER BY id',$own['id']),ARRAY_A);self::assertCount(2,$rows);foreach($rows as $row){self::assertSame($own['external_order_reference'],$row['external_order_reference']);self::assertSame('digital',$row['shop_reference']);self::assertSame(hash('sha256',$row['canonical_evidence']),$row['evidence_hash']);}self::assertSame($orderBefore,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$own['id']),ARRAY_A));$before=$rows;
        self::assertNotWPError((new DigiForge\Listings\EtsyWebhookIntake(new DigiForge\Listings\EtsyWebhookDeduplicator()))->accept($shipped['body'],$shipped['headers'],'whsec_'.base64_encode($shipped['key']),$shipped['now']));self::assertSame($before,$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::etsy_order_event_evidence().' WHERE order_id=%d ORDER BY id',$own['id']),ARRAY_A));
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$denied=$this->render();self::assertStringContainsString('explicit assignment',strtolower($denied));self::assertStringNotContainsString($shipped['id'],$denied);
    }
    public function testTamperedReceiptBindingAndFailedReadCannotDisplayVerifiedTracking():void
    {
        $own=$this->order('digital');$event=$this->event($own);self::assertNotWPError($event['result']);global $wpdb;$table=Tables::etsy_order_event_evidence();self::assertSame(1,$wpdb->update($table,['evidence_hash'=>str_repeat('0',64)],['event_id'=>$event['id']]));$html=$this->render();self::assertStringContainsString('Shipment / delivery event evidence unavailable',$html);self::assertStringNotContainsString($event['id'],$html);self::assertStringNotContainsString('No shipment / delivery event receipts',$html);
        $fault=static fn(string $sql):string=>str_starts_with($sql,'SELECT')&&str_contains($sql,$table)?str_replace($table,$table.'_synthetic_missing',$sql):$sql;$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);try{$html=$this->render();}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}self::assertStringContainsString('Shipment / delivery event evidence unavailable',$html);self::assertStringNotContainsString('No shipment / delivery event receipts',$html);
        $canonical=$wpdb->get_var($wpdb->prepare('SELECT canonical_evidence FROM '.$table.' WHERE event_id=%s',$event['id']));self::assertSame(1,$wpdb->update($table,['evidence_hash'=>hash('sha256',$canonical)],['event_id'=>$event['id']]));$receipt=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id']),ARRAY_A);
        foreach(['body_sha256'=>str_repeat('0',64),'result_hash'=>str_repeat('1',64),'processing_status'=>'FAILED','shop_reference'=>'jewelry']as $key=>$value){self::assertSame(1,$wpdb->update(Tables::webhook_evidence(),[$key=>$value],['event_id'=>$event['id']]));$projection=(new DigiForge\Listings\EtsyOrderEventRepository())->recent('digital');self::assertSame('UNAVAILABLE',$projection['query_state']);self::assertSame([],$projection['items']);self::assertSame(1,$wpdb->update(Tables::webhook_evidence(),[$key=>$receipt[$key]],['event_id'=>$event['id']]));}
        self::assertSame(1,$wpdb->update(Tables::orders(),['external_order_reference'=>'drifted-external'],['id'=>$own['id']]));self::assertSame('UNAVAILABLE',(new DigiForge\Listings\EtsyOrderEventRepository())->recent('digital')['query_state']);
    }
    public function testEvidencePersistenceFailureIsFailedAndAuthenticRetryStoresOneBoundReceipt():void
    {
        $own=$this->order('digital');global $wpdb;$table=Tables::etsy_order_event_evidence();$fault=static fn(string $sql):string=>str_starts_with($sql,'INSERT INTO `'.$table.'`')?str_replace($table,$table.'_synthetic_missing',$sql):$sql;$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);try{$event=$this->event($own);}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}self::assertWPError($event['result']);self::assertSame(503,$event['result']->get_error_data()['status']);self::assertSame('FAILED',$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id'])));self::assertSame('FAILED',$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Tables::idempotency().' WHERE operation_key=%s','etsy_webhook:'.hash('sha256',$event['id']))));
        $intake=new DigiForge\Listings\EtsyWebhookIntake(new DigiForge\Listings\EtsyWebhookDeduplicator());$retry=$intake->accept($event['body'],$event['headers'],'whsec_'.base64_encode($event['key']),$event['now']);self::assertNotWPError($retry);self::assertSame('ETSY_ORDER_EVENT_RECORDED',$retry['state']);self::assertSame('PROCESSED',$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id'])));self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$table.' WHERE event_id=%s',$event['id'])));self::assertStringContainsString($event['id'],$this->render());
    }
    public function testUnmatchedShipmentKeepsFailedReceiptAndCreatesNoOrderOrTrackingIdentity():void
    {
        global $wpdb;$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::orders());$event=$this->event(['external_order_reference'=>'unknown-'.wp_generate_uuid4(),'shop_reference'=>'digital']);self::assertWPError($event['result']);self::assertSame('digiforge_etsy_order_webhook_order_not_found',$event['result']->get_error_code());self::assertSame($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::orders()));self::assertSame('FAILED',$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id'])));self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::etsy_order_event_evidence().' WHERE event_id=%s',$event['id'])));
    }
    public function testNormalizedStorageAndFailureLedgerFaultReportsUncertainDurableFailure():void
    {
        $own=$this->order('digital');global $wpdb;$table=Tables::etsy_order_event_evidence();$fault=static function(string $sql)use($table):string{if(str_starts_with($sql,'INSERT INTO `'.$table.'`'))return str_replace($table,$table.'_synthetic_missing',$sql);if(str_starts_with($sql,'UPDATE')&&(str_contains($sql,Tables::webhook_evidence())||str_contains($sql,Tables::idempotency())))return 'UPDATE synthetic_missing_failure_ledger SET state=1';return $sql;};$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);try{$event=$this->event($own);}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}self::assertWPError($event['result']);self::assertSame('digiforge_etsy_webhook_failure_evidence_unavailable',$event['result']->get_error_code());self::assertSame(503,$event['result']->get_error_data()['status']);self::assertSame('VERIFIED',$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id'])));self::assertSame('RECEIVED',$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Tables::idempotency().' WHERE operation_key=%s','etsy_webhook:'.hash('sha256',$event['id']))));
    }
    public function testGlobalAllShopGrantStillRequiresAnAuthoritativeStoredCanonicalShopAssociation():void
    {
        $external='991'.(string)wp_rand(10000000,99999999);$own=$this->order('digital');$unknown=$this->order($external);$knownEvent=$this->event($own);$unknownEvent=$this->event($unknown);self::assertNotWPError($knownEvent['result']);self::assertNotWPError($unknownEvent['result']);
        $model=new DigiForge\Listings\EtsyOrderEventRepository();$global=$model->recent(null,1);self::assertSame('AVAILABLE',$global['query_state']);self::assertCount(1,$global['items']);self::assertSame($knownEvent['id'],$global['items'][0]['event_id']);self::assertStringNotContainsString($unknownEvent['id'],$this->render('all'));
        // Explicit synthetic stored successful connection identity; never authentic provider/owner certification.
        global $wpdb;$now=current_time('mysql',true);self::assertSame(1,$wpdb->insert(Tables::integrations(),['provider'=>'etsy','environment'=>'production','connection_key'=>'tracking-scope-'.wp_generate_uuid4(),'display_name'=>'Synthetic stored receipt scope','status'=>'CONFIGURED','enabled'=>0,'config'=>wp_json_encode(['_connection_test'=>['ok'=>true,'details'=>['shop_id'=>$external,'shop_name'=>'digital']]]),'created_at'=>$now,'updated_at'=>$now]));
        $mapped=$model->recent(null,1);self::assertSame('AVAILABLE',$mapped['query_state']);self::assertSame($unknownEvent['id'],$mapped['items'][0]['event_id']);self::assertStringContainsString($unknownEvent['id'],$this->render('digital'));
    }
    /** @dataProvider raceCases */
    public function testInsertRaceWinnerMustMatchEveryImmutableNormalizedField(bool $conflicting):void
    {
        $own=$this->order('digital');$event=$this->event($own);self::assertNotWPError($event['result']);global $wpdb;$original=$wpdb;$receipt=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id']),ARRAY_A);self::assertSame(1,$wpdb->delete(Tables::etsy_order_event_evidence(),['event_id'=>$event['id']]));
        $race=new class($original) extends wpdb {
            public function __construct(wpdb $source){foreach(get_object_vars($source)as $key=>$value)$this->$key=$value;$this->dbh=(new ReflectionProperty(wpdb::class,'dbh'))->getValue($source);}
            public bool $conflicting=false;
            public function insert($table,$data,$format=null){if($table===Tables::etsy_order_event_evidence()){if($this->conflicting)$data['result_hash']=str_repeat('f',64);if(parent::insert($table,$data,$format)!==1)throw new RuntimeException('Synthetic competing event row could not be stored.');return false;}return parent::insert($table,$data,$format);}
        };$race->conflicting=$conflicting;$race->set_prefix($original->prefix);$wpdb=$race;
        try{$result=(new DigiForge\Listings\EtsyOrderEventRepository())->record($receipt,$event['result']);}finally{$wpdb=$original;}
        if($conflicting){self::assertWPError($result);self::assertSame(503,$result->get_error_data()['status']);}else{self::assertNotWPError($result);self::assertSame($event['id'],$result['event_id']);}
        self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::etsy_order_event_evidence().' WHERE event_id=%s',$event['id'])));
    }
    public function raceCases():array{return ['exact-winner'=>[false],'conflicting-winner'=>[true]];}
    public function testAdditiveMigrationPreservesEvidenceAndFailsClosedOnStorageOrVersionFailure():void
    {
        $own=$this->order('digital');$event=$this->event($own);self::assertNotWPError($event['result']);global $wpdb;$table=Tables::etsy_order_event_evidence();$before=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE event_id=%s',$event['id']),ARRAY_A);
        $orderBefore=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$own['id']),ARRAY_A);$receiptBefore=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id']),ARRAY_A);
        // Real additive DDL in the disposable DB; restore WP's temporary-table hook afterward.
        remove_filter('query',[$this,'_create_temporary_tables']);
        try{
            update_option('digiforge_db_schema_version',26,false);$calls=0;$firstMissing=static function(string $sql)use($table,&$calls):string{if(str_starts_with($sql,'SELECT 1 FROM `'.$table.'`')&&++$calls===1)return str_replace($table,$table.'_synthetic_missing',$sql);return $sql;};add_filter('query',$firstMissing);try{self::assertTrue(DigiForge\Database\EtsyOrderEventSchema::migrateIfNeeded());}finally{remove_filter('query',$firstMissing);}self::assertSame(26,(int)get_option('digiforge_db_schema_version'));self::assertSame($before,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE event_id=%s',$event['id']),ARRAY_A));
            update_option('digiforge_db_schema_version',24,false);$fault=static function(string $sql)use($table):string{if(str_starts_with($sql,'SELECT 1 FROM `'.$table.'`'))return str_replace($table,$table.'_synthetic_missing',$sql);if(str_starts_with($sql,'CREATE TABLE IF NOT EXISTS '.$table))return 'CREATE TABLE synthetic invalid';return $sql;};$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);try{self::assertFalse(DigiForge\Database\EtsyOrderEventSchema::migrateIfNeeded());}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}self::assertSame(24,(int)get_option('digiforge_db_schema_version'));self::assertSame('ETSY_ORDER_EVENT_SCHEMA_UPDATE_FAILED',get_option('digiforge_last_migration_failure')['error_code']);
            $denyVersion=static fn($new,$old)=>$old;add_filter('pre_update_option_digiforge_db_schema_version',$denyVersion,10,2);try{self::assertFalse(DigiForge\Database\EtsyOrderEventSchema::migrateIfNeeded());}finally{remove_filter('pre_update_option_digiforge_db_schema_version',$denyVersion);}self::assertSame('ETSY_ORDER_EVENT_SCHEMA_VERSION_FAILED',get_option('digiforge_last_migration_failure')['error_code']);self::assertTrue(DigiForge\Database\EtsyOrderEventSchema::migrateIfNeeded());self::assertSame(25,(int)get_option('digiforge_db_schema_version'));
            self::assertSame($before,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE event_id=%s',$event['id']),ARRAY_A));self::assertSame($orderBefore,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$own['id']),ARRAY_A));self::assertSame($receiptBefore,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id']),ARRAY_A));
        }finally{add_filter('query',[$this,'_create_temporary_tables']);}

    }
}
