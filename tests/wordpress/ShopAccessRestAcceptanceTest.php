<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Real local REST dispatch, not a controller permission stub or external request. */
final class ShopAccessRestAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();
        (new DigiForge\REST\OrderController())->register();
        (new DigiForge\REST\ShopAccessController())->register();
        do_action('rest_api_init');
        if (class_exists(DigiForge\Security\ShopAccessRestGuard::class)) {
            (new DigiForge\Security\ShopAccessRestGuard())->register();
        }
    }

    public function testUnassignedAdministratorCannotReadOrderCollectionBeforeBusinessQueries(): void
    {
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        global $wpdb;
        $businessReads=0;
        $observe=static function(string $sql) use (&$businessReads): string {
            if (preg_match('/^SELECT/i', ltrim($sql)) && str_contains($sql, DigiForge\Database\Tables::orders())) {
                $businessReads++;
            }
            return $sql;
        };
        add_filter('query', $observe);
        try {
            $request=new WP_REST_Request('GET', '/digiforge/v1/orders/orders');
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
            $response=rest_get_server()->dispatch($request);
        } finally {
            remove_filter('query', $observe);
        }
        self::assertSame(403, $response->get_status());
        self::assertSame(0, $businessReads);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertTrue(DigiForge\Core\Settings::get('stop_all', true));
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed', false));
    }

    public function testIndependentOwnerCanGrantInspectAndRevokeWithoutImplicitBusinessAccess(): void
    {
        $owner=self::factory()->user->create(['role'=>'administrator']);
        $subject=self::factory()->user->create(['role'=>'administrator']);
        $cap='manage_digiforge_orders';
        ShopAccessFixture::provision([ShopAccessFixture::authority($owner,'digital',[$cap])]);
        wp_set_current_user($owner);
        $body=['subject_id'=>$subject,'shop'=>'digital','role'=>'administrator','caps'=>[$cap],'expires_at'=>time()+300,'request_id'=>'owner_http_'.str_replace('-','_',wp_generate_uuid4()),'expected_revision'=>0];
        $grant=$this->dispatch('POST','/digiforge/v1/shop-access/grants',$body);
        self::assertSame(201,$grant->get_status(),(string)wp_json_encode($grant->get_data()));
        $identity=$grant->get_data();self::assertFalse($identity['external_execution_authorized']);
        self::assertSame(409,$this->dispatch('POST','/digiforge/v1/shop-access/grants',$body)->get_status());
        $inspect=new WP_REST_Request('GET','/digiforge/v1/shop-access/grants');$inspect->set_query_params(['shop_key'=>'digital']);$inspect->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        $read=rest_do_request($inspect);self::assertSame(200,$read->get_status());self::assertSame(1,$read->get_data()['revision']);self::assertSame($identity['grant_id'],$read->get_data()['items'][0]['grant_id']);
        self::assertSame(403,$this->dispatch('GET','/digiforge/v1/orders/orders')->get_status());
        $orders=new DigiForge\Orders\Repository();
        $own=$orders->createOrder(['shop_reference'=>'digital','channel'=>'etsy','environment'=>'sandbox']);
        $foreign=$orders->createOrder(['shop_reference'=>'jewelry','channel'=>'etsy','environment'=>'sandbox']);
        self::assertNotWPError($own);self::assertNotWPError($foreign);
        wp_set_current_user($subject);
        $response=$this->dispatch('GET','/digiforge/v1/orders/'.$own['id'].'/readiness');
        // The real callback is admitted, but an empty order is not fulfillment-ready.
        self::assertSame(409,$response->get_status(),(string)wp_json_encode($response->get_data()));
        self::assertSame('order_line_items_missing',$response->get_data()['code']);
        self::assertSame(403,$this->dispatch('GET','/digiforge/v1/orders/'.$foreign['id'].'/readiness')->get_status());
        self::assertSame(403,$this->dispatch('GET','/digiforge/v1/orders/orders')->get_status());
        wp_set_current_user($owner);
        $revoke=$this->dispatch('POST','/digiforge/v1/shop-access/grants/'.$identity['grant_id'].'/revoke',['shop'=>'digital','request_id'=>'revoke_http_'.str_replace('-','_',wp_generate_uuid4()),'expected_revision'=>1]);
        self::assertSame(200,$revoke->get_status());
        wp_set_current_user($subject);self::assertSame(403,$this->dispatch('GET','/digiforge/v1/orders/'.$own['id'].'/readiness')->get_status());
    }
    private function dispatch(string $method,string $route,?array $body=null): WP_REST_Response
    {
        $request=new WP_REST_Request($method,$route);$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        if ($body!==null) {$request->set_header('Content-Type','application/json');$request->set_body((string)wp_json_encode($body));}
        return rest_do_request($request);
    }

    public function testCompoundRouteRequiresBothAssignedCapabilitiesEvenForGlobalAdministrator(): void
    {
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge_connections']);
        (new DigiForge\REST\EtsyOperationsController())->register();do_action('rest_api_init');
        self::assertTrue(current_user_can('manage_digiforge_orders'));
        self::assertSame(403,$this->dispatch('GET','/digiforge/v1/etsy/operations')->get_status());
    }

    public function testDeniedManagementRequestsPersistAuditWithoutChangingAssignments(): void
    {
        global $wpdb;
        wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));
        $table=DigiForge\Database\Tables::audit_log();
        $before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE event_type='shop_access_management_denied'");
        self::assertSame(403,$this->dispatch('POST','/digiforge/v1/shop-access/grants',[])->get_status());
        self::assertSame(403,$this->dispatch('POST','/digiforge/v1/shop-access/grants/'.str_repeat('a',64).'/revoke',[])->get_status());
        self::assertSame($before+2,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE event_type='shop_access_management_denied'"));
        self::assertSame(0,(new DigiForge\Security\ShopAccessRepository())->read('digital')['revision']);
        $fail=static function(string $sql) use($table):string {
            return preg_match('/^INSERT/i',ltrim($sql))&&str_contains($sql,$table)?'INSERT INTO df_missing_denial_audit_table (id) VALUES (1)':$sql;
        };
        $prior=$wpdb->suppress_errors(true);add_filter('query',$fail);
        try {self::assertSame(503,$this->dispatch('POST','/digiforge/v1/shop-access/grants',[])->get_status());}
        finally {remove_filter('query',$fail);$wpdb->suppress_errors($prior);}
    }
}
