<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ExistingRenderArtifactFixture.php';
require_once __DIR__.'/PortalActionAcceptanceFixture.php';
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\POD\ProductionAuthorizationRepository;
use DigiForge\POD\PersonalizedPodOperationsReadModel;
use DigiForge\POD\ExecutionAuthorization;
use DigiForge\POD\ExecutionAuthorizationVerifier;
use DigiForge\POD\ExecutionNonceLedger;

/** Actual synthetic personalization -> artifacts -> portal queue -> human review. No production operation. */
final class ComposedPersonalizedOrderAcceptanceTest extends WP_UnitTestCase
{
    private array $paths=[];
    private Closure $http;
    private int $calls=0;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();digiforge_reset_existing_render_fixture();
        $this->http=function(){++$this->calls;return new WP_Error('offline_composed_order','No actual HTTP.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        foreach($this->paths as $path){if(is_file($path))unlink($path);}remove_filter('pre_http_request',$this->http);
        self::assertSame(0,$this->calls);self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all'));self::assertFalse(Settings::get('automation_armed'));parent::tearDown();
    }
    private function html(string $shop='personalized_pod'): string
    {
        $get=$_GET;try{$_GET=['df_view'=>'pod_personalized','df_shop'=>$shop];return (new DigiForge\Portal\Portal())->render();}finally{$_GET=$get;}
    }
    private function export(string $name,string $html): void
    {
        $dir=getenv('DF_COMPOSED_PORTAL_FIXTURE_DIR');if(is_string($dir)&&$dir!=='')self::assertSame(strlen($html),file_put_contents($dir.'/'.$name.'.html',$html));
    }
    private function prepared(): array
    {
        $input=digiforge_existing_render_fixture('deterministicFixture');global $wpdb;
        $submission=(int)$wpdb->get_var($wpdb->prepare('SELECT ps.id FROM '.Tables::personalization_submissions().' ps INNER JOIN '.Tables::order_line_items().' li ON li.id=ps.order_line_item_id WHERE li.order_id=%d',$input['order_id']));
        self::assertSame(1,$wpdb->update(Tables::personalization_submissions(),['review_status'=>'UNREVIEWED','reviewed_by'=>0,'reviewed_at'=>null],['id'=>$submission]));
        PortalActionAcceptanceFixture::invoke('PERSONALIZATION_REVIEW_ACTION',['submission_id'=>$submission,'decision'=>'APPROVED']);
        $renderer=new DigiForge\POD\DeterministicPersonalizationRenderer();self::assertNotWPError($renderer->approveRecipe($input['template_key'],1,digiforge_existing_render_fixture('recipe')));
        $expected=$renderer->expected($input);self::assertNotWPError($expected);
        $path=tempnam(sys_get_temp_dir(),'df-composed-order-');$this->paths[]=$path;self::assertSame(strlen($expected['svg']),file_put_contents($path,$expected['svg']));
        $record=(new DigiForge\POD\RenderEvidenceRepository())->createFromArtifacts($input,$path,$path);self::assertNotWPError($record);
        $html=$this->html();self::assertStringContainsString('Buyer-specific artwork & mockup review',$html);self::assertStringContainsString('name="render_evidence_id" value="'.$record['id'].'"',$html);
        PortalActionAcceptanceFixture::invoke('POD_RENDER_REVIEW_ACTION',['render_evidence_id'=>$record['id']]);
        $package=(new ProductionAuthorizationRepository())->create($input['order_id'],(int)$record['id']);self::assertNotWPError($package);self::assertSame('REVIEW_REQUIRED',$package['state']);
        return ['input'=>$input,'render'=>$record,'package'=>$package];
    }
    private function approve(array $fixture): array
    {
        PortalActionAcceptanceFixture::invoke('POD_PACKAGE_REVIEW_ACTION',['package_id'=>$fixture['package']['id']]);global $wpdb;
        $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$fixture['package']['id']),ARRAY_A);
        self::assertSame('APPROVED_PACKAGE',$package['state']);self::assertSame(0,(int)$package['external_execution_authorized']);self::assertSame(0,(int)$package['external_execution_performed']);return $package;
    }
    public function testPreparedPersonalizationEntersOnlySelectedQueueAndActualPortalReviewPersistsOnce(): void
    {
        $f=$this->prepared();$id=(int)$f['package']['id'];$snapshot=(new PersonalizedPodOperationsReadModel())->snapshot(50,'personalized_pod');self::assertSame('AVAILABLE',$snapshot['query_state']);self::assertContains($id,array_column($snapshot['items'],'package_id'));
        $before=$this->html();self::assertStringContainsString('name="package_id" value="'.$id.'"',$before);$this->export('package-pending',$before);$dir=getenv('DF_COMPOSED_PORTAL_FIXTURE_DIR');if(is_string($dir)&&$dir!=='')file_put_contents($dir.'/journey.json',wp_json_encode(['package_id'=>$id,'shop'=>'personalized_pod','evidence_kind'=>'SYNTHETIC_NATIVE_FIXTURE']));
        foreach(['digital','standard_pod','jewelry'] as $shop){self::assertNotContains($id,array_column((new PersonalizedPodOperationsReadModel())->snapshot(50,$shop)['items'],'package_id'));self::assertStringNotContainsString('name="package_id" value="'.$id.'"',$this->html($shop));}
        $approved=$this->approve($f);$after=$this->html();self::assertStringContainsString('APPROVED_PACKAGE',$after);self::assertStringNotContainsString('name="package_id" value="'.$id.'"',$after);$this->export('package-approved',$after);
        PortalActionAcceptanceFixture::invoke('POD_PACKAGE_REVIEW_ACTION',['package_id'=>$id],true);
        global $wpdb;self::assertSame($approved,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$id),ARRAY_A));
        self::assertGreaterThan(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s','pod_authorization_package',(string)$id)));
    }
    public function testForeignAndRevokedOperatorsCannotAcceptPreparedPackage(): void
    {
        $f=$this->prepared();$author=get_current_user_id();$foreign=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($foreign,['digital'],['manage_digiforge','manage_digiforge_pod','manage_digiforge_orders']);wp_set_current_user($foreign);
        PortalActionAcceptanceFixture::invoke('POD_PACKAGE_REVIEW_ACTION',['package_id'=>$f['package']['id']],true);
        self::assertSame('UNAVAILABLE',(new PersonalizedPodOperationsReadModel())->snapshot(50,'personalized_pod')['query_state']);
        $denied=$this->html();self::assertStringNotContainsString('name="package_id"',$denied);$this->export('package-denied',$denied);
        wp_set_current_user($author);ShopAccessFixture::provision([]);PortalActionAcceptanceFixture::invoke('POD_PACKAGE_REVIEW_ACTION',['package_id'=>$f['package']['id']],true);
        global $wpdb;self::assertSame('REVIEW_REQUIRED',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$f['package']['id'])));
    }
    public function testProviderIdentityDriftInvalidatesQueueReadinessWithoutRewritingReviewHistory(): void
    {
        $f=$this->prepared();$approved=$this->approve($f);global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::pod_mappings(),['provider_variant_key'=>'999:999'],['id'=>$f['input']['provider_mapping_id']]));
        $rows=(new PersonalizedPodOperationsReadModel())->snapshot();$own=array_values(array_filter($rows['items'],static fn($r)=>$r['package_id']===(int)$approved['id']));self::assertCount(1,$own);self::assertSame('REVALIDATION_REQUIRED',$own[0]['operator_state']);self::assertFalse($own[0]['external_execution_authorized']);
        self::assertSame($approved,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$approved['id']),ARRAY_A));
        self::assertWPError((new ProductionAuthorizationRepository())->approveForReview((int)$approved['id']));$this->export('package-drift',$this->html());
    }
    public function testMockupPanelAndExplicitDisplayRightsCannotBeReboundByRehashing(): void
    {
        $f=$this->prepared();
        // The preparation already reviewed this render; retrieve immutable stored evidence.
        global $wpdb;$render=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE id=%d',$f['render']['id']),ARRAY_A);
        $spec=digiforge_existing_render_fixture('mockupRecipeFixture',[$render,'SOCKS',1]);$path=DigiForge\ProductFactory\AssetStorage::absolutePath($spec['reference']);$original=file_get_contents($path);$recipe=json_decode($original,true);$registry=new DigiForge\POD\ProductMockupRegistry();
        $bad=$recipe;$bad['panel']='unapproved_panel';file_put_contents($path,wp_json_encode($bad));self::assertWPError($registry->intake($spec['reference'],hash_file('sha256',$path),(int)$render['id'],null));
        $rightsPath=DigiForge\ProductFactory\AssetStorage::absolutePath($recipe['rights_reference']);$originalRights=file_get_contents($rightsPath);
        try {foreach(['customer_display_permitted','derivative_projection_permitted'] as $right){$rights=json_decode($originalRights,true);$rights[$right]=false;file_put_contents($rightsPath,wp_json_encode($rights));$bad=$recipe;$bad['rights_sha256']=hash_file('sha256',$rightsPath);file_put_contents($path,wp_json_encode($bad));self::assertWPError($registry->intake($spec['reference'],hash_file('sha256',$path),(int)$render['id'],null));}}
        finally {file_put_contents($path,$original);file_put_contents($rightsPath,$originalRights);}
    }
    public function testReviewedPackageCannotBypassProductionActionScopeExpiryReplayOrGlobalLocks(): void
    {
        $f=$this->prepared();$package=$this->approve($f);$actor=get_current_user_id();$fingerprint=hash('sha256','SYNTHETIC NO PROVIDER REQUEST '.$package['package_hash']);
        $approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>$package['package_hash'],'approved_by'=>$actor,'action'=>'PROVIDER_PRODUCTION_AUTHORIZE','request_fingerprint'=>$fingerprint];
        $nonce=str_replace('-','_',wp_generate_uuid4());$authorization=ExecutionAuthorization::issue($approval,'PROVIDER_PRODUCTION_AUTHORIZE',$actor,$nonce,60,$fingerprint);self::assertNotWPError($authorization);
        self::assertTrue(ExecutionAuthorizationVerifier::verify($authorization,'PROVIDER_PRODUCTION_AUTHORIZE',$package['package_hash'],time(),[ExecutionNonceLedger::class,'unused'],$fingerprint));
        foreach([['PROVIDER_ORDER_SUBMIT',$package['package_hash'],time(),$fingerprint],['PROVIDER_PRODUCTION_AUTHORIZE',hash('sha256','different package'),time(),$fingerprint],['PROVIDER_PRODUCTION_AUTHORIZE',$package['package_hash'],$authorization['authorization']['expires_at'],$fingerprint],['PROVIDER_PRODUCTION_AUTHORIZE',$package['package_hash'],$authorization['authorization']['expires_at']+1,$fingerprint],['PROVIDER_PRODUCTION_AUTHORIZE',$package['package_hash'],time(),hash('sha256','different request')]] as $bad){self::assertWPError(ExecutionAuthorizationVerifier::verify($authorization,$bad[0],$bad[1],$bad[2],[ExecutionNonceLedger::class,'unused'],$bad[3]));}
        $permit=DigiForge\POD\ControlledExecutionGate::authorize($authorization,'PROVIDER_PRODUCTION_AUTHORIZE',$package['package_hash'],$actor,time(),$fingerprint);self::assertNotWPError($permit);self::assertFalse($permit['external_execution_performed']);
        $blocked=DigiForge\POD\PrintifyLiveTransportInterlock::authorize($permit,['action'=>'PROVIDER_PRODUCTION_AUTHORIZE','integration_id'=>1,'request_fingerprint'=>$fingerprint]);self::assertWPError($blocked);self::assertSame('digiforge_printify_interlock_locked',$blocked->get_error_code());
        self::assertWPError(DigiForge\POD\ControlledExecutionGate::authorize($authorization,'PROVIDER_PRODUCTION_AUTHORIZE',$package['package_hash'],$actor,time(),$fingerprint));
    }
}
