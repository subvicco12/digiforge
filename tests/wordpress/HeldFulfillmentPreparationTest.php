<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/NativeBusinessOrderFixture.php';

use DigiForge\Database\Tables;
use DigiForge\Orders\HeldFulfillmentPreparation;
use DigiForge\Orders\Repository;

final class HeldFulfillmentPreparationTest extends WP_UnitTestCase
{
    use NativeBusinessOrderFixture;

    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
        $this->bootOrderFixture();
    }

    public function tearDown(): void
    {
        try {$this->restoreOrderFixture();} finally {parent::tearDown();}
    }

    public function testStoredOrderCanBePreparedLocallyWithoutWritesOrExternalExecution(): void
    {
        self::assertTrue(class_exists(HeldFulfillmentPreparation::class),'A stored-evidence held preparation path is required.');
        [$order, $intent] = $this->heldFixture();
        global $wpdb;
        $queries=[];$http=0;
        $record=static function(string $sql)use(&$queries):string {$queries[]=$sql;return $sql;};
        $deny=static function()use(&$http){$http++;return new WP_Error('unexpected_http');};
        add_filter('query',$record);add_filter('pre_http_request',$deny);
        try {$result=(new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent);}
        finally {remove_filter('query',$record);remove_filter('pre_http_request',$deny);}
        self::assertNotWPError($result);
        self::assertSame('HELD_FULFILLMENT_PREPARED',$result['state']);
        self::assertTrue($result['release_hold']);
        self::assertFalse($result['external_execution_authorized']);
        self::assertFalse($result['preparation']['adapter_invoked']);
        self::assertSame('printify',$result['preparation']['route']['provider']);
        self::assertSame($order,$result['order_id']);
        self::assertSame(0,$http);
        foreach($queries as $query)self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i',$query);
        $stored=$wpdb->get_row($wpdb->prepare('SELECT state FROM '.Tables::fulfillment_intents().' WHERE id=%d',$intent),ARRAY_A);
        self::assertSame('BLOCKED',$stored['state']);
    }

    public function testStoredIdentityApprovalAndEvidenceChangesFailClosed(): void
    {
        [$order,$intent,$plan,$line]=$this->heldFixture();global $wpdb;
        $service=new HeldFulfillmentPreparation();
        self::assertWPError($service->prepare('digital',$order,$intent));
        self::assertWPError($service->prepare('all',$order,$intent));
        foreach([
            [Tables::orders(),$order,'state','ON_HOLD'],
            [Tables::fulfillment_plans(),$plan,'canonical_payload','{}'],
            [Tables::fulfillment_plans(),$plan,'approved_by',0],
            [Tables::fulfillment_intents(),$intent,'state','APPROVED_INTENT'],
            [Tables::fulfillment_intents(),$intent,'fulfillment_plan_payload_hash',str_repeat('a',64)],
            [Tables::order_line_items(),$line,'provider_mapping_id',0],
        ] as [$table,$id,$field,$bad]){
            $old=$wpdb->get_var($wpdb->prepare("SELECT $field FROM $table WHERE id=%d",$id));
            self::assertSame(1,$wpdb->update($table,[$field=>$bad],['id'=>$id]));
            try {self::assertWPError($service->prepare('personalized_pod',$order,$intent),$field);}
            finally {$wpdb->update($table,[$field=>$old],['id'=>$id]);}
        }
        wp_set_current_user(0);
        self::assertWPError($service->prepare('personalized_pod',$order,$intent));
    }

    public function testUnavailableEvidenceAndForgedGetScopeDoNotRenderOrderDetails(): void
    {
        [$order,$intent]=$this->heldFixture();
        $filter=static fn(string $sql):string=>str_contains($sql,'FROM '.Tables::fulfillment_intents())?'SELECT * FROM held_prep_missing_evidence':$sql;
        add_filter('query',$filter);global $wpdb;$previous=$wpdb->suppress_errors(true);
        try {self::assertWPError((new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent));}
        finally {remove_filter('query',$filter);$wpdb->suppress_errors($previous);}
        $_GET['df_order_id']=(string)$order;$_GET['df_intent_id']=(string)$intent;
        ob_start();(new \DigiForge\Portal\OrderHeldPreparationPanel())->render('digital');$html=(string)ob_get_clean();
        self::assertStringNotContainsString('held-fixture-order',$html);
        self::assertStringNotContainsString('HELD_FULFILLMENT_PREPARED',$html);
        unset($_GET['df_order_id'],$_GET['df_intent_id']);
    }

    public function testApprovalCannotBeReusedAfterQuantityOrProviderMaterialDrifts(): void
    {
        [$order,$intent,,$line]=$this->heldFixture();global $wpdb;$service=new HeldFulfillmentPreparation();
        $mapping=(int)$wpdb->get_var($wpdb->prepare('SELECT provider_mapping_id FROM '.Tables::order_line_items().' WHERE id=%d',$line));
        foreach([[Tables::order_line_items(),$line,'quantity',3],[Tables::pod_mappings(),$mapping,'provider_product_key','different-product'],[Tables::pod_mappings(),$mapping,'mapping_version','different-version']] as [$table,$id,$field,$value]){
            $old=$wpdb->get_var($wpdb->prepare("SELECT $field FROM $table WHERE id=%d",$id));
            self::assertSame(1,$wpdb->update($table,[$field=>$value],['id'=>$id]));
            try {self::assertWPError($service->prepare('personalized_pod',$order,$intent),$field);}
            finally {$wpdb->update($table,[$field=>$old],['id'=>$id]);}
        }
    }

    public function testLegacyPlanWithoutFrozenLinesRequiresFreshReview(): void
    {
        [$order,$intent,$plan]=$this->heldFixture();global $wpdb;
        $json=$wpdb->get_var($wpdb->prepare('SELECT canonical_payload FROM '.Tables::fulfillment_plans().' WHERE id=%d',$plan));
        $payload=json_decode($json,true);unset($payload['line_identity_snapshot']);$json=\DigiForge\Orders\Validator::canonicalJson($payload);$hash=hash('sha256',$json);
        self::assertSame(1,$wpdb->update(Tables::fulfillment_plans(),['canonical_payload'=>$json,'payload_hash'=>$hash],['id'=>$plan]));
        self::assertSame(1,$wpdb->update(Tables::fulfillment_intents(),['fulfillment_plan_payload_hash'=>$hash],['id'=>$intent]));
        $result=(new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent);
        self::assertWPError($result);self::assertSame('held_fulfillment_line_snapshot',$result->get_error_code());
    }

    public function testActiveForeignOwnershipCannotUseAnotherReviewedShopConnection(): void
    {
        [$order,$intent,,$line]=$this->heldFixture();global $wpdb;$now=current_time('mysql',true);$times=['created_at'=>$now,'updated_at'=>$now];
        self::assertSame(1,$wpdb->insert(Tables::businesses(),$times+['business_key'=>'held-other-'.wp_generate_uuid4(),'status'=>'ACTIVE']));$business=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::stores(),$times+['business_id'=>$business,'store_key'=>'held-other-store','status'=>'ACTIVE']));$store=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::product_programs(),$times+['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE']));$program=(int)$wpdb->insert_id;
        $mapping=(int)$wpdb->get_var($wpdb->prepare('SELECT provider_mapping_id FROM '.Tables::order_line_items().' WHERE id=%d',$line));
        self::assertSame(1,$wpdb->update(Tables::pod_business_mappings(),['business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program],['provider_mapping_id'=>$mapping]));
        $result=(new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent);
        self::assertWPError($result);self::assertSame('held_fulfillment_ownership_scope',$result->get_error_code());
    }

    public function testOwnershipRevocationDuringReadCannotReturnPreparedEvidence(): void
    {
        [$order,$intent,,$line]=$this->heldFixture();global $wpdb;$reads=0;$mutated=false;
        $mapping=(int)$wpdb->get_var($wpdb->prepare('SELECT provider_mapping_id FROM '.Tables::order_line_items().' WHERE id=%d',$line));
        $filter=static function(string $sql)use(&$reads,&$mutated,$mapping,$wpdb):string{
            if(str_starts_with($sql,'SELECT o.id,o.channel')&&++$reads===2){$mutated=true;$wpdb->update(Tables::pod_business_mappings(),['state'=>'REVOKED'],['provider_mapping_id'=>$mapping]);}
            return $sql;
        };
        add_filter('query',$filter);
        try {$result=(new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent);}
        finally {remove_filter('query',$filter);}
        self::assertTrue($mutated);self::assertWPError($result);
    }

    public function testOrdersModuleDenialPreventsDirectAndGetInspectionDespiteWordPressCaps(): void
    {
        [$order,$intent]=$this->heldFixture();
        PrintifyApiApprovalFixture::checked(\DigiForge\PortalAccess\Store::change($this->fixture->reviewer,'Viewer',['personalized_pod'=>['research']],true,0,wp_generate_uuid4()));
        PortalAccessFixture::login($this->fixture->reviewer);self::assertTrue(current_user_can('manage_digiforge_orders'));
        self::assertWPError((new HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intent));
        $_GET['df_order_id']=(string)$order;$_GET['df_intent_id']=(string)$intent;
        ob_start();(new \DigiForge\Portal\OrderHeldPreparationPanel())->render('personalized_pod');$html=(string)ob_get_clean();
        unset($_GET['df_order_id'],$_GET['df_intent_id']);
        self::assertStringNotContainsString('held-fixture-order',$html);self::assertStringNotContainsString('HELD_FULFILLMENT_PREPARED',$html);
    }

    public function testOnlyExactStoredEtsyChannelMayBeInspectedOrPrepared(): void
    {
        [$order,$intent]=$this->heldFixture();global $wpdb;$service=new HeldFulfillmentPreparation();$get=$_GET;
        try {
            foreach(['shopify','manual','ETSY'] as $channel){
                self::assertSame(1,$wpdb->update(Tables::orders(),['channel'=>$channel],['id'=>$order]));
                foreach([$service->forOrder('personalized_pod',$order),$service->prepare('personalized_pod',$order,$intent)] as $result){self::assertWPError($result);self::assertSame('held_fulfillment_order_scope',$result->get_error_code());}
                $_GET['df_order_id']=(string)$order;$_GET['df_intent_id']=(string)$intent;
                ob_start();(new \DigiForge\Portal\OrderHeldPreparationPanel())->render('personalized_pod');$html=(string)ob_get_clean();
                self::assertStringNotContainsString('held-fixture-order',$html);self::assertStringNotContainsString('HELD_FULFILLMENT_PREPARED',$html);
            }
        } finally {$wpdb->update(Tables::orders(),['channel'=>'etsy'],['id'=>$order]);$_GET=$get;}
    }

    private function heldFixture(): array
    {
        [, $mapping,$version]=$this->product();$repo=new Repository();
        $order=PrintifyApiApprovalFixture::checked($repo->createOrder(['channel'=>'etsy','shop_reference'=>'personalized_pod','environment'=>'test','external_order_reference'=>'held-fixture-order','personalization_required'=>true,'currency'=>'USD']));
        $line=PrintifyApiApprovalFixture::checked($repo->addLineItem(['order_id'=>$order['id'],'product_version_id'=>$version,'provider_mapping_id'=>$mapping,'quantity'=>2]));
        $schema=PrintifyApiApprovalFixture::checked((new \DigiForge\POD\Repository())->createPersonalizationSchema(['product_version_id'=>$version,'schema_key'=>'held-test','version_label'=>'v1','field_definitions'=>[['key'=>'name','type'=>'text','required'=>true]]]));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked((new \DigiForge\POD\Repository())->transition('personalization',(int)$schema['id'],$state));
        $submission=PrintifyApiApprovalFixture::checked($repo->createPersonalization(['order_line_item_id'=>$line['id'],'personalization_schema_id'=>$schema['id'],'payload'=>['name'=>'Synthetic buyer']]));
        PrintifyApiApprovalFixture::checked($repo->reviewPersonalization((int)$submission['id'],'APPROVED'));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($repo->transition('order',(int)$order['id'],$state));
        $plan=PrintifyApiApprovalFixture::checked($repo->createPlan(['order_id'=>$order['id'],'provider'=>'printify','shipping_method_metadata'=>['method'=>'standard'],'cost_snapshot_metadata'=>['base_production_cost'=>10,'shipping_estimate'=>4]]));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($repo->transition('plan',(int)$plan['id'],$state));
        $intent=PrintifyApiApprovalFixture::checked($repo->createIntent(['order_id'=>$order['id'],'fulfillment_plan_id'=>$plan['id'],'intent_type'=>'PREPARE_FULFILLMENT']));
        return [(int)$order['id'],(int)$intent['id'],(int)$plan['id'],(int)$line['id']];
    }
}
