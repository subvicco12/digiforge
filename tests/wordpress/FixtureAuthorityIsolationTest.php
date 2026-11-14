<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
final class FixtureAuthorityIsolationTest extends WP_UnitTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());
    }
    public function testLegacyFixtureResetClearsPriorSyntheticGlobalOwnerLifecycle(): void
    {
        DigiForge\Core\Activator::activate();PortalAccessFixture::reset();PortalAccessFixture::enroll();
        $marker='synthetic-preserved-'.wp_generate_uuid4();
        \DigiForge\Security\Logger::write('fixture_business_preservation', ['fixture'=>true], 'product', $marker);
        \DigiForge\Security\Logger::write('shop_access_denied', ['fixture'=>true], 'shop_access', $marker);
        ShopAccessFixture::reset();
        $state=(new DigiForge\Security\ShopAccessRepository())->read('digital');
        self::assertIsArray($state,is_wp_error($state)?$state->get_error_code():'');
        self::assertSame(0,$state['revision']);self::assertSame([],$state['grants']);
        global $wpdb;self::assertSame('2',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log().' WHERE object_id=%s',$marker)));
    }
    public function testResearchApprovalCannotPoisonFollowingLegacyFixture(): void
    {
        DigiForge\Core\Activator::activate();PortalAccessFixture::reset();ShopAccessFixture::reset();
        $actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);
        $caps=['manage_digiforge','manage_digiforge_pod'];
        PrintifyResearchAccessFixture::assign($actor,'personalized_pod',$caps);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_pod'));
        $marker='research-preserved-'.wp_generate_uuid4();
        DigiForge\Security\Logger::write('fixture_business_preservation',['fixture'=>true],'product',$marker);
        DigiForge\Security\Logger::write('shop_access_denied',['fixture'=>true],'shop_access',$marker);
        ShopAccessFixture::reset();
        $state=(new DigiForge\Security\ShopAccessRepository())->read('personalized_pod');
        self::assertIsArray($state,is_wp_error($state)?$state->get_error_code():'');
        self::assertSame(0,$state['revision']);self::assertSame([],$state['grants']);
        ShopAccessFixture::authorizeActor($actor,['personalized_pod'],$caps);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_pod'));
        global $wpdb;self::assertSame('2',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log().' WHERE object_id=%s',$marker)));
    }

    public function testResetRetainsUnownedLifecycleProofAndItsFailClosedState(): void
    {
        DigiForge\Core\Activator::activate();PortalAccessFixture::reset();ShopAccessFixture::reset();
        $actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);
        $unowned=bin2hex(random_bytes(32));self::assertFalse(SyntheticShopAuthority::ownsApproval($unowned));self::assertFalse(ServerOwnerApprovalFixture::ownsApproval($unowned));
        self::assertTrue(DigiForge\Security\Logger::write('shop_access_grant',['shop'=>'digital','grant'=>['grantor_id'=>$actor,'source_approval_hash'=>$unowned]],'shop_access','unowned-isolation-marker'));
        global $wpdb;$marker=(int)$wpdb->insert_id;
        try {
            ShopAccessFixture::reset();
            self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log().' WHERE id=%d',$marker)));
            self::assertWPError((new DigiForge\Security\ShopAccessRepository())->read('digital'));
        } finally {$wpdb->delete(DigiForge\Database\Tables::audit_log(),['id'=>$marker]);}
    }

    public function testDurableApiFixtureRestoresExactAuditHashIdentifiers(): void
    {
        DigiForge\Core\Activator::activate();
        PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());
        global $wpdb;
        $hash=hash('sha256','mockup restoration '.wp_generate_uuid4());
        self::assertTrue(DigiForge\Security\Logger::write('mockup_asset_registered',['sha256'=>$hash],'product_mockup',$hash));
        $id=(int)$wpdb->insert_id;
        $before=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::audit_log().' WHERE id=%d',$id),ARRAY_A);
        $fixture=new PrintifyApiApprovalFixture();
        try {
            $fixture->boot();$fixture->restore();
            $after=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::audit_log().' WHERE id=%d',$id),ARRAY_A);
            self::assertSame($hash,$after['object_id']);
            self::assertSame($before,$after,'Snapshot restoration must preserve every audit column exactly.');
        } finally {$wpdb->delete(DigiForge\Database\Tables::audit_log(),['id'=>$id]);}
    }

}
