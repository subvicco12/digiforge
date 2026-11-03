<?php
declare(strict_types=1);
use DigiForge\POD\ApprovedDesignListingPackage;
use DigiForge\POD\ProductPackageRepository;
use DigiForge\Research\OpportunityDesignRepository;
use DigiForge\Tests\ApprovedDesignListingPackageTest;
require_once __DIR__.'/../unit/ApprovedDesignListingPackageTest.php';

final class ProductPackageWorkflowTest extends WP_UnitTestCase
{
    private function evidence(string $id='FL-04'): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixtures/product-packages/'.strtolower($id).'.json'),true);
    }
    private function approved(string $id='FL-04',bool $large=false): array
    {
        $actor=self::factory()->user->create();$u=new WP_User($actor);
        $u->add_cap('manage_digiforge_pod');$u->add_cap('manage_digiforge_research');wp_set_current_user($actor);
        $f=new ApprovedDesignListingPackageTest('testTechnicalFixturesProduceHonestCompleteReviewArtifacts');
        $in=$f->design()['record']['input'];
        foreach($in['briefs'] as $i=>&$brief)$brief['visual_direction']='Synthetic direction '.$i;
        unset($brief);
        [$w,$h]=$id==='FL-04'?[2418,3018]:[2475,1155];
        $in['artwork']['width_px']=$w;$in['artwork']['height_px']=$h;
        $in['artwork']['svg']='<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'"><text>'.($large?str_repeat('é',40000):'Synthetic test only').'</text></svg>';
        $repo=new OpportunityDesignRepository();$v=$repo->save($in);self::assertIsArray($v);
        $a=$repo->decide('personalized_pod',$v['version_hash'],'APPROVE');self::assertIsArray($a);return $a;
    }
    public function testLargeApprovedReferenceIsBoundedAndReverified(): void
    {
        $a=$this->approved('FL-04',true);$svg=$a['record']['input']['artwork']['svg'];
        self::assertWPError(ApprovedDesignListingPackage::fromApprovedVersion('personalized_pod',$a['version_hash'],$this->evidence()));
        $p=ApprovedDesignListingPackage::fromApprovedAssetReference('personalized_pod',$a['version_hash'],$this->evidence());
        self::assertIsArray($p);self::assertLessThanOrEqual(65536,strlen(wp_json_encode($p)));
        self::assertSame(hash('sha256',$svg),$p['design']['asset']['sha256']);
        self::assertArrayNotHasKey('svg',$p['source_approval']['record']['input']['artwork']);
        self::assertSame($a['version_hash'],$p['source_approval']['version_hash']);
        self::assertFalse($p['external_execution_authorized']);
        $repo=new ProductPackageRepository();$v=$repo->prepare('personalized_pod',$a['version_hash'],$this->evidence());self::assertIsArray($v);
        self::assertIsArray($repo->decide('personalized_pod',$v['hash'],'APPROVE','Large reference test only'));
        $export=$repo->export('personalized_pod',$v['hash']);self::assertIsString($export);
        self::assertLessThanOrEqual(65536,strlen(wp_json_encode(json_decode($export,true))));
        self::assertSame($svg,$repo->artwork('personalized_pod',$v['hash'])['bytes']);
        wp_set_current_user(0);
        self::assertWPError(ApprovedDesignListingPackage::fromApprovedAssetReference('personalized_pod',$a['version_hash'],$this->evidence()));
    }
    public function testMugPosterVersionsDecisionsAndHeldExport(): void
    {
        $repo=new ProductPackageRepository();$calls=0;
        $deny=static function()use(&$calls){++$calls;return new WP_Error('no_http');};add_filter('pre_http_request',$deny);
        try{foreach(['FL-02','FL-03','FL-04'] as $id){
            $a=$this->approved($id);$e=$this->evidence($id);
            $p=$repo->prepare('personalized_pod',$a['version_hash'],$e);self::assertIsArray($p);
            self::assertWPError($repo->export('personalized_pod',$p['hash']));
            $approved=$repo->decide('personalized_pod',$p['hash'],'APPROVE','Local review only');self::assertIsArray($approved);
            self::assertWPError($repo->decide('personalized_pod',$p['hash'],'REJECT','Cannot overwrite'));
            $export=$repo->export('personalized_pod',$p['hash']);self::assertIsString($export);$json=json_decode($export,true);
            self::assertFalse($json['external_execution_authorized']);self::assertTrue($json['release_hold']);
            self::assertFalse($json['commercial_approved']);
            $e['listing']['description'].=' Revised review text.';
            $next=$repo->prepare('personalized_pod',$a['version_hash'],$e,$p['hash']);self::assertIsArray($next);
            self::assertNotSame($p['hash'],$next['hash']);self::assertNull($next['decision']);
            self::assertCount(2,$repo->history('personalized_pod',$next['hash']));
            self::assertWPError($repo->read('digital',$p['hash']));
        }self::assertSame(0,$calls);}finally{remove_filter('pre_http_request',$deny);}
    }
    public function testSourceTamperAndRevokedCapabilitiesBlockPackageRead(): void
    {
        $a=$this->approved();$repo=new ProductPackageRepository();$p=$repo->prepare('personalized_pod',$a['version_hash'],$this->evidence());self::assertIsArray($p);
        wp_get_current_user()->remove_cap('manage_digiforge_pod');self::assertWPError($repo->read('personalized_pod',$p['hash']));
        wp_get_current_user()->add_cap('manage_digiforge_pod');
        update_option('digiforge_opportunity_personalized_pod_'.$a['version_hash'],'{}');
        self::assertWPError($repo->read('personalized_pod',$p['hash']));
    }

    public function testManualReconciliationBindsExactApprovedPackageWithoutActivation(): void
    {
        $a=$this->approved('FL-02');$repo=new ProductPackageRepository();
        $p=$repo->prepare('personalized_pod',$a['version_hash'],$this->evidence('FL-02'));self::assertIsArray($p);
        $receipt=['printify_product_id'=>'6ae89ec108d860bc98040ca0','etsy_listing_id'=>'4608699164',
            'etsy_state'=>'Draft','source_reference'=>'manual/etsy-readback.json','source_sha256'=>hash('sha256','synthetic readback'),
            'observed_at'=>'2026-11-03T12:00:00Z','shipping_profile_id'=>'318895906583','processing_profile_id'=>'1528525201818'];
        self::assertWPError($repo->reconcile('personalized_pod',$p['hash'],$receipt));
        self::assertIsArray($repo->decide('personalized_pod',$p['hash'],'APPROVE','Local package review'));
        $r=$repo->reconcile('personalized_pod',$p['hash'],$receipt);self::assertIsArray($r);
        self::assertSame('MANUAL_REPORTED_UNVERIFIED',$r['record']['provenance']);
        self::assertFalse($r['record']['external_execution_authorized']);
        self::assertSame($p['hash'],$r['record']['package_hash']);
        $receipt['etsy_state']='Active';self::assertWPError($repo->reconcile('personalized_pod',$p['hash'],$receipt));
        $receipt['etsy_state']='Draft';$receipt['etsy_listing_id']='999';
        self::assertWPError($repo->reconcile('personalized_pod',$p['hash'],$receipt));
    }

    public function testActualDayThreeCommercialMugAndPosterHandoff(): void
    {
        foreach(['FL-03'=>'mug','FL-04'=>'poster'] as $id=>$family){
            $this->approved($id);
            $fixture=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);
            $fixture['research']['product_family']=$family;
            $research=new \DigiForge\Research\CommercialResearchRepository();
            $v=$research->create($fixture['research']);self::assertIsArray($v);
            $v=$research->importResponse('personalized_pod',$v['hash'],json_encode($fixture['response']),
                ['method'=>'manual','provider'=>'human','model'=>'none','response_id'=>'test','generated_at'=>'2026-11-03T12:00:00Z']);
            self::assertIsArray($v);$selected=$research->select('personalized_pod',$v['hash'],1);self::assertIsArray($selected);
            $factory=new \DigiForge\Research\DesignFactoryRepository();
            $a=$factory->approveConcept('personalized_pod',$selected['hash'],['sources_reviewed'=>true,'buyer_intent_reviewed'=>true,
                'gifting_occasion_reviewed'=>true,'note'=>'Synthetic commercial criteria for integration test only']);self::assertIsArray($a);
            $spec=$factory->prepare('personalized_pod',$a['hash'],$family==='mug'?'fl03_mug_11oz':'fl04_poster_8x10',
                ['layout_notes'=>'Original paths around reserved native names','typography'=>'Licensed native text',
                'font_license_reference'=>'Synthetic review reference','palette'=>['#123456','#ffffff'],
                'native_regions'=>[['name'=>'names','kind'=>'text','x'=>500,'y'=>400,'width'=>1400,'height'=>300]],
                'asset_requirements'=>['Original fixed shapes separated from text']]);self::assertIsArray($spec);
            $asset=$factory->importArtwork('personalized_pod',$spec['hash'],['svg'=>file_get_contents(__DIR__.'/../fixtures/design-factory/'.$family.'-original.svg'),
                'raster_uploads'=>[],'rights_note'=>'Synthetic original example','source_note'=>'Manual illustrative import']);self::assertIsArray($asset);
            $repo=new ProductPackageRepository();
            self::assertWPError($repo->prepareCommercial('personalized_pod',$asset['hash'],$this->evidence($id)));
            $review=$factory->review('personalized_pod',$asset['hash'],'APPROVE',
                array_fill_keys(\DigiForge\Research\DesignFactoryContract::REVIEW_CRITERIA,true),'Synthetic human criteria review');self::assertIsArray($review);
            if($id==='FL-03')self::assertWPError($repo->prepareCommercial('personalized_pod',$review['hash'],$this->evidence('FL-02')));
            $p=$repo->prepareCommercial('personalized_pod',$review['hash'],$this->evidence($id));self::assertIsArray($p);
            self::assertSame($review['hash'],$p['record']['commercial_design_metadata']['design_hash']);
            self::assertIsArray($repo->decide('personalized_pod',$p['hash'],'APPROVE','Held package review, synthetic only'));
            $out=json_decode($repo->export('personalized_pod',$p['hash']),true);
            self::assertTrue($out['commercial_design_review_confirmed']);self::assertFalse($out['commercial_approved']);
            self::assertFalse($out['external_execution_authorized']);
            $bytes=$repo->artwork('personalized_pod',$p['hash']);self::assertIsArray($bytes);
            self::assertSame($p['record']['package']['design']['asset']['sha256'],hash('sha256',$bytes['bytes']));
        }
    }

    public function testReferenceAllowsOnlyBoundedPublicSearchQueries(): void
    {
        $base=$this->approved();$repo=new OpportunityDesignRepository();
        foreach(['q=anniversary+mug'=>true,'key=private'=>false,'q[]=mug'=>false,'q=mug&q=poster'=>false,'q=sk-private-value'=>false] as $query=>$allowed){
            $in=$base['record']['input'];$in['sources'][0]['url']='https://example.org/search?'.$query;
            $v=$repo->save($in);self::assertIsArray($v);$a=$repo->decide('personalized_pod',$v['version_hash'],'APPROVE');self::assertIsArray($a);
            $result=ApprovedDesignListingPackage::fromApprovedAssetReference('personalized_pod',$v['version_hash'],$this->evidence());
            if($allowed){self::assertIsArray($result);self::assertWPError(ApprovedDesignListingPackage::fromApprovedVersion('personalized_pod',$v['version_hash'],$this->evidence()));}
            else self::assertWPError($result);
        }
    }
    public function testDecisionWriteFailureAndCorruptReceiptCannotAuthorizeExport(): void
    {
        $a=$this->approved();$repo=new ProductPackageRepository();$p=$repo->prepare('personalized_pod',$a['version_hash'],$this->evidence());self::assertIsArray($p);
        $fault=static fn($q)=>str_contains($q,'INSERT INTO')&&str_contains($q,'digiforge_product_package_')&&str_contains($q,'_decision')?'SELECT 1 WHERE 0=1':$q;
        add_filter('query',$fault);
        try { self::assertWPError($repo->decide('personalized_pod',$p['hash'],'APPROVE','Write fault')); }
        finally { remove_filter('query',$fault); }
        self::assertWPError($repo->export('personalized_pod',$p['hash']));
        self::assertNull($repo->read('personalized_pod',$p['hash'])['decision']);
        self::assertIsArray($repo->decide('personalized_pod',$p['hash'],'APPROVE','Successful exact decision'));
        update_option('digiforge_product_package_'.$p['hash'].'_decision','{}');
        self::assertWPError($repo->read('personalized_pod',$p['hash']));
        self::assertWPError($repo->export('personalized_pod',$p['hash']));
    }
}
