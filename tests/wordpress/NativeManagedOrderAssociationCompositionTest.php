<?php
declare(strict_types=1);

use DigiForge\Connector\Printify\{NativeBusinessOrderEvidence,NativeBusinessVersionRepository,NativeBusinessVersionSchema};
use DigiForge\Database\Tables;
use DigiForge\Orders\{HeldFulfillmentPreparation,PersonalizationCurrentness,PersonalizationReadContext};
use DigiForge\Portal\OrderLocalWorkflow;
use DigiForge\POD\{PrintifyApiReadWorkflow,PrintifyControlledTransport};
use DigiForge\Security\ReviewedLocalEvidence;

/** Actual managed producer, governed read, independent review and protected local entity composition. */
final class NativeManagedOrderAssociationCompositionTest extends WP_UnitTestCase
{
    private ManagedReviewerHeldCompositionTest $managed;
    private const ZERO='0000000000000000000000000000000000000000000000000000000000000000';
    public function setUp():void
    {
        // Reuse the genuine source/authority fixture; no copied reviewer or photo issuer.
        require_once dirname(__DIR__,2).'/services/native-authority/wordpress/tests/ManagedReviewerHeldCompositionTest.php';
        $this->managed=new ManagedReviewerHeldCompositionTest();$this->managed->setUp();
    }
    public function tearDown():void
    {
        global $wpdb;$wpdb->query('ROLLBACK');$wpdb->query('DROP TABLE IF EXISTS '.NativeBusinessVersionSchema::table());
        $this->managed->tearDown();
    }
    private function helper(string $method,mixed ...$arguments):mixed {return (new ReflectionMethod($this->managed,$method))->invoke($this->managed,...$arguments);}
    private function fixture():array
    {
        $f=$this->helper('managedApprovedPhoto');
        $confirmation=$this->helper('confirmation',OrderLocalWorkflow::DRAFT,$f['order'],['shipping_method'=>'standard','base_production_cost'=>'10','shipping_estimate'=>'4']);
        $draft=PrintifyApiApprovalFixture::checked((new OrderLocalWorkflow())->processBrowser($confirmation));$plan=(int)$draft['result']['id'];
        foreach([OrderLocalWorkflow::BEGIN_PLAN_REVIEW,OrderLocalWorkflow::APPROVE_PLAN,OrderLocalWorkflow::PREPARE_INTENT]as $action){$confirmation=$this->helper('confirmation',$action,$f['order'],['plan_id'=>(string)$plan]);$result=PrintifyApiApprovalFixture::checked((new OrderLocalWorkflow())->processBrowser($confirmation));}
        $scope=$this->helper('scope');$authority=(new ReflectionProperty($this->managed,'fixture'))->getValue($this->managed);
        global $wpdb;$line=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE id=%d',$f['line']),ARRAY_A);$mapping=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d',$line['provider_mapping_id']),ARRAY_A);
        foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'printify_activation_authorized'=>true,'printify'=>true]as $name=>$value)$authority->setting($name,$value);
        $workflow=new PrintifyApiReadWorkflow();$productPlan=PrintifyApiApprovalFixture::checked($workflow->prepare('personalized_pod',$scope->integrationId,'PRODUCT_READ',['product_id'=>'managed-provider-product','blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202]]));$productApproval=$authority->approve($workflow,$productPlan,'printify-api-read');
        $productBody=['id'=>'managed-provider-product','shop_id'=>$scope->providerShopId,'blueprint_id'=>101,'print_provider_id'=>2,'variants'=>[['id'=>202,'price'=>1899,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[202],'placeholders'=>[['position'=>'front','images'=>[]]]]],'images'=>[]];
        $productReceipt=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($productBody)))->executeApiRead($productPlan['hash'],$productApproval['hash']));
        $workflow=new PrintifyApiReadWorkflow();$read=PrintifyApiApprovalFixture::checked($workflow->prepare('personalized_pod',$scope->integrationId,'ORDER_READ',['order_id'=>'managed-source-order']));$approved=$authority->approve($workflow,$read,'printify-api-read');
        $receipt=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response(['id'=>'managed-source-order','status'=>'pending','line_items'=>[['id'=>'managed-provider-line','product_id'=>'managed-provider-product','variant_id'=>202,'quantity'=>(int)$line['quantity']]],'shipments'=>[]])))->executeApiRead($read['hash'],$approved['hash']));
        \DigiForge\Core\Settings::protectProduction();
        $associations=[['provider_line_id'=>'managed-provider-line','local_line_id'=>(int)$line['id'],'product_version_id'=>(int)$line['product_version_id'],'provider_mapping_id'=>(int)$line['provider_mapping_id'],'quantity'=>(int)$line['quantity'],'product_source_receipt'=>$productReceipt['hash']]];
        $payload=json_decode($draft['result']['canonical_payload'],true);self::assertSame($f['selection']['sha256'],$payload['personalization'][0]['selection_sha256']);self::assertSame($f['selection']['revision'],$payload['personalization'][0]['selection_revision']);
        self::assertSame([],WP_Session_Tokens::get_instance($f['reviewer'])->get_all());
        return $f+['args'=>[$scope,1,$f['order'],$receipt['hash'],$plan,$associations],'intent'=>(int)$result['result']['id'],'authority'=>$authority,'product_plan'=>$productPlan,'product_receipt'=>$productReceipt];
    }
    private function reviewed(array $f):array
    {
        $proposal=PrintifyApiApprovalFixture::checked(NativeBusinessOrderEvidence::prepareEvidence(...$f['args']));$key='managed-producer-association';
        $review=PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::propose('policy','personalized_pod',$key,$proposal,time(),time()+1200,wp_generate_uuid4(),0));
        PortalAccessFixture::login($f['authority']->reviewer);PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::decide('policy','personalized_pod',$key,$review['revision'],$review['proposal_hash'],'APPROVED',wp_generate_uuid4()));$f['authority']->owner();
        return [...$f['args'],$key];
    }
    private function checkedVersion(mixed $result):array
    {
        $message='';if(is_wp_error($result)){
            $data=$result->get_error_data();$data=is_array($data)?$data:[];$public=array_intersect_key($data,array_flip(['reason','outcome']));$message=wp_json_encode($public);
            if(($public['outcome']??null)==='UNKNOWN'){global $wpdb;$expires=$wpdb->get_col("SELECT expires_at FROM ".\DigiForge\NativeAuthority\WordPress\OfflineSchema::leasesTable($wpdb)." WHERE commit_marker IS NOT NULL ORDER BY expires_at DESC LIMIT 2");file_put_contents('/tmp/native-managed-profile-unknown-observation.json',wp_json_encode($public+['observed_at'=>time(),'actual_committed_phase_expires_at'=>array_map('intval',$expires)],JSON_PRETTY_PRINT));}
        }
        self::assertNotWPError($result,$message);return $result;
    }
    private function installVersions():void {global $wpdb;$wpdb->query('DROP TABLE IF EXISTS '.NativeBusinessVersionSchema::table());self::assertNotFalse($wpdb->query(NativeBusinessVersionSchema::statement()));}
    private function foreignProductReceipt(array $f):string
    {
        $current=PrintifyApiApprovalFixture::checked(\DigiForge\Connector\Printify\NativeAuthorityAdapter::scope($f['args'][0],1));$c=$current['scope'];$integrations=new \DigiForge\Integrations\Repository();
        $i=PrintifyApiApprovalFixture::checked($integrations->create(['provider'=>'printify','environment'=>'test','connection_key'=>'foreign-product-source-'.wp_generate_uuid4(),'display_name'=>'Explicit other disposable provider account','status'=>'CONFIGURED','enabled'=>false,'config'=>['shop_key'=>'personalized_pod','shop_id'=>'99','account_reference'=>'foreign-provider-account','catalog_read_scope'=>['business_id'=>(string)$c['business_id'],'store_id'=>(string)$c['store_id'],'product_program'=>'PERSONALIZED_POD']]]));
        PrintifyApiApprovalFixture::checked($integrations->storeSecret($i['id'],'personal_access_token','synthetic-product-source-'.wp_generate_uuid4()));PrintifyApiApprovalFixture::checked($integrations->setEnabled($i['id'],true));
        $boundary=new \DigiForge\POD\ScopedProviderConnection();$proposal=PrintifyApiApprovalFixture::checked($boundary->propose('personalized_pod',['integration_id'=>$i['id'],'capability'=>'printify.connection.read','source_sha256'=>hash('sha256','disposable-foreign-product-source'),'evidence_reference'=>'local://disposable/foreign-product','observed_at'=>gmdate('c',time()-1)],time(),time()+1800,wp_generate_uuid4(),0));
        PortalAccessFixture::login($f['authority']->reviewer);PrintifyApiApprovalFixture::checked($boundary->decide('personalized_pod',$i['id'],'printify.connection.read',$proposal['revision'],$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));$f['authority']->owner();
        foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'printify_activation_authorized'=>true,'printify'=>true]as $name=>$value)$f['authority']->setting($name,$value);
        $workflow=new PrintifyApiReadWorkflow();$plan=PrintifyApiApprovalFixture::checked($workflow->prepare('personalized_pod',$i['id'],'PRODUCT_READ',['product_id'=>'managed-provider-product','blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202]]));$auth=$f['authority']->approve($workflow,$plan,'printify-api-read');
        $body=['id'=>'managed-provider-product','shop_id'=>'99','blueprint_id'=>101,'print_provider_id'=>2,'variants'=>[['id'=>202,'price'=>1899,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[202],'placeholders'=>[['position'=>'front','images'=>[]]]]],'images'=>[]];
        $receipt=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($body)))->executeApiRead($plan['hash'],$auth['hash']));\DigiForge\Core\Settings::protectProduction();return $receipt['hash'];
    }
    public function testActualManagedPlanProducesReviewedAssociationProtectedLocalVersionAndCurrentHeldMaterial():void
    {
        $f=$this->fixture();$proposal=NativeBusinessOrderEvidence::prepareEvidence(...$f['args']);
        self::assertNotWPError($proposal,is_wp_error($proposal)?$proposal->get_error_code():'Managed selection epoch must reach canonical producer.');
        self::assertNull($proposal['native_order_version']);self::assertFalse($proposal['native_allocation_receipt_issued']);
        $key='managed-producer-association';$review=PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::propose('policy','personalized_pod',$key,$proposal,time(),time()+1200,wp_generate_uuid4(),0));
        PortalAccessFixture::login($f['authority']->reviewer);PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::decide('policy','personalized_pod',$key,$review['revision'],$review['proposal_hash'],'APPROVED',wp_generate_uuid4()));$f['authority']->owner();
        $args=[...$f['args'],$key];$association=PrintifyApiApprovalFixture::checked(NativeBusinessOrderEvidence::resolveReviewed(...$args));self::assertTrue($association['local_association_reviewed']);
        global $wpdb;self::assertNotFalse($wpdb->query(NativeBusinessVersionSchema::statement()));$pins=[];
        $observe=static function(string $sql)use(&$pins):string{if(str_contains($sql,'digiforge_reviewer_authority_leases')&&str_contains($sql,"SET state='PINNED'"))$pins[]=hash('sha256',$sql);return $sql;};
        add_filter('query',$observe);try{$version=(new NativeBusinessVersionRepository())->recordReviewedVersion(...[...$args,0,self::ZERO]);}finally{remove_filter('query',$observe);}
        $version=$this->checkedVersion($version);self::assertNull($version['native_order_version']);self::assertFalse($version['native_allocation_receipt_issued']);
        self::assertGreaterThanOrEqual(2,count(array_unique($pins)),'The record and confirmation transactions require distinct actual retained-reviewer pins.');
        $current=$this->checkedVersion((new NativeBusinessVersionRepository())->readCurrent(...$args));self::assertSame($version['local_business_version_hash'],$current['local_business_version_hash']);
        $readReleases=0;$readRevoked=false;$readFault=static function(string $sql)use(&$readReleases,&$readRevoked):string{if(str_starts_with($sql,'SELECT RELEASE_LOCK(')&&str_contains($sql,'df_bov_')&&++$readReleases===2){$readRevoked=true;(new WP_User(1))->add_cap('manage_digiforge_products',false);}return $sql;};
        add_filter('query',$readFault);try{$private=(new NativeBusinessVersionRepository())->readCurrent(...$args);}finally{remove_filter('query',$readFault);(new WP_User(1))->add_cap('manage_digiforge_products',true);}
        self::assertSame(2,$readReleases);self::assertTrue($readRevoked);self::assertWPError($private);self::assertContains($private->get_error_data()['reason']??null,['read_released_source','response_scope']);self::assertSame('CONFIRMED',$wpdb->get_var('SELECT confirmation_state FROM '.NativeBusinessVersionSchema::table()));
        $genericReleased=false;$genericWithdrawn=false;$genericBefore=(new WP_User(1))->caps;
        $genericFault=static function(string $sql)use(&$genericReleased,&$genericWithdrawn):string{if(str_contains($sql,'digiforge_reviewer_authority_leases')&&str_contains($sql,"SET state='RELEASED'"))$genericReleased=true;if($genericReleased&&!$genericWithdrawn&&str_starts_with($sql,'SELECT li.id FROM ')){$genericWithdrawn=true;(new WP_User(1))->add_cap('manage_digiforge',false);}return $sql;};
        add_filter('query',$genericFault);try{$private=NativeBusinessOrderEvidence::resolveReviewed(...$args);}finally{remove_filter('query',$genericFault);$owner=new WP_User(1);if(array_key_exists('manage_digiforge',$genericBefore))$owner->add_cap('manage_digiforge',$genericBefore['manage_digiforge']);else $owner->remove_cap('manage_digiforge');wp_set_current_user(0);$f['authority']->owner();}
        self::assertTrue($genericReleased);self::assertTrue($genericWithdrawn);self::assertWPError($private);
        $held=PrintifyApiApprovalFixture::checked((new HeldFulfillmentPreparation())->prepare('personalized_pod',$f['order'],$f['intent']));self::assertFalse($held['external_execution_authorized']);self::assertFalse($held['preparation']['adapter_invoked']);
        self::assertSame($f['selection']['sha256'],$held['preparation']['route']['payload']['personalization'][0]['selection_sha256']);self::assertSame([],WP_Session_Tokens::get_instance($f['reviewer'])->get_all());
        // Same pixels, a genuinely new selected producer epoch: neither this
        // protected local association nor the old held plan may borrow it.
        $cookies=$_COOKIE;wp_set_current_user($f['customer']);$request=$f['request'];$request['expected_head']=$f['version']['sha256'];$request['request_key']='producer-currentness-new-epoch';
        $newVersion=PrintifyApiApprovalFixture::checked((new \DigiForge\POD\CustomerEditorRepository())->upload($f['session'],$request));
        $next=PrintifyApiApprovalFixture::checked((new \DigiForge\POD\CustomerPhotoSelection())->submit($f['session'],$newVersion['sha256'],$f['selection']['revision']));wp_set_current_user(1);$_COOKIE=$cookies;
        PrintifyApiApprovalFixture::checked((new \DigiForge\POD\CustomerPhotoSelection())->review($f['session'],$next['sha256'],$next['revision'],'APPROVED','Actual new selection epoch after protected association'));
        self::assertSame($f['selection']['snapshot']['payload_sha256'],$next['snapshot']['payload_sha256']);self::assertNotSame($f['selection']['sha256'],$next['sha256']);
        self::assertWPError((new NativeBusinessVersionRepository())->readCurrent(...$args));self::assertWPError(NativeBusinessOrderEvidence::resolveReviewed(...$args));self::assertWPError((new HeldFulfillmentPreparation())->prepare('personalized_pod',$f['order'],$f['intent']));
    }
    public function testCatalogObservationRequiresExactCurrentGovernedProductAndExplicitLines():void
    {
        $f=$this->fixture();$proposal=PrintifyApiApprovalFixture::checked(NativeBusinessOrderEvidence::prepareEvidence(...$f['args']));$observed=$proposal['product_observations'][(string)$f['line']];
        $released=false;$withdrawn=false;$sourceFault=static function(string $sql)use(&$released,&$withdrawn):string{if(str_contains($sql,'digiforge_reviewer_authority_leases')&&str_contains($sql,"SET state='RELEASED'"))$released=true;if($released&&!$withdrawn&&str_starts_with($sql,'SELECT li.id FROM ')){$withdrawn=true;(new WP_User(1))->add_cap('manage_digiforge_products',false);}return $sql;};
        add_filter('query',$sourceFault);try{$private=NativeBusinessOrderEvidence::prepareEvidence(...$f['args']);}finally{remove_filter('query',$sourceFault);(new WP_User(1))->add_cap('manage_digiforge_products',true);}
        self::assertTrue($released);self::assertTrue($withdrawn);self::assertWPError($private);
        self::assertSame($f['product_receipt']['hash'],$observed['source_receipt']);self::assertSame($f['product_plan']['hash'],$observed['plan_hash']);self::assertSame(202,$observed['variant_id']);self::assertFalse($proposal['provider_native_identity_verified']);
        foreach(['missing','unknown','wrong_operation','duplicate','wrong_scope']as $kind){$args=$f['args'];
            if($kind==='missing')unset($args[5][0]['product_source_receipt']);
            if($kind==='unknown')$args[5][0]['product_source_receipt']=str_repeat('f',64);
            if($kind==='wrong_operation')$args[5][0]['product_source_receipt']=$args[3];
            if($kind==='duplicate')$args[5][]=$args[5][0];
            if($kind==='wrong_scope'){$scope=$args[0];$args[0]=new \DigiForge\Connector\Printify\ShopBinding(3,'standard_pod',$scope->integrationId,$scope->providerShopId,$scope->environment,$scope->revision,$scope->credential,$scope->bindingHash,$scope->expiresAt);}
            self::assertWPError(NativeBusinessOrderEvidence::prepareEvidence(...$args),$kind);
        }
        // This is a real second governed observation, not edited receipt bytes.
        foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'printify_activation_authorized'=>true,'printify'=>true]as $name=>$value)$f['authority']->setting($name,$value);
        $workflow=new PrintifyApiReadWorkflow();$plan=PrintifyApiApprovalFixture::checked($workflow->prepare('personalized_pod',$f['args'][0]->integrationId,'PRODUCT_READ',['product_id'=>'managed-provider-product','blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[203]]));$auth=$f['authority']->approve($workflow,$plan,'printify-api-read');
        $body=['id'=>'managed-provider-product','shop_id'=>$f['args'][0]->providerShopId,'blueprint_id'=>101,'print_provider_id'=>2,'variants'=>[['id'=>203,'price'=>1899,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[203],'placeholders'=>[['position'=>'front','images'=>[]]]]],'images'=>[]];
        $receipt=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($body)))->executeApiRead($plan['hash'],$auth['hash']));\DigiForge\Core\Settings::protectProduction();
        $args=$f['args'];$args[5][0]['product_source_receipt']=$receipt['hash'];$denied=NativeBusinessOrderEvidence::prepareEvidence(...$args);self::assertWPError($denied);self::assertSame('printify_native_order_catalog_variant_unavailable',$denied->get_error_code());
        global $wpdb;$sourceKey='digiforge_api_read_receipt_'.$f['args'][3];$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$sourceKey));self::assertIsString($raw);
        try{$wpdb->update($wpdb->options,['option_value'=>'{}'],['option_name'=>$sourceKey]);self::assertWPError(NativeBusinessOrderEvidence::prepareEvidence(...$f['args']));}finally{$wpdb->update($wpdb->options,['option_value'=>$raw],['option_name'=>$sourceKey]);}
        $foreign=$this->foreignProductReceipt($f);$args=$f['args'];$args[5][0]['product_source_receipt']=$foreign;$denied=NativeBusinessOrderEvidence::prepareEvidence(...$args);self::assertWPError($denied);self::assertSame('printify_native_order_product_source_scope',$denied->get_error_code());
    }
    public function testRevokedProductObservationInvalidatesTheActualConfirmedLocalVersion():void
    {
        $f=$this->fixture();$args=$this->reviewed($f);$this->installVersions();$version=(new NativeBusinessVersionRepository())->recordReviewedVersion(...[...$args,0,self::ZERO]);$version=$this->checkedVersion($version);
        $key='printify-api-read:'.$f['product_plan']['hash'];$policy=PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::approved('policy','personalized_pod',$key));
        PortalAccessFixture::login($f['authority']->reviewer);PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::decide('policy','personalized_pod',$key,$policy['revision'],$policy['proposal_hash'],'REVOKED',wp_generate_uuid4()));$f['authority']->owner();
        self::assertWPError(NativeBusinessOrderEvidence::prepareEvidence(...$f['args']));self::assertWPError((new NativeBusinessVersionRepository())->readCurrent(...$args));self::assertWPError((new NativeBusinessVersionRepository())->recordReviewedVersion(...[...$args,1,$version['local_business_version_hash']]));
        global $wpdb;self::assertSame('1',$wpdb->get_var('SELECT COUNT(*) FROM '.NativeBusinessVersionSchema::table()));self::assertSame('CONFIRMED',$wpdb->get_var('SELECT confirmation_state FROM '.NativeBusinessVersionSchema::table()));
    }
    /** @dataProvider permissionPhases */
    public function testPermissionLossBeforeEitherOwnedPhaseCannotConfirmAProtectedVersion(int $phase):void
    {
        $f=$this->fixture();$args=$this->reviewed($f);$this->installVersions();$starts=0;$revoked=false;
        $revoke=static function(string $sql)use(&$starts,&$revoked,$phase,$f):string{if($sql==='START TRANSACTION'&&++$starts===$phase){$revoked=true;(new WP_User($f['reviewer']))->add_cap('manage_digiforge_orders',false);}return $sql;};
        add_filter('query',$revoke);try{$result=(new NativeBusinessVersionRepository())->recordReviewedVersion(...[...$args,0,self::ZERO]);}finally{remove_filter('query',$revoke);}
        self::assertTrue($revoked);self::assertWPError($result);global $wpdb;self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));self::assertSame((string)($phase-1),$wpdb->get_var('SELECT COUNT(*) FROM '.NativeBusinessVersionSchema::table()));
        if($phase===2){self::assertSame('UNKNOWN',$result->get_error_data()['outcome']);self::assertSame('PENDING',$wpdb->get_var('SELECT confirmation_state FROM '.NativeBusinessVersionSchema::table()));}
        self::assertSame([],WP_Session_Tokens::get_instance($f['reviewer'])->get_all());
    }
    public static function permissionPhases():array{return [[1],[2]];}
    public function testContradictoryCommitAndCallerFrameAreNotReportedAsSuccessfulVersions():void
    {
        $f=$this->fixture();$args=$this->reviewed($f);$this->installVersions();global $wpdb;$primary=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$pending=wp_generate_uuid4();
        try{
            $primary->query('START TRANSACTION');self::assertSame(1,$primary->insert(Tables::audit_log(),['event_type'=>'managed_producer_caller_pending','actor_id'=>1,'object_type'=>'order','object_id'=>$pending,'context'=>'{}','created_at'=>current_time('mysql',true)]));$row=(int)$primary->insert_id;$commands=[];
            $observe=static function(string $sql)use(&$commands):string{if(preg_match('/^(START TRANSACTION|COMMIT|ROLLBACK)/',$sql))$commands[]=$sql;return $sql;};add_filter('query',$observe);try{$denied=(new NativeBusinessVersionRepository())->recordReviewedVersion(...[...$args,0,self::ZERO]);}finally{remove_filter('query',$observe);}
            self::assertWPError($denied);self::assertSame([],$commands);self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'));self::assertSame('{}',$primary->get_var($primary->prepare('SELECT context FROM '.Tables::audit_log().' WHERE id=%d',$row)));self::assertNull($other->get_var($other->prepare('SELECT context FROM '.Tables::audit_log().' WHERE id=%d',$row)));$primary->query('ROLLBACK');
            $issued=false;$rolled=false;$fault=static function(string $sql)use(&$issued,&$rolled):string{if(str_starts_with($sql,'INSERT INTO `'.Tables::audit_log().'`')&&str_contains($sql,'local_business_order_version_recorded'))$issued=true;if($issued&&!$rolled&&$sql==='COMMIT'){$rolled=true;return 'ROLLBACK';}return $sql;};
            add_filter('query',$fault);try{$result=(new NativeBusinessVersionRepository())->recordReviewedVersion(...[...$args,0,self::ZERO]);}finally{remove_filter('query',$fault);}
            self::assertTrue($rolled);self::assertWPError($result);self::assertSame('UNKNOWN',$result->get_error_data()['outcome']);self::assertSame('0',$primary->get_var('SELECT COUNT(*) FROM '.NativeBusinessVersionSchema::table()));self::assertFalse($result->get_error_data()['retry_permitted']);
        }finally{$primary->query('ROLLBACK');$other->close();}
    }
    public function testFixedCanonicalBusinessFixtureRestoresIncomingIdentityExactly():void
    {
        global $wpdb;$table=Tables::businesses();
        $original=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE business_key=%s','digicraftifygoods'),ARRAY_A);
        self::assertIsArray($original);
        $nested=new PrintifyApiApprovalFixture();$failure=null;
        try{
            $nested->boot('digicraftifygoods');
            $current=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE business_key=%s','digicraftifygoods'),ARRAY_A);
            self::assertIsArray($current);self::assertNotSame($original['id'],$current['id'],'A prior business must not silently become the new fixture authority.');
        }catch(\Throwable $error){$failure=$error->getMessage();}
        finally{$nested->restore();}
        self::assertSame($original,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',(int)$original['id']),ARRAY_A),'The original business ID and every field must survive the nested fixture.');
        self::assertNull($failure,'Fixed-identity fixture must isolate incoming rows before boot: '.($failure??''));
    }

    public function testPublishedCanonicalReceiptRejectsMissingMalformedAndSubstitutedCurrentnessEvidence():void
    {
        $f=$this->helper('managedApprovedPhoto');
        $selected=PrintifyApiApprovalFixture::checked((new \DigiForge\POD\CustomerPhotoSelection())->resolveForOrder('personalized_pod',$f['order'],$f['line']));
        $material=PrintifyApiApprovalFixture::checked((new ReflectionMethod(PersonalizationReadContext::class,'readMaterial'))->invoke(null,$f['order'],false));
        $project=static fn(array $candidate)=>PersonalizationCurrentness::projectRetainedMaterial($material,[$f['line']=>$candidate],true);
        self::assertNotWPError($project($selected),'Actual published owner receipt must compose with current canonical material.');
        $proof=$selected['review_provenance'];
        self::assertNotSame($proof['reviewer_user_id'],$proof['canonical_reviewed_by']);
        foreach(['canonical_review_status','canonical_reviewed_by','canonical_reviewed_at']as $field){
            $bad=$selected;unset($bad['review_provenance'][$field]);
            self::assertWPError($project($bad),'Missing public canonical identity must not be inferred: '.$field);
        }
        foreach([
            ['canonical_review_status','REJECTED'],
            ['canonical_reviewed_by',$proof['reviewer_user_id']],
            ['canonical_reviewed_by',(string)$proof['canonical_reviewed_by']],
            ['canonical_reviewed_by',0],
            ['canonical_reviewed_at','2001-01-01 00:00:00'],
            ['canonical_reviewed_at',0],
            ['version','shop2-photo-review-proof-unknown'],
            ['selection_sha256',str_repeat('f',64)],
            ['selection_revision',$selected['revision']+1],
            ['review_sha256','unverified-review'],
        ]as [$field,$value]){
            $bad=$selected;$bad['review_provenance'][$field]=$value;
            self::assertWPError($project($bad),'Tampered copies of the real owner receipt must deny: '.$field);
        }
    }

    public function testActualCanonicalReviewMetadataAbaCannotMixPublicReceiptAndLockedMaterial():void
    {
        $f=$this->helper('managedApprovedPhoto');global $wpdb;$primary=$wpdb;
        $table=Tables::personalization_submissions();$id=$f['selection']['submission_id'];
        $original=$primary->get_row($primary->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id),ARRAY_A);
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$other->suppress_errors(true);
        self::assertNotSame($primary->get_var('SELECT CONNECTION_ID()'),$other->get_var('SELECT CONNECTION_ID()'));
        $candidate='2001-01-01 00:00:00';self::assertNotSame($candidate,$original['reviewed_at']);
        self::assertSame(1,$other->update($table,['reviewed_at'=>$candidate],['id'=>$id]));
        self::assertWPError((new \DigiForge\POD\CustomerPhotoSelection())->resolveForOrder('personalized_pod',$f['order'],$f['line']));
        $target=$primary->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id);$repaired=false;$restored=false;$context=null;
        $race=static function(string $sql)use($other,$primary,$table,$id,$original,$candidate,$target,&$repaired,&$restored):string{
            if(!$repaired&&$sql===$target){$repaired=true;if($other->update($table,['reviewed_at'=>$original['reviewed_at']],['id'=>$id])!==1)throw new RuntimeException('Canonical producer-read repair failed.');}
            if($repaired&&!$restored&&str_starts_with($sql,'SELECT ENGINE FROM information_schema.TABLES')&&str_contains($sql,\DigiForge\NativeAuthority\WordPress\OfflineSchema::generationTable($primary))){$restored=true;if($other->update($table,['reviewed_at'=>$candidate],['id'=>$id])!==1)throw new RuntimeException('Canonical metadata ABA restoration failed.');}
            return $sql;
        };
        add_filter('query',$race);
        try{
            $context=PersonalizationReadContext::capture('personalized_pod',$f['order']);
            if(is_wp_error($context))$result=$context;
            else{$primary->query('START TRANSACTION');$result=$context->forOrder($f['order']);}
            self::assertTrue($repaired);self::assertTrue($restored);
            self::assertSame($candidate,$primary->get_var($primary->prepare('SELECT reviewed_at FROM '.$table.' WHERE id=%d',$id)));
            self::assertWPError($result,'Exact current locked canonical review must match the actual immutable public owner receipt.');
            self::assertSame('personalization_currentness_selection_review_changed',$result->get_error_code());
        }finally{
            remove_filter('query',$race);$primary->query('ROLLBACK');if($context instanceof PersonalizationReadContext)$context->release(false);
            $other->update($table,['reviewed_at'=>$original['reviewed_at']],['id'=>$id]);$other->close();
        }
    }

    public function testReviewerRevocationAfterConfirmedCommitDeniesPrivateVersionResponse():void
    {
        $f=$this->fixture();$args=$this->reviewed($f);$this->installVersions();$confirmed=false;$released=false;$revoked=false;$releases=0;
        $fault=static function(string $sql)use(&$confirmed,&$released,&$revoked,&$releases,$f):string{
            if(str_starts_with($sql,'INSERT INTO `'.Tables::audit_log().'`')&&str_contains($sql,'local_business_order_version_confirmed'))$confirmed=true;
            if($confirmed&&str_contains($sql,'digiforge_reviewer_authority_leases')&&str_contains($sql,"SET state='RELEASED'"))$released=true;
            if($released&&str_starts_with($sql,'SELECT RELEASE_LOCK(')&&str_contains($sql,'df_bov_')&&++$releases===2){$revoked=true;(new WP_User($f['reviewer']))->add_cap('manage_digiforge_orders',false);}return $sql;
        };
        add_filter('query',$fault);try{$result=(new NativeBusinessVersionRepository())->recordReviewedVersion(...[...$args,0,self::ZERO]);}finally{remove_filter('query',$fault);}
        self::assertTrue($confirmed);self::assertTrue($released);self::assertTrue($revoked);self::assertSame(2,$releases);self::assertWPError($result);self::assertContains($result->get_error_data()['reason']??null,['response_source','response_reviewer_authority']);self::assertSame('UNKNOWN',$result->get_error_data()['outcome']);global $wpdb;self::assertSame('CONFIRMED',$wpdb->get_var('SELECT confirmation_state FROM '.NativeBusinessVersionSchema::table()));self::assertSame('1',$wpdb->get_var('SELECT COUNT(*) FROM '.NativeBusinessVersionSchema::table()));self::assertFalse($result->get_error_data()['retry_permitted']);
    }

}
