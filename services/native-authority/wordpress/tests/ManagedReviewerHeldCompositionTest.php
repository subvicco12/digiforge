<?php
declare(strict_types=1);
require_once dirname(__DIR__,4).'/tests/wordpress/CustomerPhotoHeldIntegrationTest.php';
require_once dirname(__DIR__,4).'/tests/wordpress/OrderLocalWorkflowTest.php';
require_once dirname(__DIR__).'/bootstrap.php';

use DigiForge\NativeAuthority\WordPress\OfflineSchema;
use DigiForge\Orders\{PersonalizationReadContext,PersonalizationCurrentness,HeldFulfillmentPreparation,Repository};
use DigiForge\Portal\OrderLocalWorkflow;
use DigiForge\POD\{CustomerPhotoSelection,CustomerEditorRepository,ProductionAuthorizationRepository,BusinessScopeRepository,RasterArtworkCodec};
use DigiForge\PortalAccess\{Store,Session};
use DigiForge\Database\Tables;

/** Reuses the genuine native-business + actual A photo/render fixture, not a resolver stand-in. */
final class ManagedReviewerHeldCompositionTest extends WP_UnitTestCase
{
    use NativeBusinessOrderFixture;
    private array $photoOptions=[];
    private array $customerIds=[];
    private $denyHttp;
    private int $http=0;
    public function setUp():void {
        parent::setUp();\DigiForge\Core\Activator::activate();$this->bootOrderFixture('digicraftifygoods');
        global $wpdb;foreach([Tables::pod_catalog(),Tables::pod_production_templates(),Tables::shop_ai_policies(),Tables::shop_ai_usage(),Tables::fulfillment_intents(),Tables::pod_render_evidence(),Tables::pod_authorization_packages(),Tables::jobs()]as $table)$this->rows[$table]=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);
        foreach(['df_ce_','df_photo_','digiforge_render_']as $prefix)foreach($wpdb->get_results($wpdb->prepare('SELECT * FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like($prefix).'%'),ARRAY_A)as $row)$this->photoOptions[$row['option_name']]=$row;
        $this->denyHttp=function(){$this->http++;return new WP_Error('unexpected_photo_http');};add_filter('pre_http_request',$this->denyHttp);
    }
    public function tearDown():void {
        remove_filter('pre_http_request',$this->denyHttp);global $wpdb;$wpdb->query('ROLLBACK');OfflineSchema::uninstallForDisposableTests();
        try{
            foreach(['df_ce_','df_photo_','digiforge_render_']as $prefix)$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like($prefix).'%'));
            foreach($this->photoOptions as $row)$wpdb->insert($wpdb->options,$row,array_fill(0,count($row),'%s'));
            require_once ABSPATH.'wp-admin/includes/user.php';foreach($this->customerIds as $id)wp_delete_user($id);
            $this->restoreOrderFixture();self::assertSame(0,$this->http);
        }finally{parent::tearDown();}
    }
    private function foreignReviewedPhoto():array {
        $f=$this->photo();$ownerCookies=$_COOKIE;
        $reviewer=(int)self::factory()->user->create(['role'=>'administrator']);$this->customerIds[]=$reviewer;
        self::assertIsArray(Store::change($reviewer,'Operator',['personalized_pod'=>['orders','personalized_pod']],true,0,wp_generate_uuid4()));
        wp_set_current_user($f['customer']);$request=$f['request'];$request['expected_head']=$f['version']['sha256'];$request['request_key']='actual-foreign-reviewed-selection';
        $version=PrintifyApiApprovalFixture::checked((new CustomerEditorRepository())->upload($f['session'],$request));
        $selection=PrintifyApiApprovalFixture::checked((new CustomerPhotoSelection())->submit($f['session'],$version['sha256'],1));
        PortalAccessFixture::login($reviewer);self::assertTrue(Session::start());
        PrintifyApiApprovalFixture::checked((new CustomerPhotoSelection())->review($f['session'],$selection['sha256'],2,'APPROVED','Actual foreign reviewer retained independently from decision actor'));
        wp_set_current_user(1);$_COOKIE=$ownerCookies;WP_Session_Tokens::get_instance($reviewer)->destroy_all();
        $f['selection']=$selection;$f['version']=$version;$f['reviewer']=$reviewer;
        $resolved=PrintifyApiApprovalFixture::checked((new CustomerPhotoSelection())->resolveForOrder('personalized_pod',$f['order'],$f['line']));
        self::assertSame($reviewer,$resolved['review_provenance']['reviewer_user_id']);
        self::assertSame([],WP_Session_Tokens::get_instance($reviewer)->get_all());
        global $wpdb;$canonical=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE id=%d',$selection['submission_id']),ARRAY_A);
        self::assertNotSame($reviewer,(int)$canonical['reviewed_by'],'Canonical-input reviewer must not be substituted for actual selected-review provenance.');
        return $f;
    }
    private function install():void {
        remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);
        self::assertTrue(OfflineSchema::installForDisposableTests());
    }
    private function confirmation(string $action,int $order,array $params=[]):array {
        $helper=new OrderLocalWorkflowTest();
        $body=(new ReflectionMethod($helper,'body'))->invoke($helper,$action,$order)+$params;
        $html=(new ReflectionMethod($helper,'invoke'))->invoke($helper,$body);
        $confirm=(new ReflectionMethod($helper,'confirmed'))->invoke($helper,$body,$html);
        self::assertArrayHasKey('REVIEW',$confirm['df_confirmation_tokens']??[]);
        return $confirm;
    }
    /** Full actual selection/render/plan chain, with canonical business selected before connection enrollment. */
    public function testActualForeignReviewedPhotoFlowsThroughRetainedPlanHeldJobAndRejectsOldEpoch():void {
        $f=$this->foreignReviewedPhoto();$photo=new CustomerPhotoSelection();$selection=$f['selection'];
        PrintifyApiApprovalFixture::checked($photo->approvePhotoLayout($f['session'],$selection['sha256'],2,$f['template'],1,'Actual local photo layout'));
        $render=PrintifyApiApprovalFixture::checked($photo->prepare($f['session'],$selection['sha256'],2,$f['template'],1));
        PrintifyApiApprovalFixture::checked($photo->approveRender($f['session'],$selection['sha256'],2,'Actual local visual review'));
        $package=PrintifyApiApprovalFixture::checked((new ProductionAuthorizationRepository())->create($f['order'],(int)$render['id']));
        $package=PrintifyApiApprovalFixture::checked((new ProductionAuthorizationRepository())->approveForReview((int)$package['id']));
        self::assertSame('APPROVED_PACKAGE',$package['state']);
        $this->install();
        $context=PersonalizationReadContext::capture('personalized_pod',$f['order']);
        self::assertNotWPError($context,is_wp_error($context)?$context->get_error_code():'Actual retained managed context required.');
        global $wpdb;
        try{
            $wpdb->query('START TRANSACTION');
            $current=PrintifyApiApprovalFixture::checked($context->forOrder($f['order']));
            self::assertSame($selection['sha256'],$current[0]['selection_sha256']);self::assertSame(2,$current[0]['selection_revision']);
            self::assertWPError($photo->resolveForOrder('personalized_pod',$f['order'],$f['line']),'Public A getter remains forbidden inside caller TX.');
            self::assertTrue($context->validate());
        }finally{$wpdb->query('ROLLBACK');self::assertTrue($context->release());}
        $confirm=$this->confirmation(OrderLocalWorkflow::DRAFT,$f['order'],['shipping_method'=>'standard','base_production_cost'=>'10','shipping_estimate'=>'4']);
        $draft=PrintifyApiApprovalFixture::checked((new OrderLocalWorkflow())->processBrowser($confirm));$plan=(int)$draft['result']['id'];
        self::assertSame('DRAFT',$draft['result']['state']);self::assertFalse($draft['external_execution_authorized']);
        $payload=json_decode($draft['result']['canonical_payload'],true);self::assertSame($selection['sha256'],$payload['personalization'][0]['selection_sha256']);self::assertSame(2,$payload['personalization'][0]['selection_revision']);
        foreach([OrderLocalWorkflow::BEGIN_PLAN_REVIEW,OrderLocalWorkflow::APPROVE_PLAN,OrderLocalWorkflow::PREPARE_INTENT]as $action){$confirm=$this->confirmation($action,$f['order'],['plan_id'=>(string)$plan]);$result=PrintifyApiApprovalFixture::checked((new OrderLocalWorkflow())->processBrowser($confirm));self::assertFalse($result['external_execution_authorized']);}
        self::assertSame('BLOCKED',$result['result']['state']);
        $held=PrintifyApiApprovalFixture::checked((new HeldFulfillmentPreparation())->prepare('personalized_pod',$f['order'],(int)$result['result']['id']));
        self::assertSame($selection['sha256'],$held['preparation']['route']['payload']['personalization'][0]['selection_sha256']);
        self::assertFalse($held['external_execution_authorized']);self::assertFalse($held['preparation']['adapter_invoked']);self::assertTrue($held['render_artifact_recheck_required']);
        self::assertSame([],WP_Session_Tokens::get_instance($f['reviewer'])->get_all());self::assertSame(1,get_current_user_id());
        $key=\DigiForge\Queue\Shop2HeldJobRecovery::enqueueKey('personalized_pod',$f['order'],(int)$result['result']['id'],$held['plan_payload_hash'],$held['current_evidence_hash'],1);
        $job=PrintifyApiApprovalFixture::checked((new \DigiForge\Queue\Shop2HeldJobRecovery())->enqueue('personalized_pod',$f['order'],(int)$result['result']['id'],$held['current_evidence_hash'],$key));
        self::assertSame('BLOCKED',$job['job']['state']);self::assertFalse($job['external_execution_authorized']);self::assertFalse($job['dispatch_permitted']);
        // Same pixels/new authoritative selection epoch: old actual artifacts and held plan stay stale.
        $ownerCookies=$_COOKIE;wp_set_current_user($f['customer']);$request=$f['request'];$request['expected_head']=$f['version']['sha256'];$request['request_key']='actual-third-selection-after-retained-held-job';
        $newVersion=PrintifyApiApprovalFixture::checked((new CustomerEditorRepository())->upload($f['session'],$request));
        $next=PrintifyApiApprovalFixture::checked($photo->submit($f['session'],$newVersion['sha256'],2));wp_set_current_user(1);$_COOKIE=$ownerCookies;
        PrintifyApiApprovalFixture::checked($photo->review($f['session'],$next['sha256'],3,'APPROVED','Actual new independent epoch after committed local held preparation'));
        self::assertNotSame($selection['sha256'],$next['sha256']);self::assertSame($selection['snapshot']['payload_sha256'],$next['snapshot']['payload_sha256']);
        $stored=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE id=%d',$render['id']),ARRAY_A);
        self::assertWPError((new PersonalizationCurrentness())->forRender($f['order'],(int)$stored['provider_mapping_id'],$stored['personalization_evidence_hash'],$stored));
        self::assertWPError((new ProductionAuthorizationRepository())->approveForReview((int)$package['id']));
        self::assertSame($package,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$package['id']),ARRAY_A));
        self::assertWPError((new HeldFulfillmentPreparation())->prepare('personalized_pod',$f['order'],(int)$result['result']['id']));
        self::assertSame('BLOCKED',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::jobs().' WHERE id=%d',$job['job_id'])));

    }
    public function testFinalSignedReadReviewerRevocationDeniesPrivateCommittedSuccess():void {
        $f=$this->foreignReviewedPhoto();$this->install();
        $confirm=$this->confirmation(OrderLocalWorkflow::DRAFT,$f['order'],['shipping_method'=>'standard','base_production_cost'=>'10','shipping_estimate'=>'4']);
        global $wpdb;$wrote=false;$committed=false;$released=false;$hit=false;
        $fault=static function(string $sql)use(&$wrote,&$committed,&$released,&$hit,$f):string{
            if(str_starts_with($sql,'INSERT INTO `'.Tables::fulfillment_plans().'`'))$wrote=true;
            if($wrote&&$sql==='COMMIT')$committed=true;
            if($committed&&str_starts_with($sql,'UPDATE `')&&str_contains($sql,'digiforge_reviewer_authority_leases')&&str_contains($sql,"SET state='RELEASED'"))$released=true;
            if($released&&!$hit&&str_starts_with($sql,'SELECT option_value FROM ')&&str_contains($sql,Store::key(1))){$hit=true;(new WP_User($f['reviewer']))->add_cap('manage_digiforge_orders',false);}
            return $sql;
        };
        add_filter('query',$fault);
        try{$result=(new OrderLocalWorkflow())->processBrowser($confirm);}finally{remove_filter('query',$fault);}
        self::assertTrue($wrote);self::assertTrue($committed);self::assertTrue($released);self::assertTrue($hit);
        self::assertWPError($result,'Revoking only the actual foreign reviewer during final signed SQL cannot return a private committed result.');
        self::assertSame('COMMITTED',$result->get_error_data()['transaction_outcome']);self::assertFalse($result->get_error_data()['retry_permitted']);
        self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::fulfillment_plans().' WHERE order_id=%d',$f['order'])));
        self::assertWPError((new OrderLocalWorkflow())->processBrowser($confirm));
    }
    public static function contradictoryCommitConsumers():array{return [['operator'],['queue']];}
    /** @dataProvider contradictoryCommitConsumers */
    public function testActualRollbackCannotBeLabeledCommittedWhenPrivateWitnessContradicts(string $consumer):void {
        $f=$this->foreignReviewedPhoto();global $wpdb;
        if($consumer==='operator'){
            $this->install();$confirm=$this->confirmation(OrderLocalWorkflow::DRAFT,$f['order'],['shipping_method'=>'standard','base_production_cost'=>'10','shipping_estimate'=>'4']);
        }else{
            $repo=new Repository();$plan=PrintifyApiApprovalFixture::checked($repo->createPlan(['order_id'=>$f['order'],'plan_version'=>'actual-queue-rollback-proof','provider'=>'printify','shipping_method_metadata'=>['method'=>'standard'],'cost_snapshot_metadata'=>['base_production_cost'=>10,'shipping_estimate'=>4]]));
            foreach(['REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($repo->transition('plan',(int)$plan['id'],$state));
            $intent=PrintifyApiApprovalFixture::checked($repo->createIntent(['order_id'=>$f['order'],'fulfillment_plan_id'=>(int)$plan['id'],'intent_type'=>'PREPARE_FULFILLMENT']));
            $prepared=PrintifyApiApprovalFixture::checked((new HeldFulfillmentPreparation())->prepare('personalized_pod',$f['order'],(int)$intent['id']));
            $key=\DigiForge\Queue\Shop2HeldJobRecovery::enqueueKey('personalized_pod',$f['order'],(int)$intent['id'],$prepared['plan_payload_hash'],$prepared['current_evidence_hash'],1);$this->install();
        }
        $wrote=false;$rolledBack=false;$table=$consumer==='operator'?Tables::fulfillment_plans():Tables::jobs();
        $fault=static function(string $sql)use(&$wrote,&$rolledBack,$table):string{
            if(str_starts_with($sql,'INSERT INTO `'.$table.'`'))$wrote=true;
            if($wrote&&!$rolledBack&&$sql==='COMMIT'){$rolledBack=true;return 'ROLLBACK';}
            return $sql;
        };
        add_filter('query',$fault);
        try{$result=$consumer==='operator'?(new OrderLocalWorkflow())->processBrowser($confirm):(new \DigiForge\Queue\Shop2HeldJobRecovery())->enqueue('personalized_pod',$f['order'],(int)$intent['id'],$prepared['current_evidence_hash'],$key);}finally{remove_filter('query',$fault);}
        self::assertTrue($wrote);self::assertTrue($rolledBack);self::assertWPError($result);
        $data=$result->get_error_data();self::assertSame('UNKNOWN',$data[$consumer==='operator'?'transaction_outcome':'local_commit_state']);
        self::assertNull($data[$consumer==='operator'?'committed':'local_metadata_committed']);self::assertFalse($data['retry_permitted']);
        self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
        $remaining=$consumer==='operator'?$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::fulfillment_plans().' WHERE order_id=%d',$f['order'])):$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::jobs().' WHERE job_type=%s','shop2_held_preparation'));
        self::assertSame('0',$remaining,'The actual injected rollback removed only this test-owned business effect.');
    }
    public function testFailedManagedQueuePinRollsBackOnlyItsStartedFrame():void {
        $f=$this->foreignReviewedPhoto();$repo=new Repository();
        $plan=PrintifyApiApprovalFixture::checked($repo->createPlan(['order_id'=>$f['order'],'plan_version'=>'actual-queue-pin-failure','provider'=>'printify','shipping_method_metadata'=>['method'=>'standard'],'cost_snapshot_metadata'=>['base_production_cost'=>10,'shipping_estimate'=>4]]));
        foreach(['REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($repo->transition('plan',(int)$plan['id'],$state));
        $intent=PrintifyApiApprovalFixture::checked($repo->createIntent(['order_id'=>$f['order'],'fulfillment_plan_id'=>(int)$plan['id'],'intent_type'=>'PREPARE_FULFILLMENT']));
        $prepared=PrintifyApiApprovalFixture::checked((new HeldFulfillmentPreparation())->prepare('personalized_pod',$f['order'],(int)$intent['id']));
        $key=\DigiForge\Queue\Shop2HeldJobRecovery::enqueueKey('personalized_pod',$f['order'],(int)$intent['id'],$prepared['plan_payload_hash'],$prepared['current_evidence_hash'],1);$this->install();
        $hit=false;$fault=static function(string $sql)use(&$hit):string{
            if(!$hit&&str_starts_with($sql,'UPDATE `')&&str_contains($sql,'digiforge_reviewer_authority_leases')&&str_contains($sql,' SET commit_marker=')){$hit=true;return 'UPDATE missing_retained_pin_marker SET id=1';}
            return $sql;
        };
        add_filter('query',$fault);
        try{$result=(new \DigiForge\Queue\Shop2HeldJobRecovery())->enqueue('personalized_pod',$f['order'],(int)$intent['id'],$prepared['current_evidence_hash'],$key);}finally{remove_filter('query',$fault);}
        global $wpdb;
        try{
            self::assertTrue($hit);self::assertWPError($result);
            self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'),'Failed pin before enqueue returns must close its own verified transaction.');
            self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::jobs().' WHERE job_type=%s','shop2_held_preparation')));
            self::assertFalse($result->get_error_data()['retry_permitted']);self::assertFalse($result->get_error_data()['replay_permitted']);
        }finally{$wpdb->query('ROLLBACK');}
    }
    public function testActualMappingAbaCannotMixProducerSnapshotAndDifferentLockedMapping():void {
        $f=$this->foreignReviewedPhoto();$this->install();global $wpdb;$primary=$wpdb;
        $mapping=(int)$f['selection']['snapshot']['provider_mapping_id'];$table=Tables::pod_mappings();
        $original=$primary->get_row($primary->prepare('SELECT * FROM '.$table.' WHERE id=%d',$mapping),ARRAY_A);
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$other->suppress_errors(true);
        self::assertNotSame($primary->get_var('SELECT CONNECTION_ID()'),$other->get_var('SELECT CONNECTION_ID()'));
        $candidate='actual-current-mapping-with-different-source-digest';
        self::assertSame(1,$other->update($table,['mapping_version'=>$candidate],['id'=>$mapping]));
        self::assertWPError((new CustomerPhotoSelection())->resolveForOrder('personalized_pod',$f['order'],$f['line']),'Actual producer must deny this different full mapping before the race.');
        $repaired=false;$restored=false;$context=null;
        $race=static function(string $sql)use($other,$primary,$table,$mapping,$original,$candidate,&$repaired,&$restored):string{
            if(!$repaired&&str_starts_with($sql,'SELECT * FROM '.$table.' WHERE id=')&&str_contains($sql,' AND product_version_id=')){
                $repaired=true;if($other->update($table,['mapping_version'=>$original['mapping_version']],['id'=>$mapping])!==1)throw new RuntimeException('Actual producer-read mapping repair failed.');
            }
            // This bridge-table metadata query occurs only AFTER the actual A resolver returns.
            if($repaired&&!$restored&&str_starts_with($sql,'SELECT ENGINE FROM information_schema.TABLES')&&str_contains($sql,OfflineSchema::generationTable($primary))){
                $restored=true;if($other->update($table,['mapping_version'=>$candidate],['id'=>$mapping])!==1)throw new RuntimeException('Actual mapping ABA restoration failed.');
            }
            return $sql;
        };
        add_filter('query',$race);
        try{
            $context=PersonalizationReadContext::capture('personalized_pod',$f['order']);
            if(is_wp_error($context))$result=$context;
            else{$primary->query('START TRANSACTION');$result=$context->forOrder($f['order']);}
            self::assertTrue($repaired);self::assertTrue($restored);
            self::assertSame($candidate,$primary->get_var($primary->prepare('SELECT mapping_version FROM '.$table.' WHERE id=%d',$mapping)));
            self::assertWPError($result,'Captured material and current locked material may agree while the authoritative photo snapshot binds a different full mapping.');
            self::assertSame('personalization_currentness_selection_identity',$result->get_error_code());
        }finally{
            remove_filter('query',$race);$primary->query('ROLLBACK');if($context instanceof PersonalizationReadContext)$context->release(false);
            $other->update($table,['mapping_version'=>$original['mapping_version']],['id'=>$mapping]);$other->close();
        }
    }
 protected function photo(bool $select=true):array {
  [, $mapping,$version,$owner]=$this->product();global $wpdb;$planId=(int)$wpdb->get_var($wpdb->prepare('SELECT production_plan_id FROM '.Tables::pod_mappings().' WHERE id=%d',$mapping));$owned=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE id=%d',$owner),ARRAY_A);$pod=new \DigiForge\POD\Repository();$newMap=PrintifyApiApprovalFixture::checked($pod->createMapping(['product_version_id'=>$version,'production_plan_id'=>$planId,'provider'=>'printify','environment'=>'test','provider_product_key'=>'101','provider_variant_key'=>'2:202','mapping_version'=>'actual-photo-consumer-v1']));$business=new BusinessScopeRepository();$newOwner=PrintifyApiApprovalFixture::checked($business->createMapping(['product_version_id'=>$version,'provider_mapping_id'=>$newMap['id'],'business_id'=>(int)$owned['business_id'],'store_id'=>(int)$owned['store_id'],'product_program'=>'PERSONALIZED_POD']));PrintifyApiApprovalFixture::checked($business->approveMapping((int)$newOwner['id']));foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($pod->transition('mapping',(int)$newMap['id'],$state));
  $repo=new Repository();$order=PrintifyApiApprovalFixture::checked($repo->createOrder(['channel'=>'etsy','environment'=>'test','shop_reference'=>'personalized_pod','currency'=>'USD','personalization_required'=>true]));$line=PrintifyApiApprovalFixture::checked($repo->addLineItem(['order_id'=>$order['id'],'product_version_id'=>$version,'provider_mapping_id'=>$newMap['id'],'quantity'=>1]));$schema=PrintifyApiApprovalFixture::checked($pod->createPersonalizationSchema(['product_version_id'=>$version,'schema_key'=>'actual-photo-consumer','version_label'=>'1','field_definitions'=>[['key'=>'photo','type'=>'image_reference','required'=>true]]]));foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($pod->transition('personalization',(int)$schema['id'],$state));$seed=PrintifyApiApprovalFixture::checked($repo->createPersonalization(['order_line_item_id'=>$line['id'],'personalization_schema_id'=>$schema['id'],'payload'=>['photo'=>'digiforge/product-factory/source.png']]));PrintifyApiApprovalFixture::checked($repo->reviewPersonalization((int)$seed['id'],'APPROVED'));foreach(['REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($repo->transition('order',(int)$order['id'],$state));
  PrintifyApiApprovalFixture::checked((new \DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>'personalized_pod','template_cap'=>['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-01-05 00:00:00']]));$template='actual-photo-consumer-'.wp_generate_uuid4();$definition=digiforge_test_printify_template(['shop_key'=>'personalized_pod','template_id'=>$template,'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>640,'height_px'=>480,'dpi'=>300,'qa_geometry'=>['safe_zone'=>['x'=>20,'y'=>20,'width'=>600,'height'=>440],'seam_exclusions'=>[],'min_image_dpi'=>72,'bleed'=>['trim_box'=>['x'=>10,'y'=>10,'width'=>620,'height'=>460],'margins_px'=>['left'=>10,'right'=>10,'top'=>10,'bottom'=>10]]]]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'SINGLE_PHOTO','template_status'=>'VALIDATED','environment'=>'test']);PrintifyApiApprovalFixture::checked($definition);$stored=PrintifyApiApprovalFixture::checked((new \DigiForge\POD\ProductionTemplateRepository())->save($definition));$input=['order_id'=>(int)$order['id'],'provider_mapping_id'=>(int)$newMap['id'],'template_key'=>$template,'template_version'=>'1','template_sha256'=>$stored['fingerprint'],'personalization_evidence_hash'=>$seed['payload_hash']];$customer=(int)self::factory()->user->create(['role'=>'subscriber']);$this->customerIds[]=$customer;$fixture=new RenderArtifactCertificationTest();$grant=(new ReflectionMethod($fixture,'editorGrant'))->invoke($fixture,$input,$customer);
  $request=['request_key'=>'actual-photo-first','expected_head'=>null,'mode'=>'PHOTO','image_base64'=>base64_encode(RasterArtworkCodec::encode(256,256,str_repeat("\x40\x80\xa0\xff",65536))),'image_field'=>'photo','fields'=>[],'selection'=>['x'=>0,'y'=>0,'width'=>256,'height'=>256],'rights_confirmed'=>true,'processing_consent'=>true];if(!$select)return ['order'=>(int)$order['id'],'line'=>(int)$line['id'],'session'=>$grant['session_key'],'customer'=>$customer,'request'=>$request];wp_set_current_user($customer);$editorVersion=PrintifyApiApprovalFixture::checked((new CustomerEditorRepository())->upload($grant['session_key'],$request));$selection=PrintifyApiApprovalFixture::checked((new CustomerPhotoSelection())->submit($grant['session_key'],$editorVersion['sha256'],0));$this->fixture->owner();PrintifyApiApprovalFixture::checked((new CustomerPhotoSelection())->review($grant['session_key'],$selection['sha256'],1,'APPROVED','Synthetic independent photo review, not supplier certification'));\DigiForge\Core\Settings::protectProduction();return ['order'=>(int)$order['id'],'line'=>(int)$line['id'],'selection'=>$selection,'session'=>$grant['session_key'],'access_sha256'=>$grant['sha256'],'customer'=>$customer,'version'=>$editorVersion,'request'=>$request,'template'=>$template];
 }
}
