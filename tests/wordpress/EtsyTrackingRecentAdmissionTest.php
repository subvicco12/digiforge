<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyOrderEventRepository;

/** Actual signed local receipt projection; no carrier or fulfillment authority. */
final class EtsyTrackingRecentAdmissionTest extends WP_UnitTestCase
{
    private array $snapshot=[];
    private int $httpCalls=0;
    private $denyHttp;

    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        global $wpdb;$this->snapshot=$wpdb->get_results('SELECT * FROM '.Tables::etsy_order_event_evidence(),ARRAY_A);$wpdb->query('DELETE FROM '.Tables::etsy_order_event_evidence());
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
        $this->denyHttp=function(){++$this->httpCalls;return new WP_Error('offline_tracking');};add_filter('pre_http_request',$this->denyHttp);
    }

    protected function tearDown():void
    {
        remove_filter('pre_http_request',$this->denyHttp);
        global $wpdb;$wpdb->query('DELETE FROM '.Tables::etsy_order_event_evidence());foreach($this->snapshot as $row)$wpdb->insert(Tables::etsy_order_event_evidence(),$row,array_fill(0,count($row),'%s'));
        self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all',true));self::assertFalse(Settings::get('automation_armed',false));parent::tearDown();
    }

    /** @dataProvider races */
    public function testRecentRechecksAdmissionAfterPrivateReceiptRead(?string $shop,string $race):void
    {
        $id=$this->receipt();$repo=new EtsyOrderEventRepository();
        $valid=$repo->recent($shop);self::assertSame('AVAILABLE',$valid['query_state']);self::assertSame($id,$valid['items'][0]['event_id']);
        $actor=get_current_user_id();$fired=false;
        $revoke=static function(string $sql)use($race,&$fired):string{
            if(!$fired&&str_starts_with($sql,'SELECT e.*')){
                $fired=true;
                if($race==='actor')wp_set_current_user(0);
                else wp_get_current_user()->add_cap('manage_digiforge_orders',false);
            }
            return $sql;
        };
        add_filter('query',$revoke);
        try {$result=$repo->recent($shop);}
        finally {remove_filter('query',$revoke);}
        self::assertTrue($fired);self::assertSame($race==='actor'?0:$actor,get_current_user_id());
        self::assertSame(['query_state'=>'UNAVAILABLE','items'=>[],'external_execution_authorized'=>false],$result);
    }

    public static function races():array
    {
        return ['selected actor'=>['digital','actor'],'selected module'=>['digital','module'],'all actor'=>[null,'actor'],'all module'=>[null,'module']];
    }

    public function testUnchangedSelectedAndAllShopReadsRemainReadOnly():void
    {
        $id=$this->receipt();global $wpdb;$before=$wpdb->get_results('SELECT * FROM '.Tables::etsy_order_event_evidence(),ARRAY_A);
        foreach(['digital',null] as $shop){$result=(new EtsyOrderEventRepository())->recent($shop);self::assertSame('AVAILABLE',$result['query_state']);self::assertSame($id,$result['items'][0]['event_id']);self::assertTrue($result['read_only']);self::assertFalse($result['external_execution_authorized']);self::assertFalse($result['retry_permitted']);}
        self::assertSame($before,$wpdb->get_results('SELECT * FROM '.Tables::etsy_order_event_evidence(),ARRAY_A));
    }

    private function receipt():string
    {
        $order=(new DigiForge\Orders\Repository())->createOrder(['channel'=>'etsy','environment'=>'production','shop_reference'=>'digital','external_order_reference'=>'tracking-recent-'.wp_generate_uuid4()]);self::assertNotWPError($order);
        $id='tracking-recent-'.wp_generate_uuid4();$now=time();$key='synthetic-recent-admission-key';
        $body=(string)wp_json_encode(['event_type'=>'order.shipped','order_id'=>$order['external_order_reference'],'shop_id'=>'digital','tracking_code'=>'private-recent-tracking','carrier_name'=>'Synthetic Carrier']);
        $headers=['webhook-id'=>$id,'webhook-timestamp'=>(string)$now,'webhook-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$id.'.'.$now.'.'.$body,$key,true))];
        $result=(new DigiForge\Listings\EtsyWebhookIntake(new DigiForge\Listings\EtsyWebhookDeduplicator()))->accept($body,$headers,'whsec_'.base64_encode($key),$now);self::assertNotWPError($result);return $id;
    }
}
