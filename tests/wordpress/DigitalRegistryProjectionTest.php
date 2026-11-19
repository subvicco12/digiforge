<?php
declare(strict_types=1);

/** Registry workflow labels never substitute for independent Product Approval. */
final class DigitalRegistryProjectionTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
    }

    public function testAdvancedRegistryStatesAreCountedWithoutClaimingReleaseApproval(): void
    {
        global $wpdb;
        $table=DigiForge\Database\Tables::digital_products();
        // Disposable records exercise the projection, not approval issuance.
        foreach(['HUMAN_APPROVED','PLATFORM_DRAFT','FINAL_VALIDATION','PUBLISH_READY'] as $index=>$state){
            self::assertSame(1,$wpdb->insert($table,['name'=>'Synthetic registry '.$state,'category'=>'test','product_id'=>1,'product_version_id'=>$index+1,'state'=>$state,'readiness'=>wp_json_encode(DigiForge\DigitalFactory\Lifecycle::readiness()),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)]));
        }
        $html=$this->project();
        self::assertStringContainsString('<span>Registry post-review states</span><b>4</b>',$html);
        self::assertStringNotContainsString('Approved / release-ready',$html);
        self::assertStringContainsString('Registry status is not Product Approval or release evidence.',$html);
        self::assertStringContainsString('<span>External publish authority</span><b>NO</b>',$html);
    }

    public function testRegistryFlagsCannotDisplayTechnicalOrCommercialPass(): void
    {
        global $wpdb;
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::digital_products(),['name'=>'Synthetic flags only','category'=>'test','product_id'=>1,'product_version_id'=>1,'state'=>'PUBLISH_READY','readiness'=>wp_json_encode(array_fill_keys(array_keys(DigiForge\DigitalFactory\Lifecycle::readiness()),true)),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)]));
        $html=$this->project();
        self::assertStringContainsString('REGISTRY COMPLETE — UNVERIFIED',$html);
        self::assertStringNotContainsString('<td>PASS</td>',$html);
    }

    /** @dataProvider invalidReadiness */
    public function testIncompleteOrMalformedRegistryFlagsRemainUnverified(array $readiness): void
    {
        global $wpdb;
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::digital_products(),['name'=>'Synthetic invalid readiness','category'=>'test','product_id'=>1,'product_version_id'=>1,'state'=>'PUBLISH_READY','readiness'=>wp_json_encode($readiness),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)]));
        $html=$this->project();
        self::assertStringContainsString('<td>UNVERIFIED</td>',$html);
        self::assertStringNotContainsString('REGISTRY COMPLETE',$html);
    }

    public static function invalidReadiness(): array
    {
        return [['empty'=>[]],['partial'=>['files'=>true]],['unknown'=>['invented_gate'=>true]],['untyped'=>array_fill_keys(['files','technical_qa','content_qa','visual_qa','commercial_qa','copyright_ip_qa','policy_qa','profitability_qa','listing','human_approval','platform_draft','final_validation'],'true')]];
    }

    private function project(): string
    {
        $get=$_GET;
        try{
            $_GET=['df_shop'=>'all'];
            $method=new ReflectionMethod(DigiForge\Portal\Portal::class,'digitalFactoryOperations');
            ob_start();
            try{$method->invoke(new DigiForge\Portal\Portal());return (string)ob_get_contents();}
            finally{ob_end_clean();}
        }finally{$_GET=$get;}
    }
}
