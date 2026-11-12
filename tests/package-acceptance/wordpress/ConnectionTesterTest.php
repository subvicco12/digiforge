<?php

declare(strict_types=1);
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';

final class ConnectionTesterTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        // Each provider fixture must establish its own read-only test gate.
        self::assertTrue(DigiForge\Core\Settings::set('research', true));
        self::assertTrue(DigiForge\Core\Settings::activateResearch());
    }

    protected function tearDown(): void
    {
        DigiForge\Core\Settings::protectProduction();
        parent::tearDown();
    }

    public function testPrintifyReadOnlyConnectionTestUsesStoredCredentialAndRecordsSuccess(): void
    {
        DigiForge\Core\Activator::activate();
        PortalAccessFixture::reset();
        PortalAccessFixture::enroll();
        self::assertTrue(DigiForge\Core\Settings::set('research', true));
        self::assertTrue(DigiForge\Core\Settings::set('ai', true));
        self::assertTrue(DigiForge\Core\Settings::set('product_development', true));
        self::assertTrue(DigiForge\Core\Settings::set('printify', true));
        self::assertTrue(DigiForge\Core\Settings::activateResearch());
        self::assertTrue(DigiForge\Core\Settings::activateAi());
        self::assertTrue(DigiForge\Core\Settings::activateProductDevelopment());
        self::assertTrue(DigiForge\Core\Settings::activatePrintify());
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'printify',
            'environment' => 'production',
            'connection_key' => 'test_printify',
            'display_name' => 'Test Printify',
            'status' => 'DISCONNECTED',
            'enabled' => false,
            'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];
        self::assertTrue($repository->storeSecret($id, 'personal_access_token', 'unit-test-printify-token') === true);

        // A disconnected disabled record has no runtime authority and must make no request.
        $deniedCalls=0;$deny=static function()use(&$deniedCalls){++$deniedCalls;return new WP_Error('offline_unexpected_http');};add_filter('pre_http_request',$deny);
        try{$denied=(new DigiForge\Integrations\ConnectionTester())->test($id);}finally{remove_filter('pre_http_request',$deny);}
        self::assertWPError($denied);self::assertSame('printify_connection_scope_required',$denied->get_error_code());self::assertSame(0,$deniedCalls);
        self::assertFalse((bool)$repository->find($id)['enabled']);
        global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);
        $wpdb->insert($t::businesses(),['business_key'=>'connection-fixture','display_name'=>'Synthetic connection business','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
        $wpdb->insert($t::stores(),['business_id'=>$business,'store_key'=>'connection-fixture-store','display_name'=>'Synthetic store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
        $wpdb->insert($t::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);
        self::assertNotWPError($repository->update($id,['status'=>'CONFIGURED','enabled'=>true,'config'=>['shop_key'=>'personalized_pod','account_reference'=>'synthetic-fixture-account','shop_id'=>'12345','catalog_read_scope'=>['business_id'=>(string)$business,'store_id'=>(string)$store,'product_program'=>'PERSONALIZED_POD']]]));
        $author=get_current_user_id();$reviewer=self::factory()->user->create(['role'=>'administrator']);
        PrintifyResearchAccessFixture::assign($reviewer,'personalized_pod',DigiForge\Core\Capabilities::ALL);
        $service=new DigiForge\POD\ScopedProviderConnection();$capability='printify.connection.read';
        $proposal=$service->propose('personalized_pod',['integration_id'=>$id,'capability'=>$capability,'source_sha256'=>hash('sha256','offline-connection-fixture'),'evidence_reference'=>'local://synthetic/connection-test','observed_at'=>gmdate('c',time()-10)],time()-1,time()+3600,wp_generate_uuid4(),0);self::assertNotWPError($proposal);
        wp_set_current_user($reviewer);$approved=$service->decide('personalized_pod',$id,$capability,$proposal['revision'],$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($approved);
        PortalAccessFixture::login($author);DigiForge\PortalAccess\Session::start();$scope=DigiForge\POD\PrintifyConnectionBoundary::context('personalized_pod',$id,$capability);self::assertNotWPError($scope);
        $seenAuthorization = '';
        $seenUrl = '';
        $filter = static function ($preempt, array $args, string $url) use (&$seenAuthorization, &$seenUrl) {
            $seenUrl = $url;
            $seenAuthorization = (string) ($args['headers']['Authorization'] ?? '');
            return [
                'headers' => [],
                'body' => wp_json_encode([
                    ['id' => 12345, 'title' => 'DigiCraftifyGoods', 'sales_channel' => 'etsy'],
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try {
            $wrongScope=$scope;$wrongScope['external_shop_reference']='99999';
            self::assertWPError((new DigiForge\Integrations\ConnectionTester())->test($id,$wrongScope));
            self::assertSame('', $seenUrl);
            self::assertSame('', $seenAuthorization);
            $result = (new DigiForge\Integrations\ConnectionTester())->test($id,$scope);
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        self::assertFalse(is_wp_error($result));
        self::assertSame('https://api.printify.com/v1/shops.json', $seenUrl);
        self::assertSame('Bearer unit-test-printify-token', $seenAuthorization);
        self::assertTrue((bool) $result['ok']);
        self::assertCount(1, $result['shops']);
        self::assertSame(12345, $result['shops'][0]['id']);

        $updated = $repository->find($id);
        self::assertIsArray($updated);
        self::assertSame('CONFIGURED', $updated['status']);
        self::assertTrue((bool) $updated['enabled']);
        self::assertFalse($result['provider_identity_verified']);
        self::assertFalse($result['external_execution_authorized']);
        self::assertTrue((bool) ($updated['config']['_connection_test']['ok'] ?? false));
        self::assertSame(DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata(
            $repository->secretMetadata($id)
        ),$updated['config']['_connection_test']['credential_evidence_version']);
        self::assertSame(1, (int) ($updated['config']['_connection_test']['details']['shop_count'] ?? 0));
        DigiForge\Core\Settings::protectProduction();
    }

    public function testEtsyAuthenticatedConnectionUsesUsersMeAndRecordsConfigured(): void
    {
        DigiForge\Core\Activator::activate();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy',
            'environment' => 'production',
            'connection_key' => 'test_etsy',
            'display_name' => 'Test Etsy',
            'status' => 'DISCONNECTED',
            'enabled' => false,
            'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];
        self::assertTrue($repository->storeSecret($id, 'keystring', 'unit-test-keystring') === true);
        self::assertTrue($repository->storeSecret($id, 'shared_secret', 'unit-test-shared-secret') === true);
        self::assertTrue($repository->storeSecret($id, 'access_token', 'unit-test-access-token') === true);

        $seen = [];
        $filter = static function ($preempt, array $args, string $url) use (&$seen) {
            $seen[] = [
                'url' => $url,
                'x_api_key' => (string) ($args['headers']['x-api-key'] ?? ''),
                'authorization' => (string) ($args['headers']['Authorization'] ?? ''),
            ];
            $body = $url === 'https://api.etsy.com/v3/application/users/me'
                ? ['user_id' => 1290867258]
                : (str_contains($url, '/users/1290867258/shops')
                    ? ['shop_id' => 24681012, 'user_id' => 1290867258]
                    : ($url === 'https://api.etsy.com/v3/application/shops/24681012'
                        ? ['shop_id' => 24681012, 'user_id' => 1290867258, 'shop_name' => 'DigiCraftifyDigital']
                        : ['application_id' => 1]));
            return [
                'headers' => [],
                'body' => wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try {
            $result = (new DigiForge\Integrations\ConnectionTester())->test($id);
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        self::assertFalse(is_wp_error($result));
        self::assertCount(4, $seen);
        self::assertSame('https://api.etsy.com/v3/application/openapi-ping', $seen[0]['url']);
        self::assertSame('unit-test-keystring:unit-test-shared-secret', $seen[0]['x_api_key']);
        self::assertSame('https://api.etsy.com/v3/application/users/me', $seen[1]['url']);
        self::assertSame('Bearer unit-test-access-token', $seen[1]['authorization']);
        self::assertSame('unit-test-keystring:unit-test-shared-secret', $seen[1]['x_api_key']);
        self::assertSame('https://api.etsy.com/v3/application/users/1290867258/shops', $seen[2]['url']);
        self::assertSame('unit-test-keystring:unit-test-shared-secret', $seen[2]['x_api_key']);
        self::assertSame('Bearer unit-test-access-token', $seen[2]['authorization']);
        self::assertSame('https://api.etsy.com/v3/application/shops/24681012', $seen[3]['url']);
        self::assertSame('Bearer unit-test-access-token', $seen[3]['authorization']);
        self::assertTrue((bool) $result['ok']);

        $updated = $repository->find($id);
        self::assertIsArray($updated);
        self::assertSame('CONFIGURED', $updated['status']);
        self::assertFalse((bool) $updated['enabled']);
        self::assertTrue((bool) ($updated['config']['_connection_test']['ok'] ?? false));
        self::assertSame(1290867258, (int) ($updated['config']['_connection_test']['details']['user_id'] ?? 0));
        self::assertSame(24681012, (int) ($updated['config']['_connection_test']['details']['shop_id'] ?? 0));
        self::assertSame('DigiCraftifyDigital', (string) ($updated['config']['_connection_test']['details']['shop_name'] ?? ''));
    }
    public function testEtsyConnectionFailsClosedWhenShopIdentityIsMissing(): void
    {
        DigiForge\Core\Activator::activate();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy', 'environment' => 'production', 'connection_key' => 'test_etsy_missing_shop',
            'display_name' => 'Test Etsy Missing Shop', 'status' => 'DISCONNECTED', 'enabled' => false, 'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];
        self::assertTrue($repository->storeSecret($id, 'keystring', 'unit-test-keystring') === true);
        self::assertTrue($repository->storeSecret($id, 'shared_secret', 'unit-test-shared-secret') === true);
        self::assertTrue($repository->storeSecret($id, 'access_token', 'unit-test-access-token') === true);

        $filter = static function ($preempt, array $args, string $url) {
            $body = $url === 'https://api.etsy.com/v3/application/users/me' ? ['user_id' => 1290867258] : (str_contains($url, '/shops') ? ['shop_id' => 0, 'shop_name' => ''] : ['application_id' => 1]);
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try { $result = (new DigiForge\Integrations\ConnectionTester())->test($id); }
        finally { remove_filter('pre_http_request', $filter, 10); }

        self::assertTrue(is_wp_error($result));
        self::assertSame('etsy_shop_identity_unverified', $result->get_error_code());
        $updated = $repository->find($id);
        self::assertIsArray($updated);
        self::assertSame('ERROR', $updated['status']);
        self::assertFalse((bool) ($updated['config']['_connection_test']['ok'] ?? true));
        self::assertSame('shop_identity_unverified', (string) ($updated['config']['_connection_test']['details']['reason'] ?? ''));
    }

    public function testEtsyConnectionRejectsShopOwnedByDifferentUser(): void
    {
        DigiForge\Core\Activator::activate();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy', 'environment' => 'production', 'connection_key' => 'test_etsy_owner_mismatch',
            'display_name' => 'Test Etsy Owner Mismatch', 'status' => 'DISCONNECTED', 'enabled' => false, 'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];
        self::assertTrue($repository->storeSecret($id, 'keystring', 'unit-test-keystring') === true);
        self::assertTrue($repository->storeSecret($id, 'shared_secret', 'unit-test-shared-secret') === true);
        self::assertTrue($repository->storeSecret($id, 'access_token', 'unit-test-access-token') === true);
        $filter = static function ($preempt, array $args, string $url) {
            $body = $url === 'https://api.etsy.com/v3/application/users/me'
                ? ['user_id' => 1290867258]
                : (str_contains($url, '/shops') ? ['shop_id' => 24681012, 'user_id' => 999999999, 'shop_name' => 'WrongOwnerShop'] : ['application_id' => 1]);
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try { $result = (new DigiForge\Integrations\ConnectionTester())->test($id); }
        finally { remove_filter('pre_http_request', $filter, 10); }
        self::assertTrue(is_wp_error($result));
        self::assertSame('etsy_shop_identity_unverified', $result->get_error_code());
    }

    public function testEtsyAuthenticationFailureRecordsSanitizedProviderEvidence(): void
    {
        DigiForge\Core\Activator::activate();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy', 'environment' => 'production', 'connection_key' => 'test_etsy_safe_error',
            'display_name' => 'Test Etsy Safe Error', 'status' => 'DISCONNECTED', 'enabled' => false, 'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];
        self::assertTrue($repository->storeSecret($id, 'keystring', 'unit-test-keystring') === true);
        self::assertTrue($repository->storeSecret($id, 'shared_secret', 'unit-test-shared-secret') === true);
        self::assertTrue($repository->storeSecret($id, 'access_token', 'unit-test-access-token') === true);
        $filter = static function ($preempt, array $args, string $url) {
            $body = $url === 'https://api.etsy.com/v3/application/users/me' ? ['user_id' => 1290867258] : (str_contains($url, '/shops') ? ['error' => 'invalid_scope', 'error_description' => 'Shop access is not permitted'] : ['application_id' => 1]);
            $status = str_contains($url, '/shops') ? 403 : 200;
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $status, 'message' => $status === 200 ? 'OK' : 'Forbidden'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try { $result = (new DigiForge\Integrations\ConnectionTester())->test($id); }
        finally { remove_filter('pre_http_request', $filter, 10); }
        self::assertTrue(is_wp_error($result));
        $updated = $repository->find($id);
        $details = $updated['config']['_connection_test']['details'] ?? [];
        self::assertSame('invalid_scope', $details['provider_error_code'] ?? '');
        self::assertSame('Shop access is not permitted', $details['provider_error_message'] ?? '');
        self::assertArrayNotHasKey('access_token', $details);
    }

    public function testEtsyMissingIdentityRecordsOnlySanitizedResponseShape(): void
    {
        DigiForge\Core\Activator::activate();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider'=>'etsy','environment'=>'production','connection_key'=>'test_etsy_shape',
            'display_name'=>'Test Etsy Shape','status'=>'DISCONNECTED','enabled'=>false,'config'=>[],
        ]);
        self::assertFalse(is_wp_error($created)); $id=(int)$created['id'];
        self::assertTrue($repository->storeSecret($id,'keystring','unit-test-keystring')===true);
        self::assertTrue($repository->storeSecret($id,'shared_secret','unit-test-shared-secret')===true);
        self::assertTrue($repository->storeSecret($id,'access_token','unit-test-access-token')===true);
        $filter=static function($preempt,array $args,string $url){
            $body=$url==='https://api.etsy.com/v3/application/users/me' ? ['user_id'=>1290867258] :
                (str_contains($url,'/shops') ? ['count'=>1,'results'=>[['shop_id'=>24681012,'user_id'=>1290867258,'shop_name'=>'DigiCraftifyDigital','secret'=>'do-not-record']]] : ['application_id'=>1]);
            return ['headers'=>[],'body'=>wp_json_encode($body),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
        };
        add_filter('pre_http_request',$filter,10,3);
        try{$result=(new DigiForge\Integrations\ConnectionTester())->test($id);}finally{remove_filter('pre_http_request',$filter,10);}
        self::assertTrue(is_wp_error($result));
        $updated=$repository->find($id); $shape=$updated['config']['_connection_test']['details']['shop_response_shape']??[];
        self::assertSame(['count','results'],$shape['top_level_keys']??[]);
        self::assertSame(1,$shape['results_count']??0);
        self::assertSame(24681012,$shape['first_result']['shop_id']??0);
        self::assertSame('DigiCraftifyDigital',$shape['first_result']['shop_name']??'');
        self::assertArrayNotHasKey('secret',$shape['first_result']??[]);
    }

    public function testEtsyConnectionResolvesCanonicalShopNameAfterOwnerLookup(): void
    {
        DigiForge\Core\Activator::activate();
        $repository=new DigiForge\Integrations\Repository();
        $created=$repository->create(['provider'=>'etsy','environment'=>'production','connection_key'=>'test_etsy_canonical','display_name'=>'Canonical Etsy','status'=>'DISCONNECTED','enabled'=>false,'config'=>[]]);
        self::assertFalse(is_wp_error($created)); $id=(int)$created['id'];
        self::assertTrue($repository->storeSecret($id,'keystring','unit-test-keystring')===true);
        self::assertTrue($repository->storeSecret($id,'shared_secret','unit-test-shared-secret')===true);
        self::assertTrue($repository->storeSecret($id,'access_token','unit-test-access-token')===true);
        $filter=static function($preempt,array $args,string $url){
            if($url==='https://api.etsy.com/v3/application/users/me'){$body=['user_id'=>1284202827];}
            elseif($url==='https://api.etsy.com/v3/application/users/1284202827/shops'){$body=['user_id'=>1284202827,'shop_id'=>67757764];}
            elseif($url==='https://api.etsy.com/v3/application/shops/67757764'){$body=['shop_id'=>67757764,'user_id'=>1284202827,'shop_name'=>'KinetiqMatrixDesigns'];}
            else{$body=['application_id'=>1];}
            return ['headers'=>[],'body'=>wp_json_encode($body),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
        };
        add_filter('pre_http_request',$filter,10,3);
        try{$result=(new DigiForge\Integrations\ConnectionTester())->test($id);}finally{remove_filter('pre_http_request',$filter,10);}
        self::assertFalse(is_wp_error($result));
        $updated=$repository->find($id);$details=$updated['config']['_connection_test']['details']??[];
        self::assertSame(67757764,$details['shop_id']??0);
        self::assertSame('KinetiqMatrixDesigns',$details['shop_name']??'');
        self::assertSame(1284202827,$details['user_id']??0);
    }

}
