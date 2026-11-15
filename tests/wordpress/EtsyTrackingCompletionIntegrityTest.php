<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyOrderEventRepository;
use DigiForge\Listings\EtsyWebhookDeduplicator;
use DigiForge\Listings\EtsyWebhookIntake;
use DigiForge\Portal\Portal;

/** Authentic synthetic signed intake, actual durable completion writes, no carrier/provider calls. */
final class EtsyTrackingCompletionIntegrityTest extends WP_UnitTestCase
{
    private array $events=[];
    private array $eventSnapshot=[];
    private int $httpCalls=0;
    private $denyHttp;

    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        global $wpdb;$this->eventSnapshot=$wpdb->get_results('SELECT * FROM '.Tables::etsy_order_event_evidence(),ARRAY_A);$wpdb->query('DELETE FROM '.Tables::etsy_order_event_evidence());
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
        $this->denyHttp=function(){++$this->httpCalls;return new WP_Error('offline_tracking');};add_filter('pre_http_request',$this->denyHttp);
    }

    protected function tearDown():void
    {
        remove_filter('pre_http_request',$this->denyHttp);
        global $wpdb;$wpdb->query('DELETE FROM '.Tables::etsy_order_event_evidence());foreach($this->eventSnapshot as $row)$wpdb->insert(Tables::etsy_order_event_evidence(),$row,array_fill(0,count($row),'%s'));
        self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::safety_locked());parent::tearDown();
    }

    public function testFailedCompletionLedgerWriteCannotAppearAsVerifiedTracking():void
    {
        $order=$this->order('digital');global $wpdb;
        self::assertSame('AVAILABLE',(new EtsyOrderEventRepository())->recent('digital')['query_state']);
        $fault=static fn(string $sql):string=>str_starts_with($sql,'UPDATE ')&&str_contains($sql,Tables::idempotency())?'UPDATE synthetic_missing_tracking_completion SET status=1':$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try {$event=$this->event($order);}
        finally {remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        self::assertWPError($event['result']);self::assertSame('digiforge_etsy_webhook_completion_evidence_unavailable',$event['result']->get_error_code());
        self::assertSame('PROCESSED',$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event['id'])));
        self::assertSame('RECEIVED',$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Tables::idempotency().' WHERE operation_key=%s',$this->key($event['id']))));
        self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::etsy_order_event_evidence().' WHERE event_id=%s',$event['id'])));
        $projection=(new EtsyOrderEventRepository())->recent('digital');
        self::assertSame('UNAVAILABLE',$projection['query_state']);self::assertSame([],$projection['items']);
        $html=$this->render();self::assertStringContainsString('Shipment / delivery event evidence unavailable',$html);self::assertStringNotContainsString($event['id'],$html);
    }

    public function testExactCompletionTypeStatusAndResultHashAreRequired():void
    {
        $order=$this->order('digital');$event=$this->event($order);self::assertNotWPError($event['result']);global $wpdb;
        $key=$this->key($event['id']);$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::idempotency().' WHERE operation_key=%s',$key),ARRAY_A);
        self::assertSame('AVAILABLE',(new EtsyOrderEventRepository())->recent('digital')['query_state']);
        foreach(['operation_type'=>'OTHER_WEBHOOK','status'=>'FAILED','response_hash'=>str_repeat('0',64)] as $field=>$bad){
            self::assertSame(1,$wpdb->update(Tables::idempotency(),[$field=>$bad],['operation_key'=>$key]));
            self::assertSame('UNAVAILABLE',(new EtsyOrderEventRepository())->recent('digital')['query_state'],$field);
            self::assertSame(1,$wpdb->update(Tables::idempotency(),[$field=>$row[$field]],['operation_key'=>$key]));
        }
        self::assertSame(1,$wpdb->delete(Tables::idempotency(),['operation_key'=>$key]));
        self::assertSame('UNAVAILABLE',(new EtsyOrderEventRepository())->recent('digital')['query_state']);
        self::assertSame(1,$wpdb->insert(Tables::idempotency(),$row));
    }

    public function testExactOrderDrilldownFindsReceiptOutsideTheRecentShopWindow():void
    {
        self::assertTrue(method_exists(EtsyOrderEventRepository::class,'forOrder'),'An exact scoped order receipt inspection is required.');
        $own=$this->order('digital');$event=$this->event($own,'order.delivered');self::assertNotWPError($event['result']);
        $other=$this->event($this->order('digital'));self::assertNotWPError($other['result']);
        $repo=new EtsyOrderEventRepository();self::assertSame($other['id'],$repo->recent('digital',1)['items'][0]['event_id']);
        global $wpdb;$before=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$own['id']),ARRAY_A);
        $projection=$repo->forOrder('digital',(int)$own['id']);
        self::assertSame('AVAILABLE',$projection['query_state']);self::assertCount(1,$projection['items']);self::assertSame($event['id'],$projection['items'][0]['event_id']);
        self::assertFalse($projection['external_execution_authorized']);self::assertFalse($projection['retry_permitted']);
        $html=$this->render(['df_tracking_order'=>(string)$own['id']]);
        self::assertStringContainsString('Order-specific shipment receipts',$html);self::assertStringContainsString($event['id'],$html);
        self::assertStringContainsString('df_tracking_order',$html);self::assertStringContainsString('not live carrier tracking',$html);
        self::assertStringNotContainsString('private-tracking-value',$html);self::assertStringContainsString(hash('sha256','private-tracking-value'),$html);
        self::assertSame($before,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$own['id']),ARRAY_A));
    }

    public function testDrilldownDeniesForeignShopAndAnonymousBeforeReadingOrderReceipts():void
    {
        self::assertTrue(method_exists(EtsyOrderEventRepository::class,'forOrder'));
        $foreign=$this->order('jewelry');$event=$this->event($foreign);self::assertNotWPError($event['result']);
        $repo=new EtsyOrderEventRepository();$result=$repo->forOrder('digital',(int)$foreign['id']);self::assertSame('UNAVAILABLE',$result['query_state']);self::assertSame([],$result['items']);
        $html=$this->render(['df_tracking_order'=>(string)$foreign['id']]);self::assertStringNotContainsString($event['id'],$html);
        wp_set_current_user(0);$reads=[];
        $observe=static function(string $sql)use(&$reads):string{if(str_starts_with($sql,'SELECT ')&&(str_contains($sql,Tables::orders())||str_contains($sql,Tables::etsy_order_event_evidence())))$reads[]=$sql;return $sql;};
        add_filter('query',$observe);
        try {$result=$repo->forOrder('jewelry',(int)$foreign['id']);}
        finally {remove_filter('query',$observe);}
        self::assertSame('UNAVAILABLE',$result['query_state']);self::assertSame([],$reads);
    }

    public function testDrilldownCannotInferEmptyHistoryFromUnavailableOrDriftedEvidence():void
    {
        self::assertTrue(method_exists(EtsyOrderEventRepository::class,'forOrder'));
        $order=$this->order('digital');$event=$this->event($order);self::assertNotWPError($event['result']);global $wpdb;$repo=new EtsyOrderEventRepository();
        self::assertSame(1,$wpdb->update(Tables::orders(),['external_order_reference'=>'drifted-tracking-order'],['id'=>$order['id']]));
        self::assertSame('UNAVAILABLE',$repo->forOrder('digital',(int)$order['id'])['query_state']);
        $deny=static fn(string $sql):string=>str_starts_with($sql,'SELECT ')&&str_contains($sql,Tables::etsy_order_event_evidence())?'SELECT FROM /* synthetic tracking read failure */':$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$deny);
        try {$result=$repo->forOrder('digital',(int)$order['id']);$html=$this->render(['df_tracking_order'=>(string)$order['id']]);}
        finally {remove_filter('query',$deny);$wpdb->suppress_errors($previous);}
        self::assertSame('UNAVAILABLE',$result['query_state']);self::assertSame([],$result['items']);
        self::assertStringContainsString('Order receipt evidence unavailable',$html);self::assertStringNotContainsString('No signed receipts in this order window',$html);
    }

    /** @dataProvider projectionRaces */
    public function testOrderAndActorAreRecheckedAfterReceiptProjection(string $race):void
    {
        $order=$this->order('digital');$event=$this->event($order);self::assertNotWPError($event['result']);$changed=false;
        $change=static function(string $sql)use($order,$race,&$changed):string{
            if(!$changed&&str_starts_with($sql,'SELECT e.*')){
                $changed=true;
                if($race==='actor')wp_set_current_user(0);
                else {global $wpdb;$wpdb->update(Tables::orders(),['state'=>'ON_HOLD'],['id'=>$order['id']]);}
            }
            return $sql;
        };
        add_filter('query',$change);
        try {$result=(new EtsyOrderEventRepository())->forOrder('digital',(int)$order['id']);}
        finally {remove_filter('query',$change);}
        self::assertTrue($changed);self::assertSame('UNAVAILABLE',$result['query_state']);self::assertSame([],$result['items']);self::assertNull($result['order']);
    }

    public static function projectionRaces():array{return [['actor'],['order']];}

    public function testOrdersModuleRevocationDuringFinalOrderReadCannotReturnReceipts():void
    {
        $order=$this->order('digital');$event=$this->event($order);self::assertNotWPError($event['result']);
        $actor=get_current_user_id();$reads=0;$revoked=false;
        $revoke=static function(string $sql)use(&$reads,&$revoked):string{
            if(str_starts_with($sql,'SELECT o.id,o.external_order_reference')&&++$reads===2){
                $revoked=true;wp_get_current_user()->add_cap('manage_digiforge_orders',false);
            }
            return $sql;
        };
        add_filter('query',$revoke);
        try {$result=(new EtsyOrderEventRepository())->forOrder('digital',(int)$order['id']);}
        finally {remove_filter('query',$revoke);}
        self::assertTrue($revoked);self::assertSame($actor,get_current_user_id());
        self::assertSame('UNAVAILABLE',$result['query_state']);self::assertSame([],$result['items']);self::assertNull($result['order']);
    }

    private function order(string $shop):array
    {
        $order=(new DigiForge\Orders\Repository())->createOrder(['channel'=>'etsy','environment'=>'production','shop_reference'=>$shop,'external_order_reference'=>'tracking-integrity-'.wp_generate_uuid4()]);self::assertNotWPError($order);return $order;
    }

    private function event(array $order,string $type='order.shipped'):array
    {
        $id='tracking-integrity-'.wp_generate_uuid4();$this->events[]=$id;$now=time();$secret='synthetic-tracking-completion';
        $body=(string)wp_json_encode(['event_type'=>$type,'order_id'=>$order['external_order_reference'],'shop_id'=>$order['shop_reference'],'tracking_code'=>'private-tracking-value','carrier_name'=>'Synthetic Carrier']);
        $headers=['webhook-id'=>$id,'webhook-timestamp'=>(string)$now,'webhook-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$id.'.'.$now.'.'.$body,$secret,true))];
        $result=(new EtsyWebhookIntake(new EtsyWebhookDeduplicator()))->accept($body,$headers,'whsec_'.base64_encode($secret),$now);return ['id'=>$id,'result'=>$result];
    }

    private function key(string $id):string {return 'etsy_webhook:'.hash('sha256',$id);}
    private function render(array $extra=[]):string
    {
        $before=$_GET;try {$_GET=$extra+['df_view'=>'fulfillment','df_shop'=>'digital'];return (new Portal())->render();}finally {$_GET=$before;}
    }
}
