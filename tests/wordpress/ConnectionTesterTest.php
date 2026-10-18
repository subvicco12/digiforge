<?php

declare(strict_types=1);

final class ConnectionTesterTest extends WP_UnitTestCase
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
        parent::setUp();
        DigiForge\Core\Activator::activate();
        $this->beginFixtureTransaction();
        // Existing released-path fixtures use synthetic HTTP only; STOP ALL is restored below.
        self::assertTrue(DigiForge\Core\Settings::set('research', true));
        self::assertTrue(DigiForge\Core\Settings::activateResearch());
        $this->beginFixtureTransaction();
    }

    protected function tearDown(): void
    {
        try{$this->assertFixtureRowsRolledBack();}
        finally{self::assertTrue(DigiForge\Core\Settings::protectProduction());$this->beginFixtureTransaction();parent::tearDown();}
    }

    public function testLegacyPrintifyConnectionCannotUseCredentialWithoutReviewedScope(): void
    {
        DigiForge\Core\Activator::activate();
        $this->beginFixtureTransaction();
        self::assertTrue(DigiForge\Core\Settings::set('research', true));
        self::assertTrue(DigiForge\Core\Settings::set('ai', true));
        self::assertTrue(DigiForge\Core\Settings::set('product_development', true));
        self::assertTrue(DigiForge\Core\Settings::set('printify', true));
        self::assertTrue(DigiForge\Core\Settings::activateResearch());
        self::assertTrue(DigiForge\Core\Settings::activateAi());
        self::assertTrue(DigiForge\Core\Settings::activateProductDevelopment());
        self::assertTrue(DigiForge\Core\Settings::activatePrintify());
        $this->beginFixtureTransaction();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'printify',
            'environment' => 'production',
            'connection_key' => 'test_printify_'.wp_generate_uuid4(),
            'display_name' => 'Test Printify',
            'status' => 'DISCONNECTED',
            'enabled' => false,
            'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];$this->fixtureConnectorIds[]=$id;
        self::assertTrue($repository->storeSecret($id, 'personal_access_token', 'unit-test-printify-token') === true);

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
            $result = (new DigiForge\Integrations\ConnectionTester())->test($id);
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        self::assertWPError($result);self::assertSame('printify_connection_scope_required',$result->get_error_code());
        self::assertSame('', $seenUrl);self::assertSame('', $seenAuthorization);
        $updated=$repository->find($id);self::assertIsArray($updated);self::assertSame('DISCONNECTED',$updated['status']);self::assertArrayNotHasKey('_connection_test',$updated['config']);

    }

    public function testEtsyAuthenticatedConnectionUsesUsersMeAndRecordsConfigured(): void
    {
        DigiForge\Core\Activator::activate();
        $this->beginFixtureTransaction();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy',
            'environment' => 'production',
            'connection_key' => 'test_etsy_'.wp_generate_uuid4(),
            'display_name' => 'Test Etsy',
            'status' => 'DISCONNECTED',
            'enabled' => false,
            'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];$this->fixtureConnectorIds[]=$id;
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
        $this->beginFixtureTransaction();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy', 'environment' => 'production', 'connection_key' => 'test_etsy_missing_shop_'.wp_generate_uuid4(),
            'display_name' => 'Test Etsy Missing Shop', 'status' => 'DISCONNECTED', 'enabled' => false, 'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];$this->fixtureConnectorIds[]=$id;
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
        $this->beginFixtureTransaction();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy', 'environment' => 'production', 'connection_key' => 'test_etsy_owner_mismatch_'.wp_generate_uuid4(),
            'display_name' => 'Test Etsy Owner Mismatch', 'status' => 'DISCONNECTED', 'enabled' => false, 'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];$this->fixtureConnectorIds[]=$id;
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
        $this->beginFixtureTransaction();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider' => 'etsy', 'environment' => 'production', 'connection_key' => 'test_etsy_safe_error_'.wp_generate_uuid4(),
            'display_name' => 'Test Etsy Safe Error', 'status' => 'DISCONNECTED', 'enabled' => false, 'config' => [],
        ]);
        self::assertFalse(is_wp_error($created));
        $id = (int) $created['id'];$this->fixtureConnectorIds[]=$id;
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
        $this->beginFixtureTransaction();
        $repository = new DigiForge\Integrations\Repository();
        $created = $repository->create([
            'provider'=>'etsy','environment'=>'production','connection_key'=>'test_etsy_shape_'.wp_generate_uuid4(),
            'display_name'=>'Test Etsy Shape','status'=>'DISCONNECTED','enabled'=>false,'config'=>[],
        ]);
        self::assertFalse(is_wp_error($created)); $id=(int)$created['id'];$this->fixtureConnectorIds[]=$id;
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
        $this->beginFixtureTransaction();
        $repository=new DigiForge\Integrations\Repository();
        $created=$repository->create(['provider'=>'etsy','environment'=>'production','connection_key'=>'test_etsy_canonical_'.wp_generate_uuid4(),'display_name'=>'Canonical Etsy','status'=>'DISCONNECTED','enabled'=>false,'config'=>[]]);
        self::assertFalse(is_wp_error($created)); $id=(int)$created['id'];$this->fixtureConnectorIds[]=$id;
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
