<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/fixtures/PrintifyWebhookConfigFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\POD\PrintifyWebhookInbox;

/** Retained local audit/state consistency only; no independent-root or carrier authority claim. */
final class PrintifyWebhookReconciliationIntegrityTest extends WP_UnitTestCase
{
    private string $binding='d123456789abcdef0123456789abcdef';
    private string $path;
    private string $savedConfig='';
    private array $keys=[];
    private int $httpCalls=0;
    private $denyHttp;

    protected function setUp():void
    {
        parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);
        DigiForge\Core\Activator::activate();Settings::protectProduction();PortalAccessFixture::reset();PortalAccessFixture::enroll();
        $this->path=PrintifyWebhookConfigFixture::path();$this->savedConfig=(string)file_get_contents($this->path);
        file_put_contents($this->path,wp_json_encode([$this->binding=>['site'=>home_url(),'shop_key'=>'personalized_pod','integration_id'=>123,'provider_shop_id'=>'123','current_secret'=>str_repeat('a',32)]]));chmod($this->path,0600);
        global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
        $this->denyHttp=function(){++$this->httpCalls;return new WP_Error('offline_webhook_reconciliation');};add_filter('pre_http_request',$this->denyHttp);
    }

    protected function tearDown():void
    {
        if($this->denyHttp!==null)remove_filter('pre_http_request',$this->denyHttp);
        global $wpdb;$wpdb->query('ROLLBACK');$wpdb->query('SET autocommit=1');
        foreach($this->keys as $key){$wpdb->delete($wpdb->options,['option_name'=>'digiforge_pfy_hook_'.$key]);$wpdb->delete($wpdb->options,['option_name'=>$this->stateKey($key)]);$wpdb->delete(Tables::audit_log(),['object_type'=>'printify_webhook','object_id'=>$key],['%s','%s']);}
        if(isset($this->path))file_put_contents($this->path,$this->savedConfig);
        PortalAccessFixture::reset();self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::safety_locked());$wpdb->query('START TRANSACTION');parent::tearDown();
    }

    public function testRetainedThreeRetryAuditCannotBeResetByDeletingOnlyMutableState():void
    {
        $key=$this->receive();$last=$this->threeRetries($key);self::assertSame('DEAD_LETTER',$last['state']);self::assertSame(3,$last['attempts']);self::assertCount(3,$this->audits($key));
        global $wpdb;self::assertSame(1,$wpdb->delete($wpdb->options,['option_name'=>$this->stateKey($key)]));
        $result=PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',0,'retry after local state rollback');
        self::assertWPError($result,'Retained audit history must prevent restarting a consumed local retry ceiling.');
        self::assertCount(3,$this->audits($key));self::assertNull($this->state($key));
    }

    public function testRestoringEarlierStateCannotHideRetainedLaterRevisions():void
    {
        $key=$this->receive();self::assertNotWPError(PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',0,'first local retry'));$first=$this->state($key);
        self::assertNotWPError(PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',1,'second local retry'));self::assertNotWPError(PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',2,'third local retry'));
        global $wpdb;self::assertSame(1,$wpdb->update($wpdb->options,['option_value'=>$first],['option_name'=>$this->stateKey($key)]));
        self::assertWPError(PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',1,'rewound local state'));self::assertSame($first,$this->state($key));self::assertCount(3,$this->audits($key));
    }

    /** @dataProvider malformedState */
    public function testMalformedOrUnanchoredStateCannotBeUsedForAnotherRevision(string $field,mixed $value):void
    {
        $key=$this->receive();$state=$this->threeRetries($key);$state[$field]=$value;$raw=(string)wp_json_encode($state);global $wpdb;
        self::assertSame(1,$wpdb->update($wpdb->options,['option_value'=>$raw],['option_name'=>$this->stateKey($key)]));
        self::assertWPError(PrintifyWebhookInbox::reconcile($key,'MANUALLY_RECONCILED',3,'local review after tamper'));self::assertSame($raw,$this->state($key));self::assertCount(3,$this->audits($key));
    }
    public static function malformedState():array{return [['revision','3'],['attempts',null],['attempts',2],['scheduled',0],['business_transition_authorized',true],['unexpected',false]];}

    /** @dataProvider missingHistory */
    public function testMissingRetainedAuditCannotAuthorizeMutableState(string $which):void
    {
        $key=$this->receive();$this->threeRetries($key);$before=$this->state($key);$audits=$this->audits($key);global $wpdb;
        $ids=$which==='all'?array_column($audits,'id'):[$audits[$which==='middle'?1:2]['id']];foreach($ids as $id)self::assertSame(1,$wpdb->delete(Tables::audit_log(),['id'=>(int)$id],['%d']));
        self::assertWPError(PrintifyWebhookInbox::reconcile($key,'MANUALLY_RECONCILED',3,'local review missing audit'));self::assertSame($before,$this->state($key));self::assertCount(3-count($ids),$this->audits($key));
    }
    public static function missingHistory():array{return [['all'],['middle'],['latest']];}

    /** @dataProvider historyReadPositions */
    public function testHistoryReadFailureBeforeOrAfterWriteCannotPersistAnotherState(int $failAt):void
    {
        $key=$this->receive();$this->threeRetries($key);$before=$this->state($key);$reads=0;
        $fail=static function(string $sql)use(&$reads,$failAt):string{if(str_starts_with($sql,'SELECT ')&&str_contains($sql,'printify_webhook_reconciled')&&++$reads===$failAt)return 'SELECT FROM /* synthetic reconciliation history read failure */';return $sql;};
        global $wpdb;$previous=$wpdb->suppress_errors(true);add_filter('query',$fail);
        try {self::assertWPError(PrintifyWebhookInbox::reconcile($key,'MANUALLY_RECONCILED',3,'manual local review'));}
        finally {remove_filter('query',$fail);$wpdb->suppress_errors($previous);}
        self::assertSame($failAt,$reads);self::assertSame($before,$this->state($key));self::assertCount(3,$this->audits($key));
    }
    public static function historyReadPositions():array{return [[1],[2]];}

    public function testStateChangeAtUpdateCannotBeOverwrittenOutsideExactCas():void
    {
        $key=$this->receive();self::assertNotWPError(PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',0,'first local retry'));$before=$this->state($key);$changed=false;
        $change=function(string $sql)use($key,&$changed):string{
            global $wpdb;if(!$changed&&str_starts_with($sql,'UPDATE '.$wpdb->options.' SET option_value=')){$changed=true;$p=json_decode((string)$this->state($key),true);$p['note']='raced note';$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($p)],['option_name'=>$this->stateKey($key)]);}return $sql;
        };
        add_filter('query',$change);
        try {self::assertWPError(PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',1,'second local retry'));}
        finally {remove_filter('query',$change);}
        self::assertTrue($changed);self::assertSame($before,$this->state($key));self::assertCount(1,$this->audits($key));
    }

    public function testRevokedModuleDuringHistoryReadCannotPersistReconciliation():void
    {
        $key=$this->receive();$revoked=false;
        $revoke=static function(string $sql)use(&$revoked):string{if(!$revoked&&str_starts_with($sql,'SELECT ')&&str_contains($sql,'printify_webhook_reconciled')){$revoked=true;wp_get_current_user()->add_cap('manage_digiforge_connections',false);}return $sql;};
        add_filter('query',$revoke);
        try {$result=PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',0,'local review during revocation');}
        finally {remove_filter('query',$revoke);wp_get_current_user()->add_cap('manage_digiforge_connections',true);}
        self::assertTrue($revoked);self::assertWPError($result);self::assertNull($this->state($key));self::assertSame([],$this->audits($key));
    }

    public function testExistingDirectDeadLetterAndManualResolutionSemanticsRemainLocalOnly():void
    {
        $key=$this->receive();$dead=PrintifyWebhookInbox::reconcile($key,'DEAD_LETTER',0,'direct local dead letter');self::assertNotWPError($dead);self::assertSame(0,$dead['attempts']);
        $manual=PrintifyWebhookInbox::reconcile($key,'MANUALLY_RECONCILED',1,'manual local receipt review');self::assertNotWPError($manual);self::assertSame(0,$manual['attempts']);
        $retry=PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',2,'explicit local retry review');self::assertNotWPError($retry);self::assertSame(1,$retry['attempts']);self::assertFalse($retry['scheduled']);self::assertFalse($retry['business_transition_authorized']);self::assertCount(3,$this->audits($key));
    }

    private function receive():string
    {
        $raw=(string)wp_json_encode(['id'=>wp_generate_uuid4(),'type'=>'order:shipment:created','created_at'=>gmdate('Y-m-d H:i:s+00:00'),'resource'=>['id'=>'synthetic-order','type'=>'order','data'=>['shop_id'=>'123']]]);
        $signature='sha256='.hash_hmac('sha256',$raw,str_repeat('a',32));$event=PrintifyWebhookInbox::authenticate($this->binding,$raw,$signature);self::assertNotWPError($event);
        $result=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);self::assertNotWPError($result);self::assertFalse($result['business_transition_authorized']);$this->keys[]=$event['dedupe_key'];return $event['dedupe_key'];
    }
    private function threeRetries(string $key):array
    {
        for($revision=0;$revision<3;$revision++){$result=PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',$revision,'reviewed local receipt retry');self::assertNotWPError($result);self::assertFalse($result['scheduled']);self::assertFalse($result['business_transition_authorized']);}
        return $result;
    }
    private function stateKey(string $key):string{return 'digiforge_pfy_hook_state_'.$key;}
    private function state(string $key):?string{global $wpdb;return $wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$this->stateKey($key)));}
    private function audits(string $key):array{global $wpdb;return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s ORDER BY id','printify_webhook_reconciled','printify_webhook',$key),ARRAY_A);}
}
