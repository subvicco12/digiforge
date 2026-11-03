<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Orders\Repository;
use DigiForge\Portal\Portal;

/** Actual selected-shop order UI; no fulfillment or provider authority. */
final class PortalOrderOperationsAcceptanceTest extends WP_UnitTestCase
{
    private int $httpCalls=0;
    private Closure $http;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge','manage_digiforge_orders']);
        wp_create_nonce('operator-order-local');
        $this->http=function(){++$this->httpCalls;return new WP_Error('offline_operator_orders','Actual HTTP forbidden.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown():void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all',true));self::assertFalse(Settings::get('automation_armed',false));parent::tearDown();
    }
    private function order(string $shop):array
    {
        $row=(new Repository())->createOrder(['channel'=>'etsy','environment'=>'sandbox','shop_reference'=>$shop,'external_order_reference'=>'operator-'.wp_generate_uuid4()]);self::assertNotWPError($row);return $row;
    }
    private function render():string
    {
        $prior=$_GET;try{$_GET=['df_view'=>'orders','df_shop'=>'digital'];return (new Portal())->render();}finally{$_GET=$prior;}
    }
    private function panel(string $html):DOMElement
    {
        $doc=new DOMDocument();$prior=libxml_use_internal_errors(true);try{$doc->loadHTML($html,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
        $panels=(new DOMXPath($doc))->query('//section[.//h2[text()="Order readiness"]]');self::assertSame(1,$panels->length);return $panels->item(0);
    }
    public function testAssignedOperatorSeesActualOrderIdentityAndMissingReadinessWithoutForeignOrdersOrWrites():void
    {
        $own=$this->order('digital');$foreign=$this->order('jewelry');$state=null;$rows=(new DigiForge\Orders\OperationsReadModel())->recent(1,$state,'digital');self::assertSame('AVAILABLE',$state);self::assertSame((int)$own['id'],(int)$rows[0]['id']);self::assertFalse($rows[0]['external_fulfillment_authorized']);self::assertFalse($rows[0]['external_execution_performed']);
        global $wpdb;$before=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$own['id']),ARRAY_A);
        $writes=[];$capture=static function(string $sql)use(&$writes):string{$denial=str_starts_with($sql,'INSERT INTO `'.Tables::audit_log().'`')&&str_contains($sql,"'shop_access_denied'");if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$sql)&&!$denial)$writes[]=$sql;return $sql;};add_filter('query',$capture);try{$panel=$this->panel($this->render());}finally{remove_filter('query',$capture);}self::assertSame([],$writes,'Only mandatory immutable access-denial audits may be written by scoped rendering.');
        $xpath=new DOMXPath($panel->ownerDocument);$nodes=$xpath->query('.//table[thead/tr/th[text()="Order"]]//tbody/tr[td[1][text()="#'.$own['id'].'"]]',$panel);self::assertSame(1,$nodes->length);$cells=$nodes->item(0)->getElementsByTagName('td');foreach([0=>'#'.$own['id'],1=>'digital',2=>'RECEIVED',3=>'UNKNOWN',5=>'NO']as $index=>$value)self::assertSame($value,$cells->item($index)->textContent);self::assertStringContainsString('UNAVAILABLE',$cells->item(4)->textContent);self::assertSame(0,$xpath->query('.//table[thead/tr/th[text()="Order"]]//tbody/tr[td[1][text()="#'.$foreign['id'].'"]]',$panel)->length);
        self::assertSame($before,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$own['id']),ARRAY_A));
    }
    public function testFailedOrderReadIsUnavailableRatherThanAnEmptyOrReadyWindow():void
    {
        $this->order('digital');global $wpdb;$table=Tables::orders();$fault=static fn(string $sql):string=>str_starts_with($sql,'SELECT id,channel,environment,external_order_reference')?str_replace($table,$table.'_synthetic_missing',$sql):$sql;$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);try{$panel=$this->panel($this->render());}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}
        self::assertStringContainsString('Recent order evidence unavailable',$panel->textContent);self::assertStringContainsString('no empty order window is inferred',$panel->textContent);self::assertStringNotContainsString('No orders found in the recent window.',$panel->textContent);
    }
    public function testStoredFailedFulfillmentWorkIsScopedAndLinksToReviewWithoutRetryAuthority():void
    {
        global $wpdb;$ids=[];$orders=[];$now=current_time('mysql',true);
        // Historical failed work is visibility evidence, with no plan or approval fabricated.
        foreach(['digital','jewelry']as $shop){$orders[$shop]=$this->order($shop);self::assertSame(1,$wpdb->insert(Tables::fulfillment_intents(),['order_id'=>$orders[$shop]['id'],'intent_type'=>'PREPARE_FULFILLMENT','environment'=>'sandbox','input_payload'=>'{}','state'=>'FAILED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]));$ids[$shop]=(int)$wpdb->insert_id;}
        $model=(new DigiForge\Operations\OperationalExceptionReadModel())->snapshot(1,'digital');self::assertSame('AVAILABLE',$model['query_state']['fulfillment']);self::assertSame($ids['digital'],(int)$model['fulfillment'][0]['id']);self::assertFalse($model['fulfillment'][0]['retry_permitted']);self::assertFalse($model['fulfillment'][0]['external_execution_authorized']);
        $panel=$this->panel($this->render());$xpath=new DOMXPath($panel->ownerDocument);$rows=$xpath->query('.//table[thead/tr/th[text()="Intent"]]//tbody/tr[td[1][starts-with(text(),"#'.$ids['digital'].' ")]]',$panel);self::assertSame(1,$rows->length);$cells=$rows->item(0)->getElementsByTagName('td');self::assertSame('#'.$orders['digital']['id'],$cells->item(1)->textContent);self::assertSame('FAILED',$cells->item(2)->textContent);self::assertSame('fulfillment_intent:'.$ids['digital'],$cells->item(3)->textContent);$links=$cells->item(4)->getElementsByTagName('a');self::assertSame(1,$links->length);self::assertStringContainsString('df_view=audit',$links->item(0)->getAttribute('href'));self::assertStringContainsString('df_shop=digital',$links->item(0)->getAttribute('href'));self::assertSame('Open audit/reconciliation',$links->item(0)->textContent);self::assertSame(0,$xpath->query('.//table[thead/tr/th[text()="Intent"]]//tbody/tr[td[1][starts-with(text(),"#'.$ids['jewelry'].' ")]]',$panel)->length);
        $fault=static fn(string $sql):string=>str_starts_with($sql,'SELECT')&&str_contains($sql,Tables::fulfillment_intents())?str_replace(Tables::fulfillment_intents(),Tables::fulfillment_intents().'_synthetic_missing',$sql):$sql;$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);try{$failed=$this->panel($this->render());}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}self::assertStringContainsString('Fulfillment exception evidence unavailable',$failed->textContent);self::assertStringContainsString('Fulfillment exceptions requiring evidence review: UNAVAILABLE',$failed->textContent);self::assertStringNotContainsString('fulfillment_intent:'.$ids['digital'],$failed->textContent);self::assertSame('FAILED',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::fulfillment_intents().' WHERE id=%d',$ids['digital'])));
    }
}
