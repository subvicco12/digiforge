<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';

final class PrintifyFeatureOnlyTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if(class_exists(\DigiForge\Portal\PrintifyProductIntelligenceFeature::class))(new ReflectionProperty(\DigiForge\Portal\PrintifyProductIntelligenceFeature::class,'registered'))->setValue(null,false);
    }
    private function registrations(string $class): int
    {
        global $wp_filter;$count=0;
        foreach(($wp_filter['rest_api_init']->callbacks??[]) as $callbacks)foreach($callbacks as $row){
            $fn=$row['function'];if($fn instanceof Closure){$owner=(new ReflectionFunction($fn))->getClosureThis();if($owner instanceof $class)$count++;}
        }
        return $count;
    }
    private function catalogAdmissionRegistrations(): int
    {
        global $wp_filter;$count=0;
        foreach(($wp_filter['rest_pre_dispatch']->callbacks??[]) as $callbacks)foreach($callbacks as $row){
            $fn=$row['function'];if(is_array($fn)&&$fn[0] instanceof \DigiForge\REST\PrintifyCatalogShopAdmission)$count++;
        }
        return $count;
    }
    public function testFeatureStyleRequiresAssignedShopTwoContext(): void
    {
        PrintifyResearchAccessFixture::reset();$user=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($user);
        $caps=['manage_digiforge','manage_digiforge_pod'];foreach($caps as $cap)wp_get_current_user()->add_cap($cap);
        PrintifyResearchAccessFixture::assign($user,'personalized_pod',$caps);
        $feature=new \DigiForge\Portal\PrintifyProductIntelligenceFeature();self::assertTrue($feature->register());
        try{
            $_GET=['df_shop'=>'digital'];$feature->enqueue();self::assertFalse(wp_style_is('digiforge-printify-intelligence','enqueued'));
            $_GET=['df_shop'=>'personalized_pod'];$feature->enqueue();self::assertTrue(wp_style_is('digiforge-printify-intelligence','enqueued'));
            self::assertStringEndsWith('/assets/printify-product-intelligence.css',wp_styles()->registered['digiforge-printify-intelligence']->src);
        }finally{$_GET=[];wp_dequeue_style('digiforge-printify-intelligence');wp_deregister_style('digiforge-printify-intelligence');}
    }
    public function testFeatureAdapterReusesExistingCatalogRegistrationAndIsIdempotent(): void
    {
        self::assertTrue(class_exists(\DigiForge\Portal\PrintifyProductIntelligenceFeature::class),'Feature-only adapter must exist');
        $before=$this->registrations(\DigiForge\REST\PrintifyCatalogReadController::class);
        $admission=$this->catalogAdmissionRegistrations();
        $feature=new \DigiForge\Portal\PrintifyProductIntelligenceFeature();
        self::assertTrue($feature->register());
        self::assertSame($admission,$this->catalogAdmissionRegistrations(),'Feature must preserve existing shared catalog admission');
        self::assertSame($before,$this->registrations(\DigiForge\REST\PrintifyCatalogReadController::class));
        $research=$this->registrations(\DigiForge\REST\PrintifyResearchController::class);
        self::assertTrue((new \DigiForge\Portal\PrintifyProductIntelligenceFeature())->register());
        self::assertSame($research,$this->registrations(\DigiForge\REST\PrintifyResearchController::class));
        wp_set_current_user(0);$r=new WP_REST_Request('GET','/digiforge/v1/shops/personalized_pod/printify-research/readiness');
        self::assertWPError((new \DigiForge\REST\PrintifyResearchController())->permission($r));
        $GLOBALS['_GET']=['df_shop'=>'digital'];$feature->enqueue();self::assertFalse(wp_style_is('digiforge-printify-intelligence','enqueued'));
        ob_start();$feature->render('/portal/');$html=ob_get_clean();self::assertSame('',$html);
        $GLOBALS['_GET']=[];
    }
}
