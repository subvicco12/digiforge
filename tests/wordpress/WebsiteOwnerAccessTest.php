<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/ShopAccessFixture.php';

final class WebsiteOwnerAccessTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }
    private function input(): array
    {
        return ['_wpnonce' => wp_create_nonce(DigiForge\Portal\ShopAccessPanel::ACTION),'confirm' => 'CONFIRM_EXPLICIT_SHOP_ACCESS','operation' => 'ENROLL','shop' => 'digital','role' => 'administrator','caps' => ['manage_digiforge','manage_digiforge_digital'],'expires_at' => (string)(time() + 600),'approval' => hash('sha256', 'Synthetic independent authority ' . get_current_user_id() . ':digital'),'request_id' => 'owner_enrollment_request_1','expected_revision' => '0'];
    }
    public function testOwnerExplicitEnrollmentPersistsAndRevokesThroughExistingLifecycle(): void
    {
        $actor = get_current_user_id();
        $caps = ['manage_digiforge','manage_digiforge_digital'];
        $authority = ShopAccessFixture::authority($actor, 'digital', $caps);
        $delegate = $authority; $delegate['authority'] = 'DELEGATE';
        ShopAccessFixture::provision([$delegate, $authority]);
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital', 'manage_digiforge_digital'));
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $input = $this->input();
        $result = $panel->processBrowser($input);
        self::assertNotWPError($result);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital', 'manage_digiforge_digital'));
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('personalized_pod', 'manage_digiforge_digital'));
        self::assertWPError($panel->processBrowser($input));
        $revoke = ['_wpnonce' => wp_create_nonce($panel::ACTION),'confirm' => 'CONFIRM_EXPLICIT_SHOP_ACCESS','operation' => 'REVOKE','shop' => 'digital','grant_id' => $result['grant_id'],'request_id' => 'owner_revoke_request_123','expected_revision' => '1'];
        self::assertNotWPError($panel->processBrowser($revoke));
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital', 'manage_digiforge_digital'));
        self::assertTrue(DigiForge\Core\Settings::get('stop_all', true));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed', false));
    }
    public function testMissingAuthorityDelegateSelfEscalationCsrfAndConfirmationDeny(): void
    {
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $p = $this->input();
        self::assertWPError($panel->processBrowser($p));
        $a = ShopAccessFixture::authority(get_current_user_id(), 'digital', $p['caps']);
        $a['authority'] = 'DELEGATE';
        $a['source_approval_hash'] = $p['approval'];
        ShopAccessFixture::provision([$a]);
        self::assertWPError($panel->processBrowser($p));
        $a['authority'] = 'OWNER';
        ShopAccessFixture::provision([$a]);
        foreach ([['confirm' => ''],['_wpnonce' => 'bad'],['approval' => str_repeat('b', 64)],['shop' => 'jewelry'],['caps' => ['manage_digiforge_ai']],['subject_id' => '99'],['expected_revision' => '1']] as $change) {
            self::assertWPError($panel->processBrowser(array_replace($p, $change)));
        }
        self::assertWPError($panel->processBrowser($p, 'GET'));
        self::assertSame(0, (new DigiForge\Security\ShopAccessRepository())->read('digital')['revision']);
    }
    public function testWebsiteOperatorAssignmentUsesExistingRoleAndExactScope(): void
    {
        $p = $this->input();
        ShopAccessFixture::provision([ShopAccessFixture::authority(get_current_user_id(), 'digital', $p['caps'])]);
        $subject = self::factory()->user->create(['role'=>'administrator']);
        $p['operation']='GRANT'; $p['subject_id']=(string)$subject;
        $panel = new DigiForge\Portal\ShopAccessPanel();
        self::assertNotWPError($panel->processBrowser($p));
        wp_set_current_user($subject);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge_digital'));
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_digital'));
    }
    public function testSelectedShopNavigationAndGoogleProjectionDoNotRequireForeignShopGrants(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(), ['digital'], ['manage_digiforge','manage_digiforge_products','manage_digiforge_automation']);
        $before=$_GET;
        try {
            $_GET=['df_view'=>'businesses','df_shop'=>'digital'];
            $html=(new DigiForge\Portal\Portal())->render();
            self::assertStringContainsString('Stored business and provider ownership',$html);
            self::assertStringContainsString('df_shop=digital',$html);
            $_GET=['df_view'=>'automation','df_shop'=>'digital'];
            $html=(new DigiForge\Portal\Portal())->render();
            self::assertStringContainsString('Google document preparation',$html);
            self::assertStringNotContainsString('Global queue health',$html);
            $_GET=['df_view'=>'automation','df_shop'=>'personalized_pod'];
            self::assertStringNotContainsString('Google document preparation',(new DigiForge\Portal\Portal())->render());
        } finally { $_GET=$before; }
    }
    public function testAccessPageAndEmptyDashboardHaveNoBusinessDataAndNeverProvisionOnRead(): void
    {
        $get = $_GET;try {
            $_GET = ['df_view' => 'access'];
            $html = (new DigiForge\Portal\Portal())->render();
            self::assertStringContainsString('Shop access and operator assignments', $html);
            self::assertStringNotContainsString('<form', $html);
            $_GET = [];
            $html = (new DigiForge\Portal\Portal())->render();
            self::assertStringContainsString('Shop access and operator assignments', $html);
            self::assertStringNotContainsString('Recent orders', $html);
        } finally {
            $_GET = $get;
        }
        self::assertSame(0, (new DigiForge\Security\ShopAccessRepository())->read('digital')['revision']);
    }
}
