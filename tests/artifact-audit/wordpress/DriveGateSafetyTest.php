<?php
declare(strict_types=1);
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\DigitalFactory\{DriveWordPressGoogleGate,DriveWordPressGoogleHttp};
use DigiForge\Operations\RecoveryDispatchLedger;

/** Disposable test database only. Never provisions Google credentials or sends HTTP. */
final class DriveGateSafetyTest extends WP_UnitTestCase
{
    public function testRealStopAndLockControlsDenyBeforeTransport(): void
    {
        if(!class_exists(DriveWordPressGoogleGate::class))$this->markTestSkipped('No inherited Drive transport in this source profile.');
        // Fresh disposable fixtures boot without WordPress activation; initialize
        // real settings rows so UPDATE/open-ledger assertions test the gate.
        Settings::ensure_defaults();
        if(!defined('DIGIFORGE_GOOGLE_EXECUTION_UNTIL'))define('DIGIFORGE_GOOGLE_EXECUTION_UNTIL',time()+800);
        if(!defined('DIGIFORGE_GOOGLE_EXECUTION_INSTALLATION'))define('DIGIFORGE_GOOGLE_EXECUTION_INSTALLATION',hash('sha256',site_url()));
        global $wpdb;$table=Tables::settings();
        $open=['stop_all'=>false,'activation_authorized'=>true,'automation_armed'=>true,'product_development'=>true,'product_development_activation_authorized'=>true];$original=[];
        foreach($open as $key=>$value)$original[$key]=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE setting_key=%s",$key),ARRAY_A);
        $http=0;$deny=static function()use(&$http){++$http;return new WP_Error('drive_safety_test_no_http');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
        try{
            // COMMIT exposes fixture writes to the real independent committed-read connection.
            foreach(['stop_all'=>true,'activation_authorized'=>false,'automation_armed'=>false,'product_development'=>false,'product_development_activation_authorized'=>false] as $closed=>$value){
                foreach($open as $key=>$flag)$wpdb->update($table,['setting_value'=>wp_json_encode($flag),'setting_type'=>'boolean'],['setting_key'=>$key]);
                $wpdb->query('COMMIT');
                self::assertTrue(RecoveryDispatchLedger::googleExecutionAllowed(),'Open committed-ledger fixture must be readable');
                self::assertFalse(Settings::safety_locked());self::assertTrue(Settings::is_enabled('product_development'));
                // Ownership is intentionally not provisioned: these transport assertions are
                // denial-only. Independent positive/negative settings/ledger assertions above
                // and below establish the safety predicate, not positive provider ownership.
                $state=$open;$state[$closed]=$value;
                foreach($state as $key=>$flag){$wpdb->update($table,['setting_value'=>wp_json_encode($flag),'setting_type'=>'boolean'],['setting_key'=>$key]);}
                $wpdb->query('COMMIT');
                self::assertFalse(RecoveryDispatchLedger::googleExecutionAllowed(),$closed);
                if(in_array($closed,['stop_all','activation_authorized','automation_armed'],true))self::assertTrue(Settings::safety_locked(),$closed);
                $gate=new DriveWordPressGoogleGate();self::assertFalse($gate->allows([]),$closed);
                $args=['method'=>'POST','timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>16385,'headers'=>['Content-Type'=>'application/x-www-form-urlencoded','Accept-Encoding'=>'identity'],'body'=>'code=synthetic'];
                self::assertTrue(DriveWordPressGoogleHttp::valid('https://oauth2.googleapis.com/token',$args));
                try{(new DriveWordPressGoogleHttp($gate))->exchange([],'https://oauth2.googleapis.com/token',$args);self::fail('Locked transport admitted');}
                catch(RuntimeException $e){self::assertSame('Google dispatch blocked.',$e->getMessage());}
                self::assertSame(0,$http,$closed);
            }
        }finally{
            foreach($original as $key=>$row){if(is_array($row))$wpdb->update($table,['setting_value'=>$row['setting_value'],'setting_type'=>$row['setting_type']],['setting_key'=>$key]);}
            $wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');remove_filter('pre_http_request',$deny,PHP_INT_MAX);
        }
    }
    public function testOAuthScopeCustodyAndCanonicalRequestCannotExpand(): void
    {
        $class=\DigiForge\DigitalFactory\DriveOAuthResponse::class;
        if(!class_exists($class))$this->markTestSkipped('No inherited Google protocol in this source profile.');
        $response=['access_token'=>'synthetic-not-a-credential','refresh_token'=>'synthetic-refresh','token_type'=>'Bearer','scope'=>$class::SCOPE,'expires_in'=>300];
        $safe=$class::describe($response,100,110,false);
        self::assertArrayNotHasKey('access_token',$safe);self::assertArrayNotHasKey('refresh_token',$safe);self::assertFalse($safe['external_execution_authorized']);
        foreach(['scope'=>'https://www.googleapis.com/auth/drive','token_type'=>'Basic','expires_in'=>3601] as $key=>$value){
            $bad=$response;$bad[$key]=$value;$denied=false;try{$class::describe($bad,100,110,false);}catch(InvalidArgumentException){$denied=true;}self::assertTrue($denied,$key);
        }
        foreach([[100,400,false],[100,110,true],[120,110,false]] as [$received,$now,$revoked]){
            $denied=false;try{$class::describe($response,$received,$now,$revoked);}catch(InvalidArgumentException){$denied=true;}self::assertTrue($denied);
        }
        $plan=['id'=>str_repeat('a',64),'source_sha256'=>hash('sha256','synthetic'),'product_id'=>1,'product_version_id'=>2];
        $request=\DigiForge\DigitalFactory\DriveTransferRequest::build('folder',$plan,[]);
        $wire=\DigiForge\DigitalFactory\DriveHttpsProtocol::encode($request,$plan,[],'synthetic');
        self::assertStringStartsWith('https://www.googleapis.com/drive/v3/files/',$wire['url']);self::assertFalse($wire['network_request_permitted']);
        $request['query']['q']='unapproved query';$denied=false;
        try{\DigiForge\DigitalFactory\DriveHttpsProtocol::encode($request,$plan,[],'synthetic');}catch(InvalidArgumentException){$denied=true;}self::assertTrue($denied);
    }

}
