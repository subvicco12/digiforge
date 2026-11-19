<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PortalAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Listings\{EtsyWebhookDeduplicator,EtsyWebhookIntake};
use DigiForge\Portal\{OrderHeldPreparationPanel,OrderHoldWorkflow,OrderIntakeEvidencePanel};

/** Actual signed intake, scoped operator inspection and existing restrictive hold workflow. */
final class OrderIntakeEvidencePanelTest extends WP_UnitTestCase
{
    private array $cutoffs=[];
    private int $httpCalls=0;
    private $denyHttp;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();PortalAccessFixture::reset();
        global $wpdb;
        foreach([Tables::orders(),Tables::order_line_items(),Tables::webhook_evidence(),Tables::idempotency(),Tables::audit_log()] as $table)$this->cutoffs[$table]=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.$table);
        $this->denyHttp=function(){++$this->httpCalls;return new WP_Error('offline_intake_inspection');};add_filter('pre_http_request',$this->denyHttp);
    }
    protected function tearDown():void
    {
        remove_filter('pre_http_request',$this->denyHttp);global $wpdb;$wpdb->query('ROLLBACK');
        foreach($this->cutoffs as $table=>$last)$wpdb->query($wpdb->prepare('DELETE FROM '.$table.' WHERE id>%d',$last));
        PortalAccessFixture::reset();self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all'));self::assertFalse(Settings::get('automation_armed'));parent::tearDown();
    }
    private function intake(bool $fail=false):array
    {
        wp_set_current_user(0);$event='operator-intake-'.wp_generate_uuid4();$external='stored-order-'.wp_generate_uuid4();$timestamp=time();$secret='synthetic-operator-intake-fixture';
        $body=(string)wp_json_encode(['event_type'=>'order.paid','order_id'=>$external,'shop_id'=>'personalized_pod','currency'=>'EUR','line_items'=>[]]);
        $headers=['webhook-id'=>$event,'webhook-timestamp'=>(string)$timestamp,'webhook-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$event.'.'.$timestamp.'.'.$body,$secret,true))];
        global $wpdb;$fault=static fn(string $sql):string=>str_starts_with($sql,'UPDATE `'.Tables::orders().'`')?'UPDATE synthetic_missing_intake_transition SET state=1':$sql;
        $old=$wpdb->suppress_errors(true);if($fail)add_filter('query',$fault);
        try{$result=(new EtsyWebhookIntake(new EtsyWebhookDeduplicator()))->accept($body,$headers,'whsec_'.base64_encode($secret),$timestamp);}
        finally{if($fail)remove_filter('query',$fault);$wpdb->suppress_errors($old);}
        if($fail)self::assertWPError($result);else self::assertNotWPError($result);
        $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE external_order_reference=%s AND shop_reference=%s',$external,'personalized_pod'),ARRAY_A);self::assertIsArray($order);
        PortalAccessFixture::enroll();$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');return ['event'=>$event,'external'=>$external,'id'=>(int)$order['id'],'body_hash'=>hash('sha256',$body)];
    }
    private function render(int $order):string
    {
        self::assertTrue(class_exists(OrderIntakeEvidencePanel::class),'A connected non-authorizing intake evidence panel is required.');
        ob_start();try{(new OrderIntakeEvidencePanel())->render('personalized_pod',$order);return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    public function testCompleteStoredIntakeDoesNotSupplyPaymentProofOrProductionAuthority():void
    {
        $f=$this->intake();$html=$this->render($f['id']);self::assertStringContainsString('Stored intake records',$html);self::assertStringContainsString('COMPLETE',$html);self::assertStringContainsString($f['event'],$html);self::assertStringContainsString($f['body_hash'],$html);self::assertStringContainsString('payment',$html);self::assertStringContainsString('Buyer identity requires verification.',$html);self::assertStringContainsString('retry',$html);self::assertStringNotContainsString('synthetic-operator-intake-fixture',$html);
        global $wpdb;$order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$f['id']),ARRAY_A);self::assertSame('REVIEW_REQUIRED',$order['state']);self::assertSame('0',$order['approved_by']);self::assertNull($order['approved_at']);
    }
    public function testFailedPersistenceRemainsVisibleBeforeProviderDependentPreparationAndCanOnlyBeHeld():void
    {
        $f=$this->intake(true);$before=$_GET;$_GET=['df_order_id'=>(string)$f['id']];ob_start();try{(new OrderHeldPreparationPanel())->render('personalized_pod');$html=(string)ob_get_contents();}finally{ob_end_clean();$_GET=$before;}
        self::assertStringContainsString('Stored intake records',$html);self::assertStringContainsString('FAILED',$html);self::assertStringContainsString($f['event'],$html);self::assertStringContainsString(OrderHoldWorkflow::ACTION,$html);
        $body=['action'=>OrderHoldWorkflow::ACTION,'order_id'=>(string)$f['id'],'shop_key'=>'personalized_pod','phase'=>'preview','_wpnonce'=>wp_create_nonce(OrderHoldWorkflow::ACTION.':'.$f['id'])];
        $workflow=new OrderHoldWorkflow();$preview=$workflow->processBrowser($body);self::assertNotWPError($preview);$p=$preview['preview'];$body['phase']='confirm';
        $result=$workflow->processBrowser($body+['df_confirm_review'=>'1','df_confirmation_shop'=>$p['shop'],'df_confirmation_nonce'=>$p['confirmation_nonce'],'df_confirmation_issued_at'=>$p['issued_at'],'df_confirmation_tokens'=>['REVIEW'=>$p['token']]]);self::assertNotWPError($result);
        global $wpdb;$order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$f['id']),ARRAY_A);self::assertSame('ON_HOLD',$order['state']);self::assertSame('0',$order['approved_by']);self::assertNull($order['approved_at']);
        foreach([Tables::fulfillment_plans(),Tables::fulfillment_intents(),Tables::pod_authorization_packages()]as $table)self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$table.' WHERE order_id=%d',$f['id'])));
        self::assertSame('FAILED',$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$f['event'])));
    }
    public function testUncertainCompletionNeverRendersCompleteOrOffersReplay():void
    {
        $f=$this->intake();global $wpdb;self::assertSame(1,$wpdb->update(Tables::idempotency(),['status'=>'RECEIVED','response_hash'=>''],['operation_key'=>'etsy_webhook:'.hash('sha256',$f['event'])]));
        $html=$this->render($f['id']);self::assertStringContainsString('UNCERTAIN',$html);self::assertStringNotContainsString('<strong>COMPLETE</strong>',$html);self::assertStringNotContainsString('<button',$html);
    }
    public function testAnonymousAndRevocationDuringHtmlPreparationCannotExposeOrderEvidence():void
    {
        $f=$this->intake();$actor=get_current_user_id();wp_set_current_user(0);$html=$this->render($f['id']);self::assertStringContainsString('unavailable',$html);self::assertStringNotContainsString($f['event'],$html);self::assertStringNotContainsString($f['external'],$html);
        wp_set_current_user($actor);$fired=false;$revoke=static function(string $safe,string $original)use(&$fired):string{if(!$fired&&$original==='COMPLETE'){$fired=true;wp_set_current_user(0);}return $safe;};add_filter('esc_html',$revoke,10,2);
        try{$html=$this->render($f['id']);}finally{remove_filter('esc_html',$revoke,10);}self::assertTrue($fired);self::assertStringContainsString('unavailable',$html);self::assertStringNotContainsString($f['event'],$html);self::assertStringNotContainsString($f['external'],$html);
    }
    public function testStoredOrderChangeDuringHtmlPreparationCannotPublishAStaleProjection():void
    {
        $f=$this->intake();$changed='changed-after-intake-inspection';$fired=false;
        $race=static function(string $safe,string $original)use($f,$changed,&$fired):string{
            if(!$fired&&$original==='COMPLETE'){$fired=true;global $wpdb;self::assertSame(1,$wpdb->update(Tables::orders(),['external_order_reference'=>$changed],['id'=>$f['id']]));}
            return $safe;
        };
        add_filter('esc_html',$race,10,2);try{$html=$this->render($f['id']);}finally{remove_filter('esc_html',$race,10);}
        self::assertTrue($fired);self::assertStringContainsString('unavailable',$html);foreach([$f['event'],$f['external'],$changed,$f['body_hash']]as $private)self::assertStringNotContainsString($private,$html);
    }
}
