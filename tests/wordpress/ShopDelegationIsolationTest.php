<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Synthetic signed delegates only: no production identities or activation. */
final class ShopDelegationIsolationTest extends WP_UnitTestCase
{
    public function testDelegateCannotGrantOrRevokeOutsideItsExactShopAndCapabilities(): void
    {
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();
        $delegate=self::factory()->user->create(['role'=>'administrator']);
        $subject=self::factory()->user->create(['role'=>'administrator']);
        $cap='manage_digiforge_research';
        $other='manage_digiforge_orders';
        ShopAccessFixture::provision([ShopAccessFixture::authority($delegate,'digital',[$cap],time()+3600,'DELEGATE')]);
        $repo=new DigiForge\Security\ShopAccessRepository();
        wp_set_current_user($delegate);
        $initial=$repo->read('digital');
        self::assertNotWPError($initial);
        self::assertWPError($repo->grant($subject,'jewelry','administrator',[$cap],time()+300,'delegate_cross_shop_123',0));
        self::assertWPError($repo->grant($subject,'digital','administrator',[$other],time()+300,'delegate_cross_cap_123',$initial['revision']));
        $granted=$repo->grant($subject,'digital','administrator',[$cap],time()+300,'delegate_exact_scope_123',$initial['revision']);
        self::assertNotWPError($granted);
        wp_set_current_user($subject);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('jewelry',$cap));
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$other));
        self::assertSame(['digital'],DigiForge\Security\ShopAccess::allowedShops($cap));
        wp_set_current_user($delegate);
        ShopAccessFixture::provision([]);
        self::assertWPError($repo->revoke('digital',$granted['grant_id'],'delegate_without_authority_123',$granted['revision']));
        wp_set_current_user($subject);
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        ShopAccessFixture::provision([ShopAccessFixture::authority($delegate,'digital',[$cap],time()+3600,'DELEGATE')]);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        wp_set_current_user($delegate);
        $revoked=$repo->revoke('digital',$granted['grant_id'],'delegate_valid_revoke_123',$granted['revision']);
        self::assertNotWPError($revoked);
        wp_set_current_user($subject);
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }

    public function testDelegatedSingleShopGrantCannotUseForeignOrderIdWithForgedShopClaim(): void
    {
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();
        (new DigiForge\REST\OrderController())->register();
        (new DigiForge\Security\ShopAccessRestGuard())->register();
        do_action('rest_api_init');
        $delegate=self::factory()->user->create(['role'=>'administrator']);
        $subject=self::factory()->user->create(['role'=>'administrator']);
        $cap='manage_digiforge_orders';
        ShopAccessFixture::provision([ShopAccessFixture::authority($delegate,'digital',[$cap],time()+3600,'DELEGATE')]);
        self::assertNotWPError(ShopAccessFixture::grant($delegate,$subject,'digital',[$cap]));
        $orders=new DigiForge\Orders\Repository();
        $own=$orders->createOrder(['shop_reference'=>'digital','channel'=>'etsy','environment'=>'sandbox']);
        $foreign=$orders->createOrder(['shop_reference'=>'jewelry','channel'=>'etsy','environment'=>'sandbox']);
        self::assertNotWPError($own);
        self::assertNotWPError($foreign);
        wp_set_current_user($subject);
        $request=new WP_REST_Request('GET','/digiforge/v1/orders/'.$foreign['id'].'/readiness');
        $request->set_param('shop_key','digital');
        $request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        self::assertSame(403,rest_do_request($request)->get_status());
        $ownRequest=new WP_REST_Request('GET','/digiforge/v1/orders/'.$own['id'].'/readiness');
        $ownRequest->set_param('shop_key','digital');
        $ownRequest->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        $response=rest_do_request($ownRequest);
        self::assertSame(409,$response->get_status());
        self::assertSame('order_line_items_missing',$response->get_data()['code']);
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }
}
