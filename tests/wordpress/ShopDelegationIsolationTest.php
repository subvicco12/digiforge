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
        $authority=ShopAccessFixture::authority($delegate,'digital',[$cap],time()+3600,'DELEGATE');
        ShopAccessFixture::provision([$authority]);
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
        ShopAccessFixture::provision([$authority]);
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
    /** Stored Etsy preflight is synthetic evidence, never an assertion of provider ownership. */
    public function testStoredProviderShopMappingsRejectMissingMismatchedAndStalePreflight(): void
    {
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        global $wpdb;
        $table=DigiForge\Database\Tables::integrations();
        $orders=new DigiForge\Orders\Repository();
        $foreign=$orders->createOrder(['shop_reference'=>'synthetic-provider-987','channel'=>'etsy','environment'=>'sandbox']);
        self::assertNotWPError($foreign);
        $predicate=DigiForge\Listings\EtsyStoredShopScope::orderPredicate('digital','scope_order');
        $lookup=static function() use ($wpdb,$predicate,$foreign): mixed {
            $table=DigiForge\Database\Tables::orders();
            return $wpdb->get_var($wpdb->prepare('SELECT scope_order.id FROM '.$table.' scope_order WHERE scope_order.id=%d AND '.$predicate.' LIMIT 1',(int)$foreign['id']));
        };
        self::assertNull($lookup(), 'No stored mapping must not authorize a provider order');
        $base=['provider'=>'etsy','environment'=>'sandbox','connection_key'=>'synthetic-scope-'.wp_generate_uuid4(),'display_name'=>'Synthetic scope','status'=>'CONFIGURED','enabled'=>0,'created_by'=>0,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)];
        $fingerprint='0123456789abcdef';
        $credentialVersion=DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata([['secret_name'=>'access_token','fingerprint'=>$fingerprint]]);
        $config=static function(string $id,string $name,bool $ok) use (&$credentialVersion): string {
            return (string)wp_json_encode(['_connection_test'=>['ok'=>$ok,'checked_at'=>current_time('mysql',true),'credential_evidence_version'=>$credentialVersion,'details'=>['shop_id'=>$id,'shop_name'=>$name]]]);
        };
        $base['config']=$config('synthetic-provider-987','wrong-shop',true);
        self::assertNotFalse($wpdb->insert($table,$base));
        $integration=(int)$wpdb->insert_id;
        self::assertNotFalse($wpdb->insert(DigiForge\Database\Tables::integration_secrets(),['integration_id'=>$integration,'secret_name'=>'access_token','ciphertext'=>'synthetic-never-used','fingerprint'=>$fingerprint,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)]));
        self::assertNull($lookup(), 'Mismatched shop name must deny');
        self::assertNotFalse($wpdb->update($table,['config'=>$config('synthetic-provider-987','digital',false)],['id'=>$integration]));
        self::assertNull($lookup(), 'Stale or failed preflight must deny');
        self::assertNotFalse($wpdb->update($table,['config'=>$config('synthetic-provider-987','digital',true),'status'=>'PAUSED'],['id'=>$integration]));
        self::assertNull($lookup(), 'Paused integration must deny');
        self::assertNotFalse($wpdb->update($table,['status'=>'CONFIGURED'],['id'=>$integration]));
        self::assertNotFalse($wpdb->update($table,['config'=>$config('synthetic-provider-rotated','digital',true),'status'=>'CONFIGURED'],['id'=>$integration]));
        self::assertNull($lookup(), 'Rotated provider identity must not retain the former external shop binding');
        self::assertNotFalse($wpdb->update($table,['config'=>$config('synthetic-provider-987','digital',true),'environment'=>'production'],['id'=>$integration]));
        self::assertNull($lookup(), 'A production integration must not authorize a sandbox order');
        self::assertNotFalse($wpdb->update($table,['config'=>'{invalid-json','environment'=>'sandbox'],['id'=>$integration]));
        self::assertNull($lookup(), 'Invalid stored preflight JSON must fail closed');
        self::assertNotFalse($wpdb->update($table,['config'=>$config('synthetic-provider-987','digital',true)],['id'=>$integration]));

        self::assertSame((string)$foreign['id'],(string)$lookup(), 'Only a matching stored successful configured preflight can resolve the synthetic identity');
        self::assertNotFalse($wpdb->update(DigiForge\Database\Tables::integration_secrets(),['fingerprint'=>'fedcba9876543210','updated_at'=>current_time('mysql',true)],['integration_id'=>$integration,'secret_name'=>'access_token']));
        self::assertNull($lookup(), 'Credential rotation invalidates the earlier successful provider identity preflight');
        $credentialVersion=DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata([['secret_name'=>'access_token','fingerprint'=>'fedcba9876543210']]);
        self::assertNotFalse($wpdb->update($table,['config'=>$config('synthetic-provider-987','digital',true)],['id'=>$integration]));
        self::assertSame((string)$foreign['id'],(string)$lookup(), 'A new successful preflight bound to current credential metadata restores identity');
        $duplicate=$base;
        $duplicate['connection_key']='synthetic-conflict-'.wp_generate_uuid4();
        $duplicate['config']=$config('synthetic-provider-987','jewelry',true);
        self::assertNotFalse($wpdb->insert($table,$duplicate));
        $duplicateId=(int)$wpdb->insert_id;
        self::assertNotFalse($wpdb->insert(DigiForge\Database\Tables::integration_secrets(),['integration_id'=>$duplicateId,'secret_name'=>'access_token','ciphertext'=>'synthetic-never-used','fingerprint'=>'fedcba9876543210','created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)]));
        self::assertNull($lookup(), 'Duplicate configured provider identity must not resolve to either local shop');
        $jewelryPredicate=DigiForge\Listings\EtsyStoredShopScope::orderPredicate('jewelry','scope_order');
        $jewelryLookup=$wpdb->get_var($wpdb->prepare('SELECT scope_order.id FROM '.DigiForge\Database\Tables::orders().' scope_order WHERE scope_order.id=%d AND '.$jewelryPredicate.' LIMIT 1',(int)$foreign['id']));
        self::assertNull($jewelryLookup, 'Conflicting provider ID must also deny the other claimed local shop');

        self::assertNotFalse($wpdb->update($table,['status'=>'PAUSED'],['id'=>$duplicateId]));
        self::assertSame((string)$foreign['id'],(string)$lookup(), 'Removing conflicting configured evidence restores unique local resolution');

        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }

}
