<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Research\CommercialResearchRepository;
use DigiForge\Research\OpportunityDesignRepository;
use DigiForge\Research\DesignFactoryRepository;
use DigiForge\POD\ProductPackageRepository;
use DigiForge\Security\ShopAccessAdminGuard;

/** Existing independent shop grants must govern the newly merged held workflow. */
final class Schema25MvpAccessTest extends WP_UnitTestCase
{
    private int $actor;
    private const CAPS=['manage_digiforge_research','manage_digiforge_pod'];
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();$this->actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($this->actor);
    }
    private function research(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true)['research'];
    }
    public function testGlobalAdministratorCannotCreateWorkflowWithoutExplicitShopGrant(): void
    {
        $v=(new CommercialResearchRepository())->create($this->research());self::assertWPError($v);self::assertSame(403,$v->get_error_data()['status']);
        foreach([(new OpportunityDesignRepository())->read('personalized_pod',str_repeat('a',64)),(new DesignFactoryRepository())->read('personalized_pod',str_repeat('a',64)),(new ProductPackageRepository())->read('personalized_pod',str_repeat('a',64))] as $result){
            self::assertWPError($result);self::assertSame(403,$result->get_error_data()['status']);
        }
    }
    public function testWrongShopAndIncompleteGrantsCannotCreateWorkflow(): void
    {
        ShopAccessFixture::authorizeActor($this->actor,['digital'],self::CAPS);
        self::assertWPError((new CommercialResearchRepository())->create($this->research()));
        ShopAccessFixture::authorizeActor($this->actor,['personalized_pod'],['manage_digiforge_research']);
        self::assertWPError((new CommercialResearchRepository())->create($this->research()));
    }
    public function testSignedGrantAllowsWorkflowAndAuthorityRevocationDeniesSavedVersion(): void
    {
        ShopAccessFixture::authorizeActor($this->actor,['personalized_pod'],self::CAPS);
        $repo=new CommercialResearchRepository();$v=$repo->create($this->research());self::assertIsArray($v);
        ShopAccessFixture::provision([]);self::assertWPError($repo->read('personalized_pod',$v['hash']));
        self::assertFalse(DigiForge\Research\GovernedResearchProvider::executionAllowed());
        self::assertFalse(DesignFactoryRepository::executionAllowed());
    }
    public function testActualAdminPostAdmissionAcceptsOnlyGrantedMvpActions(): void
    {
        $guard=new ShopAccessAdminGuard();$die=static fn()=>static function():never{throw new RuntimeException('denied');};add_filter('wp_die_handler',$die);
        try {
            foreach(['digiforge_opportunity_design','digiforge_commercial_research'] as $action){
                $denied=false;try{$guard->action('admin_post_'.$action);}catch(RuntimeException){$denied=true;}self::assertTrue($denied);
            }
            ShopAccessFixture::authorizeActor($this->actor,['personalized_pod'],self::CAPS);
            foreach(['digiforge_opportunity_design','digiforge_commercial_research'] as $action)$guard->action('admin_post_'.$action);
            $denied=false;try{$guard->action('admin_post_digiforge_unknown_mvp_action');}catch(RuntimeException){$denied=true;}self::assertTrue($denied);
            ShopAccessFixture::provision([]);$denied=false;try{$guard->action('admin_post_digiforge_commercial_research');}catch(RuntimeException){$denied=true;}self::assertTrue($denied);
        } finally {remove_filter('wp_die_handler',$die);}
    }
    public function testRegisteredAdminPostHookAdmitsGrantedHandlerAndRejectsRevocation(): void
    {
        $hook='admin_post_digiforge_commercial_research';$old=$GLOBALS['wp_filter'][$hook]??null;
        $GLOBALS['wp_filter'][$hook]=new WP_Hook();
        $sentinel=static function():never{throw new RuntimeException('handler-admitted');};add_action($hook,$sentinel,-100);
        $die=static fn()=>static function():never{throw new RuntimeException('guard-denied');};add_filter('wp_die_handler',$die);
        try {
            ShopAccessFixture::authorizeActor($this->actor,['personalized_pod'],self::CAPS);
            try{do_action($hook);self::fail('Expected isolated handler sentinel');}catch(RuntimeException $e){self::assertSame('handler-admitted',$e->getMessage());}
            ShopAccessFixture::provision([]);
            try{do_action($hook);self::fail('Revoked actor reached handler');}catch(RuntimeException $e){self::assertSame('guard-denied',$e->getMessage());}
        } finally {remove_filter('wp_die_handler',$die);if($old===null)unset($GLOBALS['wp_filter'][$hook]);else $GLOBALS['wp_filter'][$hook]=$old;}
    }
    public function testSchema25InitializationPreservesExistingRowsAndMarkers(): void
    {
        global $wpdb;
        $event=DigiForge\Database\Tables::etsy_order_event_evidence();$provider=DigiForge\Database\ProviderConnectionSchema::table();
        $before=[(int)$wpdb->get_var("SELECT COUNT(*) FROM $event"),(int)$wpdb->get_var("SELECT COUNT(*) FROM $provider")];
        self::assertTrue(DigiForge\Database\EtsyOrderEventSchema::migrateIfNeeded());self::assertTrue(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());
        self::assertSame(25,(int)get_option('digiforge_db_schema_version'));self::assertSame('1',get_option('digiforge_provider_connection_schema'));
        self::assertSame($before,[(int)$wpdb->get_var("SELECT COUNT(*) FROM $event"),(int)$wpdb->get_var("SELECT COUNT(*) FROM $provider")]);
    }
}
