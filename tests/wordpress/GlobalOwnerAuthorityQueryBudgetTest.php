<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PortalAccessFixture.php';

/** A current owner check must not re-fetch each lifecycle row four times. */
final class GlobalOwnerAuthorityQueryBudgetTest extends WP_UnitTestCase
{
    protected function setUp():void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        PortalAccessFixture::reset();
    }
    protected function tearDown():void
    {
        PortalAccessFixture::reset();
        parent::tearDown();
    }

    public function testCurrentOwnerVerificationHasBoundedFreshRoundTrips():void
    {
        PortalAccessFixture::enroll();
        $profile=DigiForge\PortalAccess\Store::read(1);
        global $wpdb;
        $before=$wpdb->num_queries;
        self::assertTrue(DigiForge\PortalAccess\GlobalOwnerAuthority::active($profile));
        self::assertLessThanOrEqual(20,$wpdb->num_queries-$before,
            'A single fresh owner check must batch the four native lifecycle reads; never cache the authorization decision.');
    }
}
