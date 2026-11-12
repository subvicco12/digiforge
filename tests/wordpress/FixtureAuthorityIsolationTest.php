<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class FixtureAuthorityIsolationTest extends WP_UnitTestCase
{
    public function testLegacyFixtureResetClearsPriorSyntheticGlobalOwnerLifecycle(): void
    {
        DigiForge\Core\Activator::activate();PortalAccessFixture::reset();PortalAccessFixture::enroll();
        \DigiForge\Security\Logger::write('fixture_business_preservation', ['fixture'=>true], 'product', 'synthetic-preserved');
        \DigiForge\Security\Logger::write('shop_access_denied', ['fixture'=>true], 'shop_access', 'synthetic-preserved');
        ShopAccessFixture::reset();
        $state=(new DigiForge\Security\ShopAccessRepository())->read('digital');
        self::assertIsArray($state,is_wp_error($state)?$state->get_error_code():'');
        self::assertSame(0,$state['revision']);self::assertSame([],$state['grants']);
        global $wpdb;self::assertSame('2',$wpdb->get_var("SELECT COUNT(*) FROM ".DigiForge\Database\Tables::audit_log()." WHERE object_id='synthetic-preserved'"));
    }
}
