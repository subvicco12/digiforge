<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/NativeBusinessOrderFixture.php';

use DigiForge\Database\Tables;
use DigiForge\Orders\Repository;
use DigiForge\Portal\OrderArtifactInspection;

/** Stored metadata inspection is separate from byte certification or production approval. */
final class OrderArtifactInspectionTest extends WP_UnitTestCase
{
    use NativeBusinessOrderFixture;

    protected function setUp(): void
    {
        parent::setUp();\DigiForge\Core\Activator::activate();$this->bootOrderFixture();global $wpdb;
        foreach([Tables::pod_render_evidence(),Tables::pod_authorization_packages()] as $table)$this->rows[$table]=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);
    }

    protected function tearDown(): void
    {
        try {$this->restoreOrderFixture();} finally {parent::tearDown();}
    }

    public function testExactStoredOrderRenderPackageInspectionReturnsOnlyLocalMetadata(): void
    {
        self::assertTrue(class_exists(OrderArtifactInspection::class),'Order-scoped stored artifact inspection must exist.');
        [$order,$render,$package]=$this->inspectionFixture();$http=0;$queries=[];
        $record=static function(string $sql)use(&$queries):string{$queries[]=$sql;return $sql;};
        $deny=static function()use(&$http){$http++;return new WP_Error('unexpected_http');};
        add_filter('query',$record);add_filter('pre_http_request',$deny);
        try {$result=(new OrderArtifactInspection())->inspect('personalized_pod',$order,$render,$package);}
        finally {remove_filter('query',$record);remove_filter('pre_http_request',$deny);}
        self::assertNotWPError($result);self::assertSame($order,$result['order_id']);self::assertSame($render,$result['render']['id']);self::assertSame($package,$result['package']['id']);
        self::assertSame('CURRENT_TYPED_INPUT',$result['current_input_state']);self::assertSame('NOT_PERFORMED',$result['artifact_certification']);
        self::assertSame('UNREVIEWED',$result['render']['review_status']);self::assertTrue($result['release_hold']);self::assertFalse($result['external_execution_authorized']);self::assertFalse($result['production_ready']);
        self::assertStringNotContainsString('Private buyer values',json_encode($result));self::assertStringNotContainsString('canonical_payload',json_encode($result));self::assertArrayNotHasKey('preview_url',$result);self::assertSame(0,$http);
        foreach($queries as $query)self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i',$query);
    }

    public function testForeignOrderRenderPackageAndExecutionClaimsFailClosed(): void
    {
        [$order,$render,$package]=$this->inspectionFixture();$service=new OrderArtifactInspection();global $wpdb;
        self::assertWPError($service->inspect('digital',$order,$render,$package));self::assertWPError($service->inspect('all',$order,$render,$package));
        self::assertWPError($service->inspect('personalized_pod',$order+100000,$render,$package));self::assertWPError($service->inspect('personalized_pod',$order,$render+100000,$package));
        foreach(['order_id'=>$order+100000,'render_evidence_id'=>$render+100000,'provider_mapping_id'=>100000,'ownership_mapping_id'=>100000,'external_execution_authorized'=>1,'external_execution_performed'=>1] as $field=>$value){
            $old=$wpdb->get_var($wpdb->prepare("SELECT $field FROM ".Tables::pod_authorization_packages().' WHERE id=%d',$package));self::assertSame(1,$wpdb->update(Tables::pod_authorization_packages(),[$field=>$value],['id'=>$package]));
            try {self::assertWPError($service->inspect('personalized_pod',$order,$render,$package),$field);}
            finally {$wpdb->update(Tables::pod_authorization_packages(),[$field=>$old],['id'=>$package]);}
        }
    }

    public function testNewerBuyerInputIsReportedBlockedWithoutCertifyingHistoricalArtifact(): void
    {
        [$order,$render,$package,$submission]=$this->inspectionFixture();$repo=new Repository();
        $second=$repo->createPersonalization(['order_line_item_id'=>$submission['order_line_item_id'],'personalization_schema_id'=>$submission['personalization_schema_id'],'payload'=>['name'=>'Corrected buyer values']]);self::assertNotWPError($second);
        self::assertNotWPError($repo->reviewPersonalization((int)$second['id'],'APPROVED'));
        $result=(new OrderArtifactInspection())->inspect('personalized_pod',$order,$render,$package);self::assertNotWPError($result);
        self::assertSame('BLOCKED',$result['current_input_state']);self::assertContains('CURRENT_BUYER_INPUT_REQUIRED',$result['blockers']);self::assertSame('NOT_PERFORMED',$result['artifact_certification']);self::assertFalse($result['production_ready']);
    }

    public function testUnavailableOrChangedSelectedEvidenceCannotReturnAnInspection(): void
    {
        [$order,$render,$package]=$this->inspectionFixture();$service=new OrderArtifactInspection();global $wpdb;
        $fault=static fn(string $sql):string=>str_starts_with($sql,'SELECT id,order_id,provider_mapping_id,template_key')?'SELECT * FROM order_artifact_missing_evidence':$sql;
        $prior=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try {$failure=$service->inspect('personalized_pod',$order,$render,$package);self::assertWPError($failure);self::assertSame(503,$failure->get_error_data()['status']);}
        finally {remove_filter('query',$fault);$wpdb->suppress_errors($prior);}
        $reads=0;$mutate=static function(string $sql)use(&$reads,$render,$wpdb):string{
            if(str_starts_with($sql,'SELECT id,order_id,provider_mapping_id,template_key')&&++$reads===2)$wpdb->update(Tables::pod_render_evidence(),['template_version'=>'2'],['id'=>$render]);return $sql;
        };
        add_filter('query',$mutate);try {self::assertWPError($service->inspect('personalized_pod',$order,$render,$package));} finally {remove_filter('query',$mutate);}
    }

    public function testModuleDenialCannotRenderPrivateOrderMetadata(): void
    {
        [$order,$render,$package]=$this->inspectionFixture();
        PrintifyApiApprovalFixture::checked(\DigiForge\PortalAccess\Store::change($this->fixture->reviewer,'Viewer',['personalized_pod'=>['research']],true,0,wp_generate_uuid4()));PortalAccessFixture::login($this->fixture->reviewer);
        self::assertTrue(current_user_can('manage_digiforge_orders'));$service=new OrderArtifactInspection();self::assertWPError($service->inspect('personalized_pod',$order,$render,$package));
        ob_start();$service->render('personalized_pod',$order,$render,$package);$html=(string)ob_get_clean();self::assertStringNotContainsString('stored-inspection-template',$html);self::assertStringNotContainsString('Private buyer values',$html);
    }

    public function testMountedOrderDetailShowsEscapedMetadataAndStaleBlockerWithoutPreviewUrl(): void
    {
        [$order,$render,$package,$submission]=$this->inspectionFixture();global $wpdb;$before=$_GET;
        self::assertSame(1,$wpdb->update(Tables::orders(),['external_order_reference'=>'stored&private'],['id'=>$order]));
        $_GET=['df_view'=>'orders','df_shop'=>'personalized_pod','df_order_id'=>(string)$order,'df_render_id'=>(string)$render,'df_package_id'=>(string)$package];
        try {
            ob_start();(new \DigiForge\Portal\OrderHeldPreparationPanel())->render('personalized_pod');$html=(string)ob_get_clean();
            self::assertStringContainsString('Order artifact inspection',$html);self::assertStringContainsString('stored-inspection-template',$html);self::assertStringContainsString('stored&amp;private',$html);self::assertStringNotContainsString('stored&private',$html);
            self::assertStringContainsString('NOT_PERFORMED',$html);self::assertStringNotContainsString('content_base64',$html);self::assertStringNotContainsString('df_render_panel',$html);
            foreach(['df_package_id','df_order_id','df_render_id','df_shop'] as $field)self::assertStringContainsString('name="'.$field.'"',$html);
            self::assertNotWPError((new Repository())->createPersonalization(['order_line_item_id'=>$submission['order_line_item_id'],'personalization_schema_id'=>$submission['personalization_schema_id'],'payload'=>['name'=>'Unresolved correction']]));
            ob_start();(new \DigiForge\Portal\OrderHeldPreparationPanel())->render('personalized_pod');$stale=(string)ob_get_clean();
            self::assertStringContainsString('Current buyer input: BLOCKED',$stale);self::assertStringNotContainsString('content_base64',$stale);self::assertStringNotContainsString('df_render_panel',$stale);
        } finally {$_GET=$before;}
    }

    public function testMalformedArtifactGetTargetsCannotRevealSelectedMetadata(): void
    {
        [$order,$render,$package]=$this->inspectionFixture();$before=$_GET;
        try {
            foreach([['df_render_id'=>[]],['df_render_id'=>'0'],['df_render_id'=>'2147483648'],['df_package_id'=>[]],['df_package_id'=>'0'],['df_package_id'=>'2147483648']] as $bad){
                $_GET=$bad+['df_order_id'=>(string)$order,'df_render_id'=>(string)$render,'df_package_id'=>(string)$package];
                ob_start();(new \DigiForge\Portal\OrderHeldPreparationPanel())->render('personalized_pod');$html=(string)ob_get_clean();
                self::assertStringNotContainsString('Recorded output hash',$html);self::assertStringNotContainsString('Current buyer input: CURRENT_TYPED_INPUT',$html);
            }
        } finally {$_GET=$before;}
        self::assertWPError((new OrderArtifactInspection())->inspect('personalized_pod',$order,2147483648,$package));
    }

    public function testFinalActorAndModuleAdmissionAreRecheckedAfterSelectedMetadataReads(): void
    {
        [$order,$render,$package]=$this->inspectionFixture();$actor=get_current_user_id();$reads=0;
        $logout=static function(string $sql)use(&$reads):string{
            if(str_starts_with($sql,'SELECT id,order_id,provider_mapping_id,template_key')&&++$reads===2)wp_set_current_user(0);
            return $sql;
        };
        add_filter('query',$logout);
        try {$loggedOut=(new OrderArtifactInspection())->inspect('personalized_pod',$order,$render,$package);}
        finally {remove_filter('query',$logout);wp_set_current_user($actor);}
        $actor=self::factory()->user->create(['role'=>'administrator']);
        PrintifyApiApprovalFixture::checked(\DigiForge\PortalAccess\Store::change($actor,'Operator',['personalized_pod'=>['orders','personalized_pod','integrations']],true,0,wp_generate_uuid4()));
        PortalAccessFixture::login($actor);self::assertNotWPError(\DigiForge\PortalAccess\Session::start());
        $reads=0;$revoked=false;$error=null;$cookies=$_COOKIE;$fixture=$this->fixture;
        $revoke=static function(string $sql)use(&$reads,&$revoked,&$error,$actor,$cookies,$fixture):string{
            if(str_starts_with($sql,'SELECT id,order_id,provider_mapping_id,template_key')&&++$reads===2){
                $fixture->owner();
                $result=\DigiForge\PortalAccess\Store::change($actor,'Viewer',['personalized_pod'=>['research']],true,\DigiForge\PortalAccess\Store::read($actor)['revision'],wp_generate_uuid4());
                $revoked=!is_wp_error($result);$error=is_wp_error($result)?$result->get_error_code():null;
                wp_set_current_user($actor);$_COOKIE=$cookies;
            }
            return $sql;
        };
        add_filter('query',$revoke);
        try {$moduleRevoked=(new OrderArtifactInspection())->inspect('personalized_pod',$order,$render,$package);}
        finally {remove_filter('query',$revoke);$this->fixture->owner();require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($actor);}
        self::assertSame(2,$reads,is_wp_error($moduleRevoked)?$moduleRevoked->get_error_code().': '.$moduleRevoked->get_error_message():'Metadata returned.');self::assertTrue($revoked,(string)$error);
        self::assertSame(['logout'=>false,'orders_module_removed'=>false],['logout'=>!is_wp_error($loggedOut),'orders_module_removed'=>!is_wp_error($moduleRevoked)],'Neither logout nor removal of the current actor orders module may release metadata after the final selected-row read.');
    }

    private function inspectionFixture(): array
    {
        [, $mapping,$version,$ownership]=$this->product();$repo=new Repository();
        $order=PrintifyApiApprovalFixture::checked($repo->createOrder(['channel'=>'etsy','environment'=>'test','shop_reference'=>'personalized_pod','personalization_required'=>true]));
        $line=PrintifyApiApprovalFixture::checked($repo->addLineItem(['order_id'=>$order['id'],'product_version_id'=>$version,'provider_mapping_id'=>$mapping]));
        $pod=new \DigiForge\POD\Repository();$schema=PrintifyApiApprovalFixture::checked($pod->createPersonalizationSchema(['product_version_id'=>$version,'schema_key'=>'inspection-input','version_label'=>'v1','field_definitions'=>[['key'=>'name','type'=>'text','required'=>true]]]));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)PrintifyApiApprovalFixture::checked($pod->transition('personalization',(int)$schema['id'],$state));
        $submission=PrintifyApiApprovalFixture::checked($repo->createPersonalization(['order_line_item_id'=>$line['id'],'personalization_schema_id'=>$schema['id'],'payload'=>['name'=>'Private buyer values']]));PrintifyApiApprovalFixture::checked($repo->reviewPersonalization((int)$submission['id'],'APPROVED'));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)PrintifyApiApprovalFixture::checked($repo->transition('order',(int)$order['id'],$state));
        // The genuine repository stores asserted hashes; no artifact bytes/certification are supplied by this fixture.
        $render=PrintifyApiApprovalFixture::checked((new \DigiForge\POD\RenderEvidenceRepository())->create(['order_id'=>$order['id'],'provider_mapping_id'=>$mapping,'template_key'=>'stored-inspection-template','template_version'=>'1','template_sha256'=>hash('sha256','inspection-template'),'personalization_evidence_hash'=>$submission['payload_hash'],'output_sha256'=>hash('sha256','asserted-only-output'),'buyer_preview_sha256'=>hash('sha256','asserted-only-output'),'render_mode'=>'DETERMINISTIC']));
        $readiness=PrintifyApiApprovalFixture::checked($repo->readinessSnapshot((int)$order['id']));$readinessHash=hash('sha256',wp_json_encode($readiness));
        global $wpdb;self::assertSame(1,$wpdb->insert(Tables::pod_authorization_packages(),['order_id'=>$order['id'],'render_evidence_id'=>$render['id'],'provider_mapping_id'=>$mapping,'ownership_mapping_id'=>$ownership,'readiness_hash'=>$readinessHash,'package_hash'=>hash('sha256','stored-inspection-package:'.$order['id'].':'.$render['id']),'state'=>'REVIEW_REQUIRED','external_execution_authorized'=>0,'external_execution_performed'=>0,'created_at'=>current_time('mysql',true)]));
        return [(int)$order['id'],(int)$render['id'],(int)$wpdb->insert_id,$submission];
    }
}
