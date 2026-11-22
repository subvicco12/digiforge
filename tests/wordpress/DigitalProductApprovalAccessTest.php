<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

final class DigitalProductApprovalAccessTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();
        (new DigiForge\Portal\Portal())->register();
        (new DigiForge\Portal\U3ApprovalInbox())->register();
    }

    private function actor(bool $research = false): int
    {
        $id=self::factory()->user->create(['role'=>'subscriber']);
        $user=new WP_User($id);
        foreach(['manage_digiforge','manage_digiforge_digital','manage_digiforge_products'] as $cap)$user->add_cap($cap);
        if($research)$user->add_cap('manage_digiforge_research');
        wp_set_current_user($id);
        return $id;
    }

    private function render(string $shop = 'digital'): string
    {
        $get=$_GET;$calls=[];
        $http=static function($pre,$args,$url)use(&$calls){$calls[]=$url;return new WP_Error('offline_fixture','No outbound requests allowed.');};
        add_filter('pre_http_request',$http,10,3);
        try{$_GET=['df_view'=>'approvals','df_shop'=>$shop];$html=do_shortcode('[digiforge_admin_portal]');}
        finally{$_GET=$get;remove_filter('pre_http_request',$http,10);}
        self::assertSame([],$calls);
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
        return $html;
    }

    public function testProductOnlyActorCanOpenGateTwoWithoutResearchAccess(): void
    {
        $id=$this->actor();
        ShopAccessFixture::authorizeActor($id,['digital'],['manage_digiforge_products','manage_digiforge_digital']);
        $html=$this->render();
        self::assertStringContainsString('<h1>Approval Inbox</h1>',$html);
        self::assertStringContainsString('df-u3-product-approvals',$html);
        self::assertStringContainsString('aria-current="page"',$html);
        self::assertStringNotContainsString('Research approval inbox',$html);
        self::assertStringNotContainsString('digiforge_portal_review_research',$html);
    }

    public function testResearchCapabilityWithForeignAssignmentCannotExposeResearchPanel(): void
    {
        $id=$this->actor(true);
        ShopAccessFixture::authorizeActor($id,['digital'],['manage_digiforge_products']);
        ShopAccessFixture::authorizeActor($id,['jewelry'],['manage_digiforge_research']);
        $html=$this->render();
        self::assertStringContainsString('df-u3-product-approvals',$html);
        self::assertStringNotContainsString('Research approval inbox',$html);
        self::assertStringNotContainsString('digiforge_portal_review_research',$html);
        $foreign=$this->render('personalized_pod');
        self::assertStringNotContainsString('df-portal-shell',$foreign);
        self::assertStringNotContainsString('df-u3-product-approvals',$foreign);
    }

    public function testRevokedProductAssignmentCannotOpenGateTwo(): void
    {
        $id=$this->actor();
        $owner=ShopAccessFixture::authorizeActor($id,['digital'],['manage_digiforge_products']);
        $repo=new DigiForge\Security\ShopAccessRepository();$state=$repo->read('digital');
        self::assertNotWPError($state);
        $grants=array_filter($state['grants'],static fn(array $grant):bool=>(int)$grant['actor_id']===$id);
        self::assertNotEmpty($grants);
        wp_set_current_user($owner);
        self::assertNotWPError($repo->revoke('digital',(string)array_key_first($grants),'digital_product_approval_revoke',$state['revision']));
        wp_set_current_user($id);
        self::assertStringNotContainsString('df-u3-product-approvals',$this->render());
    }

    public function testSplitCapabilityAssignmentsCannotAuthorizeGlobalEvidence(): void
    {
        $id=$this->actor(true);
        ShopAccessFixture::authorizeActor($id,['digital','personalized_pod'],['manage_digiforge_products']);
        ShopAccessFixture::authorizeActor($id,['standard_pod','jewelry'],['manage_digiforge_research']);
        $html=$this->render();
        self::assertStringContainsString('df-u3-product-approvals',$html);
        self::assertStringContainsString('SHOP SCOPE',$html);
        self::assertStringNotContainsString('<option value="all"',$html);
        self::assertStringNotContainsString('Research approval inbox',$html);
        $global=$this->render('all');
        self::assertStringContainsString('An explicit assignment is required',$global);
        self::assertStringNotContainsString('df-portal-shell',$global);
        self::assertStringNotContainsString('df-u3-product-approvals',$global);
    }
}
