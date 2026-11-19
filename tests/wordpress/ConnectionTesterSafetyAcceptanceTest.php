<?php
declare(strict_types=1);

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\ConnectionTester;
use DigiForge\Integrations\Repository;

/** Actual local connector entry point; every HTTP response is synthetic. */
final class ConnectionTesterSafetyAcceptanceTest extends WP_UnitTestCase
{
    private array $fixtureConnectorIds=[];
    private function beginFixtureTransaction(): void
    {
        global $wpdb;$wpdb->last_error='';self::assertNotFalse($wpdb->query('START TRANSACTION'));self::assertSame('',$wpdb->last_error);
    }
    private function assertFixtureRowsRolledBack(): void
    {
        global $wpdb;self::assertNotFalse($wpdb->query('ROLLBACK'));
        foreach($this->fixtureConnectorIds as $id){
            self::assertSame(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::integrations().' WHERE id=%d',$id)),'Synthetic connector must disappear after fixture rollback.');
            self::assertSame(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::integration_secrets().' WHERE integration_id=%d',$id)),'Synthetic secrets must disappear after fixture rollback.');
        }
    }
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();self::assertTrue(Settings::protectProduction());$this->beginFixtureTransaction();
    }
    protected function tearDown(): void
    {
        try{$this->assertFixtureRowsRolledBack();}
        finally{self::assertTrue(Settings::protectProduction());$this->beginFixtureTransaction();parent::tearDown();}
    }
    public static function lockedProviders(): array
    {
        $cases=[];
        foreach(['etsy','ai','gelato'] as $provider){
            $cases[$provider.' protected']=[$provider,null,false];
            foreach(['activation_authorized','automation_armed','stop_all'] as $flag){
                $cases[$provider.' missing '.$flag]=[$provider,$flag,false];
                $cases[$provider.' corrupt '.$flag]=[$provider,$flag,true];
            }
        }
        return $cases;
    }
    private function connector(string $provider): int
    {
        $repo=new Repository();$row=$repo->create(['provider'=>$provider,'environment'=>'production','connection_key'=>'safety-'.wp_generate_uuid4(),'display_name'=>'Synthetic locked connector','status'=>'DISCONNECTED','enabled'=>false,'config'=>['vendor'=>'openai','preserve'=>'local-only-marker']]);
        self::assertNotWPError($row);$id=(int)$row['id'];$this->fixtureConnectorIds[]=$id;
        foreach($provider==='etsy'?['keystring','shared_secret','access_token']:($provider==='gelato'?['personal_access_token']:['api_key']) as $name)self::assertTrue($repo->storeSecret($id,$name,'synthetic-safety-'.$name));
        return $id;
    }
    private function snapshot(int $id): array
    {
        global $wpdb;
        return [$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::integrations().' WHERE id=%d',$id),ARRAY_A),$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::integration_secrets().' WHERE integration_id=%d ORDER BY id',$id),ARRAY_A)];
    }
    private function syntheticHttp(int &$calls): Closure
    {
        return static function($pre,array $args,string $url)use(&$calls):array{
            ++$calls;
            $body=str_contains($url,'/models')?['data'=>[['id'=>'synthetic-model']]]:(str_ends_with($url,'/users/me')?['user_id'=>1]:(str_contains($url,'/shops')?['shop_id'=>2,'user_id'=>1,'shop_name'=>'Synthetic']:['application_id'=>1]));
            return ['headers'=>[],'body'=>wp_json_encode($body),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
        };
    }
    /** @dataProvider lockedProviders */
    public function testProtectedMissingAndCorruptAdmissionNeverDecryptsCallsOrMutatesConnector(string $provider,?string $flag,bool $corrupt): void
    {
        if($flag!==null){self::assertTrue(Settings::set('research',true));self::assertTrue(Settings::activateResearch());global $wpdb;
            if($corrupt)$wpdb->update(Tables::settings(),['setting_value'=>'"invalid boolean"'],['setting_key'=>$flag]);
            else $wpdb->delete(Tables::settings(),['setting_key'=>$flag]);
        }
        $this->beginFixtureTransaction();self::assertTrue(Settings::safety_locked());$id=$this->connector($provider);$before=$this->snapshot($id);$calls=0;$credentialReads=[];$http=$this->syntheticHttp($calls);
        $capture=static function(string $sql)use(&$credentialReads):string{if(str_starts_with(ltrim($sql),'SELECT ciphertext FROM '))$credentialReads[]=$sql;return $sql;};
        global $wpdb;$audits=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='integration_connection_test_denied' AND object_id=%s",(string)$id));
        add_filter('pre_http_request',$http,10,3);add_filter('query',$capture);
        try{$result=(new ConnectionTester())->test($id);}finally{remove_filter('pre_http_request',$http,10);remove_filter('query',$capture);}
        self::assertSame(0,$calls);self::assertSame([],$credentialReads);self::assertWPError($result);self::assertSame('integration_connection_test_safety_locked',$result->get_error_code());self::assertSame(409,$result->get_error_data()['status']);self::assertSame($before,$this->snapshot($id));
        self::assertSame($audits+1,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='integration_connection_test_denied' AND object_id=%s",(string)$id)));
    }
    public static function providers(): array{return [['etsy'],['ai'],['gelato']];}
    /** @dataProvider providers */
    public function testDeniedAuditFailureIsUnavailableWithoutNetworkOrConnectorMutation(string $provider): void
    {
        $id=$this->connector($provider);$before=$this->snapshot($id);$calls=0;$http=$this->syntheticHttp($calls);global $wpdb;$table=Tables::audit_log();
        $fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'INSERT INTO `'.$table.'`')?str_replace('`'.$table.'`','`missing_connection_denial_audit`',$sql):$sql;
        $prior=$wpdb->suppress_errors(true);add_filter('pre_http_request',$http,10,3);add_filter('query',$fault);
        try{$result=(new ConnectionTester())->test($id);}finally{remove_filter('pre_http_request',$http,10);remove_filter('query',$fault);$wpdb->suppress_errors($prior);}
        self::assertSame(0,$calls);self::assertWPError($result);self::assertSame('integration_connection_test_audit_unavailable',$result->get_error_code());self::assertSame(503,$result->get_error_data()['status']);self::assertSame($before,$this->snapshot($id));
    }
}
