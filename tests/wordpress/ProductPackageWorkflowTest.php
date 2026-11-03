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
        $f=new ApprovedDesignListingPackageTest('testTechnicalFixturesProduceHonestCompleteReviewArtifacts');
        $e=$f->evidence();
        if($id==='FL-03')$e['fixture']=['fixture_id'=>$id,'printify_product_id'=>'6ae98d61eb5d7e24b80ca0a6','etsy_listing_id'=>null,'etsy_state'=>'NOT_TRANSFERRED'];
        if($id==='FL-02')$e=$f->evidence('FL-02');
        return $e;
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
}
