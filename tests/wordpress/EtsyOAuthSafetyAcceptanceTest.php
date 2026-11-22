<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\EtsyOAuth;
use DigiForge\Integrations\Repository;

/** Real local handler admission; synthetic encrypted keys, no provider authentication. */
final class EtsyOAuthSafetyAcceptanceTest extends WP_UnitTestCase
{
    private int $integration;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],['manage_digiforge_connections']);
        $repo=new Repository();$row=$repo->create(['provider'=>'etsy','environment'=>'sandbox','connection_key'=>'oauth-safe-'.wp_generate_uuid4(),'display_name'=>'Synthetic OAuth admission','status'=>'DISCONNECTED','enabled'=>false,'config'=>[]]);self::assertNotWPError($row);$this->integration=(int)$row['id'];
        self::assertTrue($repo->storeSecret($this->integration,'keystring','synthetic-app-key'));self::assertTrue($repo->storeSecret($this->integration,'shared_secret','synthetic-app-secret'));
    }

    private function invoke(string $method,array $params): array
    {
        $get=$_GET;$request=$_REQUEST;$out=[];$calls=0;$privateReads=0;
        $die=static function()use(&$out){return static function($message='',$title='',$args=[])use(&$out):never{$out=['kind'=>'denied','message'=>(string)$message,'status'=>(int)($args['response']??500)];throw new RuntimeException('oauth-test-return');};};
        $redirect=static function(string $url)use(&$out):never{$out=['kind'=>'redirect','url'=>$url];throw new RuntimeException('oauth-test-return');};
        $http=static function($pre)use(&$calls){++$calls;return new WP_Error('offline_oauth','Provider HTTP forbidden.');};
        $reads=static function(string $sql)use(&$privateReads):string{if(str_starts_with(ltrim($sql),'SELECT')&&(str_contains($sql,Tables::integration_secrets())||str_contains($sql,'_transient_digiforge_etsy_oauth_')))++$privateReads;return $sql;};
        add_filter('wp_die_handler',$die);add_filter('wp_redirect',$redirect);add_filter('pre_http_request',$http);add_filter('query',$reads);
        try{$_GET=$_REQUEST=$params;try{(new EtsyOAuth())->$method();self::fail('OAuth handler must terminate.');}catch(RuntimeException $e){self::assertSame('oauth-test-return',$e->getMessage());}}
        finally{remove_filter('wp_die_handler',$die);remove_filter('wp_redirect',$redirect);remove_filter('pre_http_request',$http);remove_filter('query',$reads);$_GET=$get;$_REQUEST=$request;}
        return $out+['http_calls'=>$calls,'private_reads'=>$privateReads];
    }

    private function snapshot(): array
    {
        global $wpdb;return ['integration'=>(new Repository())->find($this->integration),'secrets'=>$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::integration_secrets().' WHERE integration_id=%d ORDER BY id',$this->integration),ARRAY_A)];
    }

    private function flow(): array
    {
        $state='synthetic-state-'.wp_generate_uuid4();$key='digiforge_etsy_oauth_'.hash('sha256',$state);
        $flow=['integration_id'=>$this->integration,'user_id'=>get_current_user_id(),'verifier'=>CredentialVault::encrypt(str_repeat('synthetic-pkce-',6),'etsy-oauth:'.$state),'created_at'=>time()];self::assertTrue(set_transient($key,$flow,600));return [$state,$key,$flow];
    }

    /** @dataProvider lockedSafetyFlags */
    public function testLockedCallbackDeniesBeforePrivateFlowOrHttpAndPreservesCredentials(string $key,string $mode): void
    {
        if($key!==''){global $wpdb;if($mode==='missing')$wpdb->delete(Tables::settings(),['setting_key'=>$key]);else $wpdb->update(Tables::settings(),['setting_value'=>'"corrupt"'],['setting_key'=>$key]);}
        [$state,$flowKey,$flow]=$this->flow();$before=$this->snapshot();
        foreach([1,2]as $attempt){$result=$this->invoke('callback',['state'=>$state,'code'=>'synthetic-code']);self::assertSame('denied',$result['kind']);self::assertSame(403,$result['status']);self::assertSame(0,$result['http_calls']);self::assertSame(0,$result['private_reads']);self::assertSame($flow,get_transient($flowKey));self::assertSame($before,$this->snapshot());}
        $this->assertAudit('callback',2);
    }

    public function lockedSafetyFlags(): array
    {
        return ['protected'=>['',''], 'activation missing'=>['activation_authorized','missing'],'activation corrupt'=>['activation_authorized','corrupt'],'automation missing'=>['automation_armed','missing'],'automation corrupt'=>['automation_armed','corrupt'],'stop missing'=>['stop_all','missing'],'stop corrupt'=>['stop_all','corrupt']];
    }

    private function assertAudit(string $phase,int $count=1): void
    {
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT context FROM ".Tables::audit_log()." WHERE event_type='etsy_oauth_safety_denied' AND actor_id=%d ORDER BY id",get_current_user_id()),ARRAY_A);self::assertCount($count,$rows);
        foreach($rows as $row){$context=json_decode($row['context'],true);self::assertSame($phase,$context['phase']);self::assertSame('production_safety_locked',$context['reason']);}
    }

    public function testLockedStartCannotInitializeFlowOrEmitExternalAuthorizationUrl(): void
    {
        global $wpdb;$before=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE '%digiforge_etsy_oauth_%' ORDER BY option_name",ARRAY_A);$snapshot=$this->snapshot();
        $result=$this->invoke('start',['integration_id'=>$this->integration,'_wpnonce'=>wp_create_nonce('digiforge_etsy_oauth_start')]);
        self::assertSame('denied',$result['kind']);self::assertSame(403,$result['status']);self::assertSame(0,$result['http_calls']);self::assertSame(0,$result['private_reads']);
        self::assertSame($before,$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE '%digiforge_etsy_oauth_%' ORDER BY option_name",ARRAY_A));self::assertSame($snapshot,$this->snapshot());$this->assertAudit('start');
    }

    /** @dataProvider deniedAuditPhases */
    public function testUnavailableDenialAuditReturns503WithoutHttpOrPrivateReads(string $phase): void
    {
        global $wpdb;$table=Tables::audit_log();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'INSERT INTO `'.$table.'`')?str_replace($table,$table.'_synthetic_missing',$sql):$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);$before=$this->snapshot();
        try{$result=$this->invoke($phase,['integration_id'=>$this->integration,'_wpnonce'=>wp_create_nonce('digiforge_etsy_oauth_start'),'state'=>'synthetic','code'=>'synthetic']);}
        finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        self::assertSame('denied',$result['kind']);self::assertSame(503,$result['status']);self::assertSame(0,$result['http_calls']);self::assertSame(0,$result['private_reads']);self::assertSame($before,$this->snapshot());
    }

    public function deniedAuditPhases(): array{return [['start'],['callback']];}

    public function testCapabilityAndNonceChecksStillDenyBeforeSafetyAdmission(): void
    {
        $actor=get_current_user_id();wp_set_current_user(0);$result=$this->invoke('callback',['state'=>'synthetic','code'=>'synthetic']);self::assertSame('denied',$result['kind']);self::assertSame(0,$result['http_calls']);
        wp_set_current_user($actor);$result=$this->invoke('start',['integration_id'=>$this->integration,'_wpnonce'=>'invalid']);self::assertSame('denied',$result['kind']);self::assertSame(0,$result['http_calls']);
        global $wpdb;self::assertSame(0,(int)$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='etsy_oauth_safety_denied'"));
    }
}
