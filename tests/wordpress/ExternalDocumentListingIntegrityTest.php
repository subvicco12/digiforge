<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/ExternalDocumentAdmissionTest.php';

use DigiForge\Database\Tables;
use DigiForge\Production\Repository;

/** Synthetic already-reviewed fixture only: no owner approval or commercial acceptance. */
final class ExternalDocumentListingIntegrityTest extends WP_UnitTestCase
{
    private array $inputs=[];
    private int $httpCalls=0;
    private Closure $http;
    private int $scopeOwner;

    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        $this->scopeOwner=ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge','manage_digiforge_products','manage_digiforge_listings']);
        $this->http=function(){++$this->httpCalls;return new WP_Error('external_listing_test_network_denied');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);foreach($this->inputs as $path)@unlink($path);
        self::assertSame(0,$this->httpCalls);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));parent::tearDown();
    }
    private function syntheticReviewedVersion(): array
    {
        // ACL lifecycle commits during the race test can outlive the WP transaction.
        // Give every researched identity a unique name so no later test borrows it.
        $nonce=wp_generate_uuid4();$research=new DigiForge\Research\Repository();
        $source=$research->createSource(['shop_key'=>'digital','name'=>'Synthetic external listing source '.$nonce,'source_type'=>'manual','environment'=>'sandbox']);self::assertNotWPError($source);
        $observation=$research->ingest(['source_id'=>$source['id'],'title'=>'Synthetic external listing observation '.$nonce,'body'=>'Synthetic test only; no marketplace demand or commercial acceptance.']);self::assertNotWPError($observation);
        $evidence=$research->addEvidence((int)$observation['id'],['evidence_type'=>'manual_note','value'=>'Unique synthetic listing lineage '.$nonce]);self::assertNotWPError($evidence);
        $candidate=$research->createCandidate(['shop_key'=>'digital','title'=>'Synthetic external listing candidate '.$nonce]);self::assertNotWPError($candidate);self::assertTrue($research->linkEvidence((int)$candidate['id'],(int)$evidence['id']));
        self::assertNotWPError($research->review((int)$candidate['id'],'APPROVED','Synthetic test fixture only; no owner approval.'));
        $opportunity=$research->promote((int)$candidate['id']);self::assertNotWPError($opportunity);
        $products=new DigiForge\ProductFactory\Repository();
        $family=$products->create('product_family',['opportunity_id'=>$opportunity['id'],'name'=>'Synthetic external listing family '.$nonce]);self::assertNotWPError($family);
        $product=$products->create('product',['product_family_id'=>$family['id'],'name'=>'Synthetic external listing product '.$nonce]);self::assertNotWPError($product);
        $notes=['spec'=>['listing_title_draft'=>'Synthetic external document review '.$nonce,'listing_description_draft'=>'Local technical test fixture only; no live sale or commercial approval.','seo_keywords'=>['synthetic','fixture'],'price_strategy'=>['recommended_etsy_price_usd'=>12],'delivery_selection'=>['selected_language'=>'English']]];
        $version=$products->create('product_version',['product_id'=>$product['id'],'version_label'=>'synthetic-listing-'.$nonce,'notes'=>wp_json_encode($notes)]);self::assertNotWPError($version);
        self::assertNotWPError($products->transition('product_version',(int)$version['id'],'REVIEW'));self::assertNotWPError($products->transition('product_version',(int)$version['id'],'APPROVED'));
        return $version;
    }

    private function fixture(): array
    {
        // Use unique synthetic researched identities and the shared real document generators.
        $version=$this->syntheticReviewedVersion();
        $request=(new ReflectionMethod(ExternalDocumentAdmissionTest::class,'documentRequest'))->invoke(new ExternalDocumentAdmissionTest());
        $this->inputs=[$request['source_path'],$request['export_path']];$production=new Repository();
        $spec=$production->createSpec(['product_version_id'=>$version['id'],'asset_key'=>'external-listing-source','asset_type'=>'digital_document','format'=>'pdf']);self::assertNotWPError($spec);
        $request=array_replace($request,['product_id'=>(int)$version['product_id'],'product_version_id'=>(int)$version['id'],'asset_spec_id'=>(int)$spec['id']]);
        $admitted=(new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request);self::assertNotWPError($admitted);
        $revision=$production->addRevision($admitted['revision_input']);self::assertNotWPError($revision);
        self::assertNotWPError($production->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'external_document_source_completeness','status'=>'PASS','details'=>$admitted['evidence']+['binding'=>$admitted['binding']]]));
        // This test seeds an already-reviewed synthetic bundle through existing lifecycle APIs.
        // It does not claim completeness is sufficient for genuine manual Product Approval.
        foreach(['QA_PASSED','APPROVED'] as $state)self::assertNotWPError($production->transition('revision',(int)$revision['id'],$state));
        $plan=$production->createPlan(['product_version_id'=>$version['id'],'plan_key'=>'synthetic-external-listing','version_label'=>'Synthetic 1','channel'=>'digital','production_type'=>'external_document']);self::assertNotWPError($plan);
        self::assertTrue($production->linkAsset((int)$plan['id'],(int)$spec['id']));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)self::assertNotWPError($production->transition('plan',(int)$plan['id'],$state));
        $bundle=$production->createBundle(['production_plan_id'=>$plan['id'],'bundle_key'=>'synthetic-external-listing','version_label'=>'Synthetic 1']);self::assertNotWPError($bundle);
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)self::assertNotWPError($production->transition('bundle',(int)$bundle['id'],$state));
        $ready=$production->validateBundle((int)$bundle['id']);self::assertNotWPError($ready);self::assertSame('RELEASE_READY',$ready['state']);
        return compact('version','admitted','revision','plan','bundle');
    }
    private function listingCount(int $versionId): int
    {
        global $wpdb;return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::listings().' WHERE product_version_id=%d',$versionId));
    }
    public function testCachedReleaseCannotAdmitDriftedExternalRevisionBeforeListingCreation(): void
    {
        // Reproduce the defect without the new custody service: approved native fixture
        // becomes an invalid external-document revision AFTER cached bundle validation.
        $version=$this->syntheticReviewedVersion();
        $production=new Repository();
        $spec=$production->createSpec(['product_version_id'=>$version['id'],'asset_key'=>'cached-release-drift','asset_type'=>'digital_document','format'=>'pdf']);self::assertNotWPError($spec);
        $revision=$production->addRevision(['asset_spec_id'=>$spec['id'],'revision_label'=>'synthetic-cache-baseline','storage_reference'=>'local://synthetic/cached.png','checksum_sha256'=>hash('sha256','synthetic cached baseline'),'mime_type'=>'image/png','byte_size'=>25,'provenance'=>['origin'=>'synthetic_cached_release_fixture']]);self::assertNotWPError($revision);
        self::assertNotWPError($production->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'synthetic_cached_review','status'=>'PASS','details'=>['fixture_only'=>true]]));
        foreach(['QA_PASSED','APPROVED'] as $state)self::assertNotWPError($production->transition('revision',(int)$revision['id'],$state));
        $plan=$production->createPlan(['product_version_id'=>$version['id'],'plan_key'=>'cached-external-drift','version_label'=>'Synthetic','channel'=>'digital','production_type'=>'external_document']);self::assertNotWPError($plan);self::assertTrue($production->linkAsset((int)$plan['id'],(int)$spec['id']));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)self::assertNotWPError($production->transition('plan',(int)$plan['id'],$state));
        $bundle=$production->createBundle(['production_plan_id'=>$plan['id'],'bundle_key'=>'cached-external-drift','version_label'=>'Synthetic']);self::assertNotWPError($bundle);
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)self::assertNotWPError($production->transition('bundle',(int)$bundle['id'],$state));
        self::assertSame('RELEASE_READY',$production->validateBundle((int)$bundle['id'])['state']);
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::asset_revisions(),['mime_type'=>'application/pdf','provenance'=>wp_json_encode(['generator'=>'digiforge_external_document','pdf_generator_profile'=>'digiforge-external-docx-pdf-v1'])],['id'=>$revision['id']]));
        self::assertWPError((new DigiForge\Listings\ListingFactory())->prepare((int)$version['id'],'external-cached-drift-'.wp_generate_uuid4()));
        self::assertSame(0,$this->listingCount((int)$version['id']));
    }
    public function testUntamperedCustodyPreparesOnlyScopedLocalReviewListing(): void
    {
        $f=$this->fixture();$factory=new DigiForge\Listings\ListingFactory();$key='external-listing-'.wp_generate_uuid4();
        $r=$factory->prepare((int)$f['version']['id'],$key);self::assertNotWPError($r);self::assertSame('REVIEW_REQUIRED',$r['listing']['state']);self::assertSame('digital',$r['listing']['shop_reference']);self::assertSame('sandbox',$r['listing']['environment']);self::assertFalse($r['draft_package_created']);
        self::assertSame($r['listing_id'],$factory->prepare((int)$f['version']['id'],$key)['listing_id']);self::assertSame(1,$this->listingCount((int)$f['version']['id']));
    }
    public static function retainedKinds(): array {return [['source'],['export']];}
    /** @dataProvider retainedKinds */
    public function testTamperedRetainedBytesDenyBeforeListingCreation(string $kind): void
    {
        $f=$this->fixture();file_put_contents($f['admitted'][$kind]['absolute_path'],'tampered retained bytes');
        self::assertWPError((new DigiForge\Listings\ListingFactory())->prepare((int)$f['version']['id'],'external-tamper-'.wp_generate_uuid4()));self::assertSame(0,$this->listingCount((int)$f['version']['id']));
    }
    public function testCachedManifestCannotRemoveExternalMemberWithoutMatchingHash(): void
    {
        $f=$this->fixture();global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::release_bundles(),['manifest'=>'[]'],['id'=>$f['bundle']['id']]));
        self::assertWPError((new DigiForge\Listings\ListingFactory())->prepare((int)$f['version']['id'],'external-manifest-'.wp_generate_uuid4()));self::assertSame(0,$this->listingCount((int)$f['version']['id']));
    }

    public function testReservedCustodyNamespaceCannotDowngradeStrippedMetadata(): void
    {
        $f=$this->fixture();global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::asset_revisions(),['mime_type'=>'application/octet-stream','provenance'=>'{}'],['id'=>$f['revision']['id']]));
        self::assertWPError((new DigiForge\Listings\ListingFactory())->prepare((int)$f['version']['id'],'external-stripped-'.wp_generate_uuid4()));self::assertSame(0,$this->listingCount((int)$f['version']['id']));
    }

    public function testListingAssignmentLostDuringFinalQaReadDeniesBeforeAnyBusinessWrite(): void
    {
        $f=$this->fixture();global $wpdb;
        $used=$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s AND stage=%s AND workflow=%s AND occurred_at>=%s','digital','listing_prepare','listing_factory_sandbox',gmdate('Y-m-01 00:00:00')));self::assertTrue(is_numeric($used));
        self::assertNotWPError((new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>'digital','currency'=>'USD','budgets'=>['month'=>10],'stages'=>['listing_prepare'=>['limit'=>(int)$used+1,'estimated_unit_cost'=>0]]],'sandbox'));
        $productId=(int)$f['version']['product_id'];
        $before=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::shop_ai_usage().' WHERE product_id=%d AND stage=%s',$productId,'listing_prepare'));
        $actor=get_current_user_id();$owner=$this->scopeOwner;$revoked=false;
        $filter=function(string $query)use(&$revoked,$owner,$actor):string{
            if (!$revoked&&str_contains($query,'SELECT status,details FROM '.Tables::production_qa())&&str_contains($query,'external_document_source_completeness')) {
                $revoked=true;
                // Preserve product authority with a separately signed replacement assignment;
                // revoke only grants carrying the listing capability, leaving WP roles intact.
                ShopAccessFixture::grant($owner,$actor,'digital',['manage_digiforge','manage_digiforge_products']);
                wp_set_current_user($owner);
                try {
                    $repo=new DigiForge\Security\ShopAccessRepository();$state=$repo->read('digital');$this->assertNotWPError($state);
                    foreach($state['grants'] as $id=>$grant){
                        if($grant['actor_id']!==$actor||$grant['revoked']||!in_array('manage_digiforge_listings',$grant['caps'],true))continue;
                        $result=$repo->revoke('digital',$id,str_replace('-','_',wp_generate_uuid4()),$state['revision']);$this->assertNotWPError($result);$state=$repo->read('digital');$this->assertNotWPError($state);
                    }
                } finally {wp_set_current_user($actor);}
                $this->assertNotWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge_products'));
                $this->assertWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge_listings'));
                $this->assertTrue(current_user_can('manage_digiforge_listings'));
            }
            return $query;
        };
        add_filter('query',$filter);
        try {$result=(new DigiForge\Listings\ListingFactory())->prepare((int)$f['version']['id'],'external-midworker-acl-'.wp_generate_uuid4());}
        finally {remove_filter('query',$filter);}
        self::assertTrue($revoked,'The actual post-worker QA query must exercise the listing-only assignment loss.');self::assertWPError($result);
        self::assertSame(0,$this->listingCount((int)$f['version']['id']));
        self::assertSame($before,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::shop_ai_usage().' WHERE product_id=%d AND stage=%s',$productId,'listing_prepare')));
    }

    public function testLostExactShopAssignmentDeniesBeforeListingCreation(): void
    {
        $f=$this->fixture();ShopAccessFixture::reset();
        self::assertWPError((new DigiForge\Listings\ListingFactory())->prepare((int)$f['version']['id'],'external-unassigned-'.wp_generate_uuid4()));self::assertSame(0,$this->listingCount((int)$f['version']['id']));
    }
}
