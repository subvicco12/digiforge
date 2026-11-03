<?php
declare(strict_types=1);
use DigiForge\DigitalFactory\{DriveWordPressGrantStore,DriveWordPressGoogleGate,DriveWordPressGoogleHttp,DriveGoogleOAuth};
use DigiForge\Operations\RecoveryDispatchLedger;
use DigiForge\Core\Settings;

/** Actual durable database boundary; no live credentials, safety changes or provider requests. */
final class DriveGoogleOperationalDbTest extends WP_UnitTestCase
{
    private array $keys=[];
    protected function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();}
    protected function tearDown():void{
        global $wpdb;foreach($this->keys as $key)$wpdb->delete($wpdb->options,['option_name'=>$key]);
        $wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');
        self::assertTrue(Settings::get('stop_all',true));self::assertTrue(Settings::safety_locked());self::assertFalse(Settings::get('automation_armed',false));parent::tearDown();
    }
    public function testNamespaceInsertRaceAndRevocationCannotResurrect():void{
        $a=new DriveWordPressGrantStore();$b=new DriveWordPressGrantStore();
        foreach(['oauth','connection','operation'] as $kind){
            $key=DriveGoogleOAuth::key($kind,wp_generate_uuid4());$this->keys[]=$key;
            self::assertSame([],$a->read($key));self::assertTrue($a->compareAndSwap($key,[],['state'=>'ACTIVE','ciphertext'=>'synthetic-envelope']));
            self::assertFalse($b->compareAndSwap($key,[],['state'=>'ACTIVE','ciphertext'=>'foreign']));
            self::assertTrue($b->compareAndSwap($key,['state'=>'ACTIVE','ciphertext'=>'synthetic-envelope'],['state'=>'REVOKED','ciphertext'=>'']));
            self::assertFalse($a->compareAndSwap($key,['state'=>'ACTIVE','ciphertext'=>'synthetic-envelope'],['state'=>'ACTIVE','ciphertext'=>'stale']));
            self::assertSame(['state'=>'REVOKED','ciphertext'=>''],$a->read($key));
        }
        self::assertNull($a->read('digiforge_google_secrets_'.str_repeat('a',64)));
    }
    public function testRealLockedGateAndTransportNeverRetrieveOrSend():void{
        $gate=new DriveWordPressGoogleGate();self::assertFalse($gate->allows([]));self::assertFalse(RecoveryDispatchLedger::googleExecutionAllowed());
        $called=0;$filter=static function($response)use(&$called){$called++;return $response;};add_filter('pre_http_request',$filter);
        try{
            $transport=new DriveWordPressGoogleHttp($gate);
            try{$transport->exchange([],'https://oauth2.googleapis.com/token',['method'=>'POST','timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>16385,'headers'=>['Content-Type'=>'application/x-www-form-urlencoded','Accept-Encoding'=>'identity'],'body'=>'code=synthetic']);self::fail('Locked transport must deny.');}
            catch(RuntimeException $error){self::assertSame('Google dispatch blocked.',$error->getMessage());}
            self::assertSame(0,$called);
        }finally{remove_filter('pre_http_request',$filter);}
    }
}
