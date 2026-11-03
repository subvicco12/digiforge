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
    public static function ownerShops(): array
    {
        return [['digital'], ['personalized_pod'], ['standard_pod'], ['jewelry']];
    }

    /** @dataProvider ownerShops */
    public function testFullSignedOwnerEnrollmentIncludesAllCapabilitiesWithoutOperationalActivation(string $shop): void
    {
        $actor = get_current_user_id();
        $caps = DigiForge\Core\Capabilities::ALL;
        self::assertCount(15, $caps);
        $authority = ShopAccessFixture::authority($actor, $shop, $caps);
        ShopAccessFixture::provision([$authority]);
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $p = array_replace($this->input(), ['shop' => $shop, 'caps' => $caps, 'approval' => $authority['source_approval_hash']]);
        $externalCalls = 0;
        $blockHttp = static function ($preempt) use (&$externalCalls) {
            $externalCalls++;
            return new WP_Error('synthetic_external_request_denied');
        };
        add_filter('pre_http_request', $blockHttp);
        try {
            $result = $panel->processBrowser($p);
            self::assertNotWPError($result);
            self::assertFalse($result['external_execution_authorized']);
            foreach ($caps as $cap) {
                self::assertTrue(DigiForge\Security\ShopAccess::authorize($shop, $cap), $cap);
            }
            $repo = new DigiForge\Security\ShopAccessRepository();
            $state = $repo->read($shop);
            self::assertNotWPError($state);
            self::assertSame(1, $state['revision']);
            self::assertSame($caps, $state['grants'][$result['grant_id']]['caps']);
            self::assertTrue($repo->verifyGrantEvidence($result['grant_id'], $state['grants'][$result['grant_id']], 1));
            self::assertWPError($panel->processBrowser($p));
            self::assertWPError($panel->processBrowser(array_replace($p, ['expected_revision' => '1'])));
            foreach (array_diff(DigiForge\Security\ShopAccessPolicy::canonicalShops(), [$shop]) as $foreign) {
                self::assertWPError(DigiForge\Security\ShopAccess::authorize($foreign, 'manage_digiforge_finance'));
            }
            self::assertSame(1, $repo->read($shop)['revision']);
            self::assertTrue(DigiForge\Core\Settings::get('stop_all', true));
            self::assertTrue(DigiForge\Core\Settings::safety_locked());
            self::assertFalse(DigiForge\Core\Settings::get('automation_armed', false));
            self::assertSame(0, $externalCalls);
        } finally {
            remove_filter('pre_http_request', $blockHttp);
        }
    }

    public function testFinanceCheckboxAppearsOnlyInSignedOwnerEnrollment(): void
    {
        $authority = ShopAccessFixture::authority(get_current_user_id(), 'digital', DigiForge\Core\Capabilities::ALL);
        ShopAccessFixture::provision([$authority]);
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $document = new DOMDocument();
        @$document->loadHTML($panel->render());
        $xpath = new DOMXPath($document);
        $finance = '//input[@name="caps[]" and @value="manage_digiforge_finance"]';
        self::assertSame(1, $xpath->query('//form[.//input[@name="operation" and @value="ENROLL"]]' . $finance)->length);
        self::assertSame(0, $xpath->query('//form[.//input[@name="operation" and @value="GRANT"]]' . $finance)->length);
        $authority['authority'] = 'DELEGATE';
        ShopAccessFixture::provision([$authority]);
        @$document->loadHTML($panel->render());
        self::assertSame(0, (new DOMXPath($document))->query($finance)->length);
    }

    public function testUnsignedDelegateAndOperatorFinanceSubmissionsCannotUseOwnerEnrollment(): void
    {
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $p = array_replace($this->input(), ['caps' => ['manage_digiforge_finance']]);
        self::assertWPError($panel->processBrowser($p));
        $authority = ShopAccessFixture::authority(get_current_user_id(), 'digital', $p['caps'], 0, 'DELEGATE');
        ShopAccessFixture::provision([$authority]);
        self::assertWPError($panel->processBrowser($p));
        $authority['authority'] = 'OWNER';
        ShopAccessFixture::provision([$authority]);
        $subject = self::factory()->user->create(['role' => 'administrator']);
        self::assertWPError($panel->processBrowser(array_replace($p, ['operation' => 'GRANT', 'subject_id' => (string)$subject])));
        wp_set_current_user($subject);
        $p['_wpnonce'] = wp_create_nonce($panel::ACTION);
        self::assertWPError($panel->processBrowser($p));
        self::assertSame(0, (new DigiForge\Security\ShopAccessRepository())->read('digital')['revision']);
    }

    public function testFinanceEnrollmentStillRequiresNonceConfirmationApprovalRoleScopeAndCurrentRevision(): void
    {
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $p = array_replace($this->input(), ['caps' => ['manage_digiforge_finance']]);
        ShopAccessFixture::provision([ShopAccessFixture::authority(get_current_user_id(), 'digital', $p['caps'])]);
        foreach ([
            ['_wpnonce' => 'invalid'], ['confirm' => ''], ['approval' => str_repeat('b', 64)],
            ['role' => 'subscriber'], ['caps' => ['manage_digiforge_finance', 'manage_options']],
            ['subject_id' => '1'], ['expected_revision' => '1'], ['expires_at' => (string)(time() + 7200)],
        ] as $change) {
            self::assertWPError($panel->processBrowser(array_replace($p, $change)));
        }
        self::assertWPError($panel->processBrowser($p, 'GET'));
        $authority = ShopAccessFixture::authority(get_current_user_id(), 'digital', ['manage_digiforge']);
        ShopAccessFixture::provision([$authority]);
        self::assertWPError($panel->processBrowser($p));
        self::assertSame(0, (new DigiForge\Security\ShopAccessRepository())->read('digital')['revision']);
    }

    public function testRevokedAndExpiredOwnerAuthorityInvalidateFinanceAccessAndFurtherEnrollment(): void
    {
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $p = array_replace($this->input(), ['caps' => ['manage_digiforge_finance']]);
        $authority = ShopAccessFixture::authority(get_current_user_id(), 'digital', $p['caps']);
        ShopAccessFixture::provision([$authority]);
        self::assertNotWPError($panel->processBrowser($p));
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital', 'manage_digiforge_finance'));
        $next = array_replace($p, ['request_id' => 'owner_finance_request_2', 'expected_revision' => '1']);
        ShopAccessFixture::provision([]);
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital', 'manage_digiforge_finance'));
        self::assertWPError($panel->processBrowser($next));
        $authority['expires_at'] = time() - 1;
        ShopAccessFixture::provision([$authority]);
        self::assertWPError(DigiForge\Security\ShopAccessAuthority::records());
        self::assertWPError($panel->processBrowser($next));
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital', 'manage_digiforge_finance'));
        self::assertSame(1, (new DigiForge\Security\ShopAccessRepository())->read('digital')['revision']);
    }

    public function testFinanceEnrollmentCannotReplaceMissingOrdinaryCapabilityOrDurableAudit(): void
    {
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $p = array_replace($this->input(), ['caps' => ['manage_digiforge_finance']]);
        ShopAccessFixture::provision([ShopAccessFixture::authority(get_current_user_id(), 'digital', $p['caps'])]);
        wp_get_current_user()->add_cap('manage_digiforge_finance', false);
        self::assertWPError($panel->processBrowser($p));
        wp_get_current_user()->remove_cap('manage_digiforge_finance');
        global $wpdb;
        $table = DigiForge\Database\Tables::audit_log();
        $quotedTable = chr(96) . $table . chr(96);
        $failAudit = static function (string $sql) use ($quotedTable): string {
            return str_starts_with($sql, 'INSERT INTO ' . $quotedTable)
                ? str_replace($quotedTable, chr(96) . 'missing_owner_finance_audit' . chr(96), $sql) : $sql;
        };
        $prior = $wpdb->suppress_errors(true);
        add_filter('query', $failAudit);
        try {
            self::assertWPError($panel->processBrowser($p));
        } finally {
            remove_filter('query', $failAudit);
            $wpdb->suppress_errors($prior);
        }
        self::assertSame(0, (new DigiForge\Security\ShopAccessRepository())->read('digital')['revision']);
    }

    public function testTamperedSignedOwnerManifestCannotEnrollFinance(): void
    {
        $panel = new DigiForge\Portal\ShopAccessPanel();
        $p = array_replace($this->input(), ['caps' => ['manage_digiforge_finance']]);
        $authority = ShopAccessFixture::authority(get_current_user_id(), 'digital', $p['caps']);
        ShopAccessFixture::provision([$authority]);
        $path = constant('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE');
        $envelope = json_decode((string)file_get_contents($path), true);
        $envelope['manifest'] .= ' ';
        self::assertNotFalse(file_put_contents($path, wp_json_encode($envelope), LOCK_EX));
        try {
            $denied = DigiForge\Security\ShopAccessAuthority::records();
            self::assertWPError($denied);
            self::assertSame('digiforge_shop_authority_signature', $denied->get_error_code());
            self::assertWPError($panel->processBrowser($p));
            self::assertSame(0, (new DigiForge\Security\ShopAccessRepository())->read('digital')['revision']);
        } finally {
            ShopAccessFixture::provision([$authority]);
        }
    }

    public function testAccessPageAndEmptyDashboardHaveNoBusinessDataAndNeverProvisionOnRead(): void
    {
        $get = $_GET;try {
            $_GET = ['df_view' => 'access'];
            $html = (new DigiForge\Portal\Portal())->render();
            self::assertStringContainsString('Shop access and operator assignments', $html);
            self::assertStringNotContainsString('value="digiforge_portal_access"', $html);
            self::assertStringContainsString('Return to Operations Console', $html);
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
