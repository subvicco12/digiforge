<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\EtsyOAuth;
use DigiForge\Integrations\Repository;

/** Synthetic local OAuth/PKCE protocol only; no authentic app or provider certification. */
final class EtsyOAuthProtocolAcceptanceTest extends WP_UnitTestCase
{
    private int $integration;
    private array $requests=[];
    private int $foreignActor;
    private int $responseOwner=123;
    private Closure $http;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],['manage_digiforge_connections']);
        $this->foreignActor=self::factory()->user->create(['role'=>'administrator']);
        self::assertTrue(Settings::set('research',true));self::assertTrue(Settings::activateResearch());global $wpdb;self::assertNotFalse($wpdb->query('START TRANSACTION'));
        $row=(new Repository())->create(['provider'=>'etsy','environment'=>'sandbox','connection_key'=>'oauth-protocol-'.wp_generate_uuid4(),'display_name'=>'Synthetic OAuth protocol','status'=>'DISCONNECTED','enabled'=>false,'config'=>[]]);self::assertNotWPError($row);$this->integration=(int)$row['id'];
        self::assertTrue((new Repository())->storeSecret($this->integration,'keystring','synthetic-app-key'));self::assertTrue((new Repository())->storeSecret($this->integration,'shared_secret','synthetic-app-secret'));
        $this->http=function($pre,array $args,string $url):array|WP_Error{
            $this->requests[]=['url'=>$url,'args'=>$args];
            $payload=match($url){
                'https://api.etsy.com/v3/public/oauth/token'=>['access_token'=>'123.synthetic-access','refresh_token'=>'synthetic-refresh','expires_in'=>3600,'scope'=>'profile_r shops_r listings_r listings_w transactions_r'],
                'https://api.etsy.com/v3/application/openapi-ping'=>['application_id'=>123],
                'https://api.etsy.com/v3/application/users/me'=>['user_id'=>123],
                'https://api.etsy.com/v3/application/users/123/shops'=>['shop_id'=>456,'user_id'=>$this->responseOwner,'shop_name'=>'SyntheticProtocolShop'],
                'https://api.etsy.com/v3/application/shops/456'=>['shop_id'=>456,'user_id'=>$this->responseOwner,'shop_name'=>'SyntheticProtocolShop'],
                default=>null,
            };
            if($payload===null)return new WP_Error('offline_protocol','Unexpected endpoint blocked; no request can reach a provider.');
            return ['headers'=>[],'body'=>wp_json_encode($payload),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
        };add_filter('pre_http_request',$this->http,10,3);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http,10);global $wpdb;
        try{self::assertNotFalse($wpdb->query('ROLLBACK'));self::assertNull((new Repository())->find($this->integration));}
        finally{self::assertTrue(Settings::protectProduction());self::assertNotFalse($wpdb->query('START TRANSACTION'));parent::tearDown();}
    }
    private function invoke(string $method,array $params): array
    {
        $get=$_GET;$request=$_REQUEST;$out=[];
        $redirect=static function(string $url)use(&$out):never{$out=['kind'=>'redirect','url'=>$url];throw new RuntimeException('oauth-protocol-return');};
        $die=static function()use(&$out){return static function($message='',$title='',$args=[])use(&$out):never{$out=['kind'=>'denied','status'=>$args['response']??500,'message'=>(string)$message];throw new RuntimeException('oauth-protocol-return');};};
        add_filter('wp_redirect',$redirect);add_filter('wp_die_handler',$die);
        try{$_GET=$_REQUEST=$params;try{(new EtsyOAuth())->$method();self::fail('OAuth handler must terminate.');}catch(RuntimeException $e){self::assertSame('oauth-protocol-return',$e->getMessage());}}
        finally{$_GET=$get;$_REQUEST=$request;remove_filter('wp_redirect',$redirect);remove_filter('wp_die_handler',$die);}
        return $out;
    }
    private function start(): array
    {
        $result=$this->invoke('start',['integration_id'=>$this->integration,'_wpnonce'=>wp_create_nonce('digiforge_etsy_oauth_start')]);self::assertSame('redirect',$result['kind']);
        self::assertSame('https',wp_parse_url($result['url'],PHP_URL_SCHEME));self::assertSame('www.etsy.com',wp_parse_url($result['url'],PHP_URL_HOST));self::assertSame('/oauth/connect',wp_parse_url($result['url'],PHP_URL_PATH));
        parse_str((string)wp_parse_url($result['url'],PHP_URL_QUERY),$args);return $args;
    }
    public function testActualStartRetainsActorBoundEncryptedVerifierAndS256Challenge(): void
    {
        $args=$this->start();self::assertSame([],$this->requests);self::assertSame('code',$args['response_type']);self::assertSame('S256',$args['code_challenge_method']);self::assertSame('synthetic-app-key',$args['client_id']);self::assertSame(EtsyOAuth::callbackUrl(),$args['redirect_uri']);self::assertSame('profile_r shops_r listings_r listings_w transactions_r',$args['scope']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/',$args['state']);$key='digiforge_etsy_oauth_'.hash('sha256',$args['state']);$flow=get_transient($key);self::assertIsArray($flow);self::assertSame($this->integration,$flow['integration_id']);self::assertSame(get_current_user_id(),$flow['user_id']);
        $verifier=CredentialVault::decrypt($flow['verifier'],'etsy-oauth:'.$args['state']);self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/',$verifier);self::assertNotSame($verifier,$flow['verifier']);self::assertSame(rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),$args['code_challenge']);self::assertStringNotContainsString($verifier,$args['state']);
    }
    private function redirectQuery(array $result): array
    {
        self::assertSame('redirect',$result['kind']);self::assertSame(wp_parse_url(admin_url(),PHP_URL_HOST),wp_parse_url($result['url'],PHP_URL_HOST));parse_str((string)wp_parse_url($result['url'],PHP_URL_QUERY),$query);return $query;
    }
    private function secretRows(int $id): array
    {
        global $wpdb;return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::integration_secrets().' WHERE integration_id=%d ORDER BY id',$id),ARRAY_A);
    }
    public function testCallbackUsesRetainedPkceAndMockedV3OwnerIdentityThenStoresEncryptedTokensOnce(): void
    {
        $repo=new Repository();$foreign=$repo->create(['provider'=>'etsy','environment'=>'sandbox','connection_key'=>'oauth-other-'.wp_generate_uuid4(),'display_name'=>'Other synthetic shop','status'=>'DISCONNECTED','enabled'=>false,'config'=>['shop_identity'=>'other-fixture']]);self::assertNotWPError($foreign);$foreignId=(int)$foreign['id'];self::assertTrue($repo->storeSecret($foreignId,'access_token','other-synthetic-access'));$foreignBefore=[$repo->find($foreignId),$this->secretRows($foreignId)];
        $args=$this->start();$key='digiforge_etsy_oauth_'.hash('sha256',$args['state']);$flow=get_transient($key);$verifier=CredentialVault::decrypt($flow['verifier'],'etsy-oauth:'.$args['state']);
        $code='synthetic-code +/=';$query=$this->redirectQuery($this->invoke('callback',['state'=>$args['state'],'code'=>$code,'integration_id'=>$foreignId]));self::assertSame('0',$query['df_error']);self::assertFalse(get_transient($key));
        self::assertSame(['https://api.etsy.com/v3/public/oauth/token','https://api.etsy.com/v3/application/openapi-ping','https://api.etsy.com/v3/application/users/me','https://api.etsy.com/v3/application/users/123/shops','https://api.etsy.com/v3/application/shops/456'],array_column($this->requests,'url'));
        $token=$this->requests[0]['args'];self::assertSame('POST',$token['method']);self::assertSame('application/x-www-form-urlencoded',$token['headers']['Content-Type']);self::assertSame(['grant_type'=>'authorization_code','client_id'=>'synthetic-app-key','redirect_uri'=>EtsyOAuth::callbackUrl(),'code'=>$code,'code_verifier'=>$verifier],$token['body']);
        // Assert WordPress's transport parameters, not a provider's received wire bytes.
        parse_str(http_build_query($token['body'],'','&',PHP_QUERY_RFC1738),$encoded);self::assertSame($token['body'],$encoded);
        foreach($this->requests as $index=>$request){self::assertSame(0,$request['args']['redirection']);self::assertTrue($request['args']['sslverify']);if($index>0){self::assertSame('GET',$request['args']['method']);self::assertSame('synthetic-app-key:synthetic-app-secret',$request['args']['headers']['x-api-key']);if($index>1)self::assertSame('Bearer 123.synthetic-access',$request['args']['headers']['Authorization']);}}
        $connected=$repo->find($this->integration);self::assertSame('CONFIGURED',$connected['status']);self::assertFalse((bool)$connected['enabled']);self::assertSame(['http_status'=>200,'app_credentials'=>'valid','oauth'=>'valid','user_id'=>123,'shop_id'=>456,'shop_name'=>'SyntheticProtocolShop'],$connected['config']['_connection_test']['details']);self::assertTrue($connected['config']['_connection_test']['ok']);
        $secrets=$this->secretRows($this->integration);$tokens=[];foreach($secrets as $secret)if(in_array($secret['secret_name'],['access_token','refresh_token'],true)){$tokens[$secret['secret_name']]=CredentialVault::decrypt($secret['ciphertext'],Repository::secretContext($this->integration,$secret['secret_name']));self::assertStringNotContainsString($tokens[$secret['secret_name']],$secret['ciphertext']);}self::assertSame(['access_token'=>'123.synthetic-access','refresh_token'=>'synthetic-refresh'],$tokens);
        self::assertSame($foreignBefore,[$repo->find($foreignId),$this->secretRows($foreignId)]);$before=[$connected,$secrets];$requestCount=count($this->requests);
        $replay=$this->redirectQuery($this->invoke('callback',['state'=>$args['state'],'code'=>$code]));self::assertSame('1',$replay['df_error']);self::assertSame($requestCount,count($this->requests));self::assertSame($before,[$repo->find($this->integration),$this->secretRows($this->integration)]);
    }
    public function testForeignActorCannotConsumeStateIntoCredentialsOrProviderRequests(): void
    {
        $args=$this->start();$actor=get_current_user_id();$before=[(new Repository())->find($this->integration),$this->secretRows($this->integration)];wp_set_current_user($this->foreignActor);
        try{$query=$this->redirectQuery($this->invoke('callback',['state'=>$args['state'],'code'=>'synthetic']));}finally{wp_set_current_user($actor);}
        self::assertSame('1',$query['df_error']);self::assertStringContainsString('does not match the administrator',$query['df_notice']);self::assertSame([],$this->requests);self::assertFalse(get_transient('digiforge_etsy_oauth_'.hash('sha256',$args['state'])));self::assertSame($before,[(new Repository())->find($this->integration),$this->secretRows($this->integration)]);
    }
    public function testMissingCodeAndUnknownStateDoNotExchangeOrWriteTokens(): void
    {
        $args=$this->start();$key='digiforge_etsy_oauth_'.hash('sha256',$args['state']);$flow=get_transient($key);$before=$this->secretRows($this->integration);
        $incomplete=$this->redirectQuery($this->invoke('callback',['state'=>$args['state']]));self::assertSame('1',$incomplete['df_error']);self::assertSame($flow,get_transient($key));
        $unknown=$this->redirectQuery($this->invoke('callback',['state'=>'not-issued-synthetic-state','code'=>'synthetic']));self::assertSame('1',$unknown['df_error']);self::assertSame([],$this->requests);self::assertSame($before,$this->secretRows($this->integration));
    }
    public function testMockedForeignShopOwnerCannotProduceConfiguredConnection(): void
    {
        $this->responseOwner=999;$args=$this->start();$query=$this->redirectQuery($this->invoke('callback',['state'=>$args['state'],'code'=>'synthetic']));self::assertSame('1',$query['df_error']);$row=(new Repository())->find($this->integration);
        self::assertSame('ERROR',$row['status']);self::assertFalse($row['config']['_connection_test']['ok']);self::assertSame('shop_identity_unverified',$row['config']['_connection_test']['details']['reason']);self::assertFalse((bool)$row['enabled']);self::assertCount(4,$this->requests);
    }

    public function testRepeatedStartsRetainDistinctStateAndVerifierBindings(): void
    {
        $one=$this->start();$two=$this->start();self::assertNotSame($one['state'],$two['state']);self::assertNotSame($one['code_challenge'],$two['code_challenge']);self::assertSame([],$this->requests);
        foreach([$one,$two]as $args){$flow=get_transient('digiforge_etsy_oauth_'.hash('sha256',$args['state']));self::assertIsArray($flow);self::assertSame(get_current_user_id(),$flow['user_id']);$verifier=CredentialVault::decrypt($flow['verifier'],'etsy-oauth:'.$args['state']);self::assertSame(rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),$args['code_challenge']);}
    }
    public function testInvalidStartNonceCannotInitializeFlowOrReadProvider(): void
    {
        global $wpdb;$count=static fn():int=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%digiforge_etsy_oauth_%'");$before=$count();$secrets=$this->secretRows($this->integration);
        $result=$this->invoke('start',['integration_id'=>$this->integration,'_wpnonce'=>'synthetic-invalid-nonce']);self::assertSame('denied',$result['kind']);self::assertSame([],$this->requests);self::assertSame($before,$count());self::assertSame($secrets,$this->secretRows($this->integration));
    }

}
