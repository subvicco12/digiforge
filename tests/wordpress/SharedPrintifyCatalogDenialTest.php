<?php
declare(strict_types=1);

/** Denial checks only: never creates a catalog grant, exception or remote request. */
final class SharedPrintifyCatalogDenialTest extends WP_UnitTestCase
{
    public function testAnonymousAndUnscopedAccountsCannotReachCatalogHandlers(): void
    {
        $http=0;
        $deny=static function () use (&$http) {++$http;throw new RuntimeException('Unexpected HTTP');};
        add_filter('pre_http_request',$deny);
        $controller=new \DigiForge\REST\PrintifyCatalogReadController();
        try {
            foreach([0,self::factory()->user->create(['role'=>'subscriber'])] as $account){
                wp_set_current_user($account);
                $request=new WP_REST_Request('GET','/digiforge/v1/printify/catalog-read/'.str_repeat('a',64));
                $request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
                foreach(['canRead','canApprove','read','approve','approveSafetyException','revoke','revokeSafetyException','evidence'] as $method){
                    $result=$controller->$method($request);
                    self::assertWPError($result);self::assertSame('catalog_read_forbidden',$result->get_error_code());
                }
            }
            self::assertSame(0,$http);
        } finally {remove_filter('pre_http_request',$deny);wp_set_current_user(0);}
    }
    public function testMissingNonceAndFabricatedReservationFailClosed(): void
    {
        wp_set_current_user(0);
        $controller=new \DigiForge\REST\PrintifyCatalogReadController();
        self::assertWPError($controller->canRead(new WP_REST_Request('GET','/')));
        $result=\DigiForge\POD\PrintifyCatalogReadPolicy::authorizeReserved([],['kind'=>'size_guide','blueprint_id'=>5],'noncredential-test-value',false);
        self::assertWPError($result);
        self::assertSame('catalog_read_reserved_scope',$result->get_error_code());
    }
}
