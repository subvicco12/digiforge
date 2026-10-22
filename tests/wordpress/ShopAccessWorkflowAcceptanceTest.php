<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

final class ShopAccessWorkflowAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
    public function testActualAiSubmitBrowserAndFormDenyUnassignedAdministrator():void
    {
        $workflow=new DigiForge\Portal\AiEvidenceWorkflow();$input=['shop_key'=>'digital','operation'=>'tariff','document'=>'{}','decision'=>'CONFIRM_PROVIDER_TARIFF'];
        foreach([$workflow->submit($input),$workflow->processBrowser($input+['_wpnonce'=>wp_create_nonce($workflow::ACTION)])] as $result){self::assertWPError($result);self::assertStringStartsWith('digiforge_shop_access_',$result->get_error_code());}
        ob_start();$workflow->render('digital');$html=ob_get_clean();self::assertSame('',$html);
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_ai']);
        ob_start();$workflow->render('digital');$html=ob_get_clean();self::assertStringContainsString('Provider evidence review',$html);
        ob_start();$workflow->render('jewelry');self::assertSame('',ob_get_clean());
        $result=$workflow->submit($input);self::assertWPError($result);self::assertStringStartsNotWith('digiforge_shop_access_',$result->get_error_code());
    }
    public function testActualPodOperatorEntriesDenyBeforePrivateEvidenceButExplicitGrantRevealsLocalForm():void
    {
        $render=new DigiForge\POD\DeterministicRenderWorkflow();$design=new DigiForge\POD\PersonalizedDesignWorkflow();
        $privateReads=0;$tables=[DigiForge\Database\Tables::pod_production_templates(),DigiForge\Database\Tables::pod_render_evidence(),DigiForge\Database\Tables::personalization_submissions(),DigiForge\Database\Tables::orders()];
        $filter=static function(string $sql)use(&$privateReads,$tables):string{if(str_starts_with($sql,'SELECT'))foreach($tables as $table)if(str_contains($sql,$table)){$privateReads++;break;}return $sql;};add_filter('query',$filter);
        $recipe=['shop_key'=>'personalized_pod','decision'=>'CONFIRM_LOCAL_RENDER_RECIPE','recipe'=>[]];
        $input=['shop_key'=>'personalized_pod','decision'=>'PREPARE_LOCAL_ARTIFACTS','submission_id'=>1];
        foreach([$render->submit($recipe),$render->prepare($input),$render->preview(1,''),$render->processBrowser($recipe+['_wpnonce'=>wp_create_nonce($render::ACTION)]),$design->visualEvidence(['shop_key'=>'personalized_pod']),$design->qa(['shop_key'=>'personalized_pod','key'=>'unknown'])] as $result){self::assertWPError($result);self::assertStringStartsWith('digiforge_shop_access_',$result->get_error_code());}
        ob_start();$render->render('personalized_pod');self::assertSame('',ob_get_clean());
        remove_filter('query',$filter);self::assertSame(0,$privateReads);
        ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge_pod']);
        ob_start();$render->render('personalized_pod');$html=ob_get_clean();self::assertNotSame('',$html);
        $result=$render->prepare($input);self::assertWPError($result);self::assertStringStartsNotWith('digiforge_shop_access_',$result->get_error_code());
        // Customer preview retains its independent exact session/order check, never an operator assignment requirement.
        $customer=self::factory()->user->create(['role'=>'subscriber']);wp_set_current_user($customer);$result=$render->customerPreview(1,'','invalid');self::assertWPError($result);self::assertStringStartsNotWith('digiforge_shop_access_',$result->get_error_code());
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
    }
    public function testUnassignedPrivilegedActorCannotOverrideActualForeignCustomerSession():void
    {
        $operator=get_current_user_id();ShopAccessFixture::authorizeActor($operator,['personalized_pod'],['manage_digiforge_pod']);
        $customer=self::factory()->user->create(['role'=>'subscriber']);$foreign=self::factory()->user->create(['role'=>'administrator']);
        $orders=new DigiForge\Orders\Repository();$order=$orders->createOrder(['shop_reference'=>'personalized_pod','channel'=>'etsy','environment'=>'sandbox','buyer_reference'=>'synthetic-customer-'.wp_generate_uuid4()]);self::assertNotWPError($order);
        // Local synthetic stored line facts support delegation, never artwork/production certification.
        global $wpdb;$now=current_time('mysql',true);self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::order_line_items(),['order_id'=>$order['id'],'product_version_id'=>1,'provider_mapping_id'=>0,'quantity'=>1,'currency'=>'USD','environment'=>'sandbox','validation_status'=>'VALIDATED','created_at'=>$now,'updated_at'=>$now]));$line=(int)$wpdb->insert_id;
        $session='fixture-session-'.wp_generate_uuid4();$ref='digiforge/product-factory/pod-editor-evidence/shop-personalized_pod/'.wp_generate_uuid4().'.json';
        DigiForge\ProductFactory\AssetStorage::ensureProtectedRoot();$path=wp_upload_dir()['basedir'].'/'.$ref;wp_mkdir_p(dirname($path));
        file_put_contents($path,wp_json_encode(['schema'=>1,'shop_key'=>'personalized_pod','order_id'=>(int)$order['id'],'line_item_id'=>$line,'customer_user_id'=>$customer,'buyer_reference'=>$order['buyer_reference'],'local_personalization_access_authorized'=>true]));
        try{
            $repo=new DigiForge\POD\CustomerEditorRepository();$grant=$repo->grant(['shop_key'=>'personalized_pod','session_key'=>$session,'order_id'=>(int)$order['id'],'line_item_id'=>$line,'customer_user_id'=>$customer,'proof_reference'=>$ref,'proof_sha256'=>hash_file('sha256',$path)]);self::assertNotWPError($grant);
            wp_set_current_user($foreign);self::assertWPError($repo->access($session,true));self::assertWPError((new DigiForge\POD\DeterministicRenderWorkflow())->customerPreview(1,'',$session));
            wp_set_current_user($customer);$access=$repo->access($session);self::assertNotWPError($access);self::assertSame($customer,$access['customer_user_id']);
        }finally{@unlink($path);wp_set_current_user($operator);}
    }
}
