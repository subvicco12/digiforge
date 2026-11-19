<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ExistingRenderArtifactFixture.php';
require_once __DIR__.'/fixtures/NativeBusinessOrderFixture.php';
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Database\Tables;
use DigiForge\Orders\Repository as Orders;
use DigiForge\Orders\HeldFulfillmentPreparation;
use DigiForge\POD\{DeterministicPersonalizationRenderer,DeterministicRenderWorkflow,RenderEvidenceRepository,RenderArtifactVerifier,ProductionAuthorizationRepository};

/** Actual local artifacts and human-reviewed inputs; no provider execution or mutable approval repair. */
final class BuyerPersonalizationRevisionIntegrityTest extends WP_UnitTestCase
{
    use NativeBusinessOrderFixture;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();digiforge_reset_existing_render_fixture();
    }

    public function testDistinctSecondApprovalInvalidatesOldProductionReviewPackage():void
    {
        $input=digiforge_existing_render_fixture('deterministicFixture');
        $renderer=new DeterministicPersonalizationRenderer();
        self::assertNotWPError($renderer->approveRecipe($input['template_key'],1,digiforge_existing_render_fixture('recipe')));
        $expected=$renderer->expected($input);self::assertNotWPError($expected);
        $path=tempnam(sys_get_temp_dir(),'buyer-revision-art-');file_put_contents($path,$expected['svg']);
        $http=0;$deny=static function()use(&$http){$http++;return new WP_Error('offline_only');};add_filter('pre_http_request',$deny);
        try {
            $renders=new RenderEvidenceRepository();$render=$renders->createFromArtifacts($input,$path,$path);self::assertNotWPError($render);
            $render=$renders->approve((int)$render['id']);self::assertNotWPError($render);
            self::assertNotWPError((new RenderArtifactVerifier())->assertCertified($render));
            self::assertNotWPError((new DeterministicRenderWorkflow())->preview((int)$render['id'],'front'));
            $packages=new ProductionAuthorizationRepository();$package=$packages->create((int)$input['order_id'],(int)$render['id']);self::assertNotWPError($package);
            global $wpdb;
            $original=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE payload_hash=%s',$input['personalization_evidence_hash']),ARRAY_A);self::assertIsArray($original);
            $orders=new Orders();$second=$orders->createPersonalization(['order_line_item_id'=>$original['order_line_item_id'],'personalization_schema_id'=>$original['personalization_schema_id'],'payload'=>['name'=>'Ada']]);self::assertNotWPError($second);
            $second=$orders->reviewPersonalization((int)$second['id'],'APPROVED');self::assertNotWPError($second);self::assertNotSame($original['payload_hash'],$second['payload_hash']);
            // Package currentness is owned here; renderer/preview findings remain a separate Lane A receipt.
            $accepted=[
                'old_review_package_created'=>!is_wp_error($packages->create((int)$input['order_id'],(int)$render['id'])),
                'old_review_package_approved'=>!is_wp_error($packages->approveForReview((int)$package['id'])),
            ];
            $stored=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE id=%d',$original['id']),ARRAY_A);self::assertSame($original,$stored,'Old approval/input must remain immutable.');
            self::assertSame(0,$http);
            self::assertSame(array_fill_keys(array_keys($accepted),false),$accepted,'Two distinct approved inputs on one line require an explicit current approved selection; neither old approval nor matching hash establishes currentness.');
        } finally {remove_filter('pre_http_request',$deny);unlink($path);}
    }

    public function testAmbiguousInputsBlockFreshPlanAndCannotRecoverByOverwritingHistory():void
    {
        $this->bootOrderFixture();
        try {
            [, $mapping,$version]=$this->product();$orders=new Orders();
            $order=PrintifyApiApprovalFixture::checked($orders->createOrder(['channel'=>'etsy','environment'=>'test','shop_reference'=>'personalized_pod','personalization_required'=>true]));
            $line=PrintifyApiApprovalFixture::checked($orders->addLineItem(['order_id'=>$order['id'],'product_version_id'=>$version,'provider_mapping_id'=>$mapping]));
            $pod=new DigiForge\POD\Repository();$schema=PrintifyApiApprovalFixture::checked($pod->createPersonalizationSchema(['product_version_id'=>$version,'schema_key'=>'buyer-revision','version_label'=>'v1','field_definitions'=>[['key'=>'name','type'=>'text','required'=>true]]]));
            foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)PrintifyApiApprovalFixture::checked($pod->transition('personalization',(int)$schema['id'],$state));
            $first=PrintifyApiApprovalFixture::checked($orders->createPersonalization(['order_line_item_id'=>$line['id'],'personalization_schema_id'=>$schema['id'],'payload'=>['name'=>'First']]));
            $first=PrintifyApiApprovalFixture::checked($orders->reviewPersonalization((int)$first['id'],'APPROVED'));
            foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)PrintifyApiApprovalFixture::checked($orders->transition('order',(int)$order['id'],$state));
            $makeIntent=function()use($orders,$order):int {
                $plan=PrintifyApiApprovalFixture::checked($orders->createPlan(['order_id'=>$order['id'],'plan_version'=>wp_generate_uuid4(),'provider'=>'printify','shipping_method_metadata'=>['method'=>'standard'],'cost_snapshot_metadata'=>['base_production_cost'=>10,'shipping_estimate'=>4]]));
                foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)PrintifyApiApprovalFixture::checked($orders->transition('plan',(int)$plan['id'],$state));
                $intent=PrintifyApiApprovalFixture::checked($orders->createIntent(['order_id'=>$order['id'],'fulfillment_plan_id'=>$plan['id'],'intent_type'=>'PREPARE_FULFILLMENT']));return (int)$intent['id'];
            };
            $held=new HeldFulfillmentPreparation();self::assertNotWPError($held->prepare('personalized_pod',(int)$order['id'],$makeIntent()));
            $second=PrintifyApiApprovalFixture::checked($orders->createPersonalization(['order_line_item_id'=>$line['id'],'personalization_schema_id'=>$schema['id'],'payload'=>['name'=>'Corrected']]));
            PrintifyApiApprovalFixture::checked($orders->reviewPersonalization((int)$second['id'],'APPROVED'));
            $fresh=$orders->createPlan(['order_id'=>$order['id'],'plan_version'=>wp_generate_uuid4(),'provider'=>'printify','shipping_method_metadata'=>['method'=>'standard'],'cost_snapshot_metadata'=>['base_production_cost'=>10,'shipping_estimate'=>4]]);
            self::assertWPError($fresh);self::assertSame('not_ready',$fresh->get_error_code(),'Ambiguous approved buyer inputs must block a fresh plan until explicit correction recovery resolves currentness.');
            $overwrite=$orders->reviewPersonalization((int)$first['id'],'REJECTED');self::assertWPError($overwrite);self::assertSame('invalid_transition',$overwrite->get_error_code());
            global $wpdb;self::assertSame('APPROVED',$wpdb->get_var($wpdb->prepare('SELECT review_status FROM '.Tables::personalization_submissions().' WHERE id=%d',$first['id'])));
        } finally {$this->restoreOrderFixture();}
    }
}
