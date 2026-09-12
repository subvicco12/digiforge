<?php

declare(strict_types=1);

final class ProductionRepositoryTest extends WP_UnitTestCase
{
    private DigiForge\Production\Repository $repo;
    private int $productVersionId;

    public function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        $products=new DigiForge\ProductFactory\Repository();
        $opp=$products->create('opportunity',['title'=>'Production Fixture'],'prod-opp');
        $family=$products->create('product_family',['opportunity_id'=>$opp['id'],'name'=>'Fixture Family'],'prod-family');
        $product=$products->create('product',['product_family_id'=>$family['id'],'name'=>'Fixture Product'],'prod-product');
        $version=$products->create('product_version',['product_id'=>$product['id'],'version_label'=>'1.0.0'],'prod-version');
        $this->productVersionId=(int)$version['id'];
        $this->repo=new DigiForge\Production\Repository();
    }

    public function testReleaseReadinessRequiresRelationshipsQaAndHumanApproval(): void
    {
        $spec=$this->repo->createSpec([
            'product_version_id'=>$this->productVersionId,'asset_key'=>'main-art','asset_type'=>'print','format'=>'png','width_px'=>4500,'height_px'=>5400,'dpi'=>300,
            'content_requirements'=>['required'=>true],'design_constraints'=>['background'=>'transparent'],
        ],'spec-1');
        self::assertFalse(is_wp_error($spec));
        $replay=$this->repo->createSpec(['product_version_id'=>$this->productVersionId,'asset_key'=>'ignored','asset_type'=>'print','format'=>'png'],'spec-1');
        self::assertFalse(is_wp_error($replay));
        self::assertTrue($replay['idempotent_replay']);

        $plan=$this->repo->createPlan(['product_version_id'=>$this->productVersionId,'plan_key'=>'launch','version_label'=>'1','channel'=>'pod','production_type'=>'artwork'],'plan-1');
        self::assertFalse(is_wp_error($plan));
        self::assertTrue($this->repo->linkAsset((int)$plan['id'],(int)$spec['id'],true,1));

        $intent=$this->repo->createIntent(['production_plan_id'=>$plan['id'],'asset_spec_id'=>$spec['id'],'intent_type'=>'RENDER','provider_class'=>'ai','input_payload'=>['prompt_ref'=>'governed-local-reference']], 'intent-1');
        self::assertFalse(is_wp_error($intent));
        self::assertSame('BLOCKED',$intent['state']);

        $revision=$this->repo->addRevision(['asset_spec_id'=>$spec['id'],'revision_label'=>'r1','storage_reference'=>'local://assets/r1.png','checksum_sha256'=>str_repeat('a',64),'mime_type'=>'image/png','width_px'=>4500,'height_px'=>5400,'provenance'=>['source'=>'manual-fixture']], 'rev-1');
        self::assertFalse(is_wp_error($revision));
        $qaPassed=$this->repo->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'technical','status'=>'PASS','details'=>['fixture'=>true]], 'qa-1');
        self::assertFalse(is_wp_error($qaPassed));

        self::assertFalse(is_wp_error($this->repo->transition('revision',(int)$revision['id'],'QA_PASSED')));
        self::assertFalse(is_wp_error($this->repo->transition('revision',(int)$revision['id'],'APPROVED')));
        self::assertFalse(is_wp_error($this->repo->transition('plan',(int)$plan['id'],'VALIDATED')));
        self::assertFalse(is_wp_error($this->repo->transition('plan',(int)$plan['id'],'REVIEW_REQUIRED')));
        self::assertFalse(is_wp_error($this->repo->transition('plan',(int)$plan['id'],'APPROVED')));

        $bundle=$this->repo->createBundle(['production_plan_id'=>$plan['id'],'bundle_key'=>'etsy-launch','version_label'=>'1'],'bundle-1');
        self::assertFalse(is_wp_error($bundle));
        self::assertFalse(is_wp_error($this->repo->transition('bundle',(int)$bundle['id'],'VALIDATED')));
        self::assertFalse(is_wp_error($this->repo->transition('bundle',(int)$bundle['id'],'REVIEW_REQUIRED')));

        $beforeApproval=$this->repo->validateBundle((int)$bundle['id']);
        self::assertFalse(is_wp_error($beforeApproval));
        self::assertFalse($beforeApproval['readiness']['ready']);
        self::assertSame('REVIEW_REQUIRED',$beforeApproval['state']);

        self::assertFalse(is_wp_error($this->repo->transition('bundle',(int)$bundle['id'],'APPROVED')));
        $bypass=$this->repo->transition('bundle',(int)$bundle['id'],'RELEASE_READY');
        self::assertTrue(is_wp_error($bypass));
        self::assertSame(409,$bypass->get_error_data()['status']);

        $ready=$this->repo->validateBundle((int)$bundle['id']);
        self::assertFalse(is_wp_error($ready));
        self::assertTrue($ready['readiness']['ready']);
        self::assertSame('RELEASE_READY',$ready['state']);
        self::assertSame(64,strlen((string)$ready['checksum_sha256']));
        $again=$this->repo->validateBundle((int)$bundle['id']);
        self::assertSame($ready['checksum_sha256'],$again['checksum_sha256']);
        self::assertSame($ready['manifest'],$again['manifest']);
    }

    public function testMissingQaAndCrossProductLinksFailClosed(): void
    {
        $spec=$this->repo->createSpec(['product_version_id'=>$this->productVersionId,'asset_key'=>'secondary','asset_type'=>'digital','format'=>'pdf'],'spec-2');
        $plan=$this->repo->createPlan(['product_version_id'=>$this->productVersionId,'plan_key'=>'digital','version_label'=>'1','channel'=>'digital','production_type'=>'document'],'plan-2');
        self::assertTrue($this->repo->linkAsset((int)$plan['id'],(int)$spec['id']));
        $revision=$this->repo->addRevision(['asset_spec_id'=>$spec['id'],'revision_label'=>'r1','storage_reference'=>'local://assets/r2.pdf','checksum_sha256'=>str_repeat('b',64)],'rev-2');
        self::assertFalse(is_wp_error($this->repo->transition('revision',(int)$revision['id'],'QA_PASSED')));
        self::assertFalse(is_wp_error($this->repo->transition('revision',(int)$revision['id'],'APPROVED')));
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)self::assertFalse(is_wp_error($this->repo->transition('plan',(int)$plan['id'],$state)));
        $bundle=$this->repo->createBundle(['production_plan_id'=>$plan['id'],'bundle_key'=>'no-qa','version_label'=>'1'],'bundle-2');
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)self::assertFalse(is_wp_error($this->repo->transition('bundle',(int)$bundle['id'],$state)));
        $blocked=$this->repo->validateBundle((int)$bundle['id']);
        self::assertFalse($blocked['readiness']['ready']);
        self::assertNotEmpty($blocked['readiness']['qa_blockers']);

        $products=new DigiForge\ProductFactory\Repository();
        $opp=$products->create('opportunity',['title'=>'Other'],'other-opp');
        $family=$products->create('product_family',['opportunity_id'=>$opp['id'],'name'=>'Other Family'],'other-family');
        $product=$products->create('product',['product_family_id'=>$family['id'],'name'=>'Other Product'],'other-product');
        $otherVersion=$products->create('product_version',['product_id'=>$product['id'],'version_label'=>'1'],'other-version');
        $otherSpec=$this->repo->createSpec(['product_version_id'=>$otherVersion['id'],'asset_key'=>'other','asset_type'=>'digital','format'=>'png'],'other-spec');
        $invalid=$this->repo->linkAsset((int)$plan['id'],(int)$otherSpec['id']);
        self::assertTrue(is_wp_error($invalid));
        self::assertSame(409,$invalid->get_error_data()['status']);
    }
}
