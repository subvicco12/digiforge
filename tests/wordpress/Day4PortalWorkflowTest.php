<?php
declare(strict_types=1);
use DigiForge\Portal\CommercialResearchPanel;
use DigiForge\Portal\DesignFactoryPanel;
use DigiForge\POD\ProductPackageRepository;
use DigiForge\Research\DesignFactoryContract;

final class Day4PortalWorkflowTest extends WP_UnitTestCase
{
    private int $sequence=0;
    protected function setUp(): void
    {
        parent::setUp();$id=self::factory()->user->create();$u=new WP_User($id);
        foreach(['manage_digiforge','manage_digiforge_pod','manage_digiforge_research'] as $cap)$u->add_cap($cap);
        wp_set_current_user($id);
    }
    private function action(array $post): array
    {
        $result=(new CommercialResearchPanel())->process($post+['shop_key'=>'personalized_pod']);
        self::assertIsArray($result);return $result;
    }
    private function approvedDesign(string $family): array
    {
        $f=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);
        $f['research']['product_family']=$family;$f['research']['title'].=' '.++$this->sequence.' '.$family;
        $v=$this->action(['operation'=>'research_import','research_json'=>wp_json_encode($f['research'])]);
        $expectedSources=$f['research']['sources'];usort($expectedSources,static fn($a,$b)=>strcmp($a['id'],$b['id']));
        self::assertSame($expectedSources,$v['record']['research']['sources']);
        $hash=$v['hash'];
        $concept=$this->action(['operation'=>'concepts','workflow_hash'=>$hash,'method'=>'manual','response_json'=>wp_json_encode($f['response'])]);
        $selected=$this->action(['operation'=>'select','workflow_hash'=>$concept['hash'],'concept'=>'1']);
        $context=['workflow_hash'=>$selected['hash']];
        $a=$this->action($context+['operation'=>'design_approve_concept','sources_reviewed'=>'1','buyer_intent_reviewed'=>'1',
            'gifting_occasion_reviewed'=>'1','note'=>'Synthetic source review for portal acceptance only']);
        $spec=$this->action($context+['operation'=>'design_prepare','factory_hash'=>$a['design_factory_hash'],
            'profile'=>$family==='mug'?'fl03_mug_11oz':'fl04_poster_8x10','layout_notes'=>'Original fixed artwork outside native reservation',
            'typography'=>'Licensed native text hierarchy','font_license_reference'=>'Synthetic license review reference',
            'palette'=>'#123456,#ffffff','asset_requirements'=>'Original shapes separated from native names',
            'native_regions_json'=>wp_json_encode([['name'=>'names','kind'=>'text','x'=>500,'y'=>400,'width'=>1400,'height'=>300]])]);
        $asset=$this->action($context+['operation'=>'design_import','factory_hash'=>$spec['design_factory_hash'],
            'svg'=>file_get_contents(__DIR__.'/../fixtures/design-factory/'.$family.'-original.svg'),'rasters_json'=>'[]',
            'rights_note'=>'Synthetic original artwork example','source_note'=>'Manual acceptance fixture, not actual commercial approval']);
        $criteria=array_fill_keys(DesignFactoryContract::REVIEW_CRITERIA,'1');
        $approved=$this->action($context+['operation'=>'design_review','factory_hash'=>$asset['design_factory_hash'],
            'decision'=>'APPROVE','note'=>'Synthetic human review only','confirm'=>'1']+$criteria);
        return ['workflow_hash'=>$selected['hash'],'factory_hash'=>$approved['design_factory_hash']];
    }
    private function prepare(array $context,string $fixture,?string $parent=null): array
    {
        $e=json_decode(file_get_contents(__DIR__.'/../fixtures/product-packages/'.$fixture.'.json'),true);
        return $this->action($context+['operation'=>'package_prepare','parent_hash'=>$parent??'',
            'evidence_json'=>wp_json_encode($e),'title'=>$e['listing']['title'],'description'=>$e['listing']['description'],
            'tags'=>implode(', ',$e['listing']['tags']),'price'=>(string)$e['economics']['sale_price'],'currency'=>'USD']);
    }
    public function testMugAndPosterCompleteThroughExistingPortalParserAndRender(): void
    {
        $calls=0;$deny=static function()use(&$calls){++$calls;return new WP_Error('no_http');};add_filter('pre_http_request',$deny);
        try{foreach(['mug'=>'fl-03','poster'=>'fl-04'] as $family=>$id){
            $context=$this->approvedDesign($family);$p=$this->prepare($context,$id);
            self::assertArrayHasKey('package_hash',$p);
            $post=$context+['operation'=>'package_export','package_hash'=>$p['package_hash'],'confirm'=>'1','shop_key'=>'personalized_pod'];
            self::assertWPError((new CommercialResearchPanel())->process($post));
            $this->action($context+['operation'=>'package_decide','package_hash'=>$p['package_hash'],
                'decision'=>'APPROVE','note'=>'Synthetic held package review only','confirm'=>'1']);
            $out=$this->action($post);$json=json_decode($out['package_export'],true);
            self::assertTrue($json['release_hold']);self::assertFalse($json['external_execution_authorized']);
            self::assertFalse($json['commercial_approved']);self::assertLessThanOrEqual(65536,strlen(wp_json_encode($json)));
            $_GET['df_research_version']=$context['workflow_hash'];$_GET['df_factory_version']=$context['factory_hash'];$_GET['df_package_version']=$p['package_hash'];
            ob_start();(new CommercialResearchPanel())->render('personalized_pod');$html=ob_get_clean();
            self::assertStringContainsString('Held product package',$html);self::assertStringContainsString('package_export',$html);
            self::assertStringContainsString('Conditional economics',$html);self::assertStringContainsString('data:image/svg+xml;base64,',$html);
            self::assertStringNotContainsString('<svg',$html);self::assertStringNotContainsString('name="package_export" value=',$html);
            self::assertStringContainsString('_wpnonce',$html);
            self::assertStringNotContainsString('OWNER_PACKAGE_APPROVAL_REQUIRED',$html);
            unset($_GET['df_research_version'],$_GET['df_factory_version'],$_GET['df_package_version']);
            $next=$this->prepare($context,$id,$p['package_hash']);self::assertNotSame($p['package_hash'],$next['package_hash']);
            self::assertNull((new ProductPackageRepository())->read('personalized_pod',$next['package_hash'])['decision']);
        }self::assertSame(0,$calls);}finally{remove_filter('pre_http_request',$deny);}
    }
    public function testWorkImportIsBoundedAndCannotAssertApproval(): void
    {
        $f=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true)['research'];
        $post=['operation'=>'research_import','shop_key'=>'personalized_pod','research_json'=>wp_json_encode($f)];
        self::assertIsArray((new CommercialResearchPanel())->process($post));
        $f['approved_by']=get_current_user_id();$post['research_json']=wp_json_encode($f);
        self::assertWPError((new CommercialResearchPanel())->process($post));
        $post['research_json']=str_repeat('x',200001);self::assertWPError((new CommercialResearchPanel())->process($post));
        $post['research_json']='{"shop_key":"digital"}';self::assertWPError((new CommercialResearchPanel())->process($post));
    }
    public function testAuthorizationContextAndMalformedEvidenceAreDenied(): void
    {
        $a=$this->approvedDesign('mug');$p=$this->prepare($a,'fl-03');$b=$this->approvedDesign('poster');
        $post=$b+['operation'=>'package_inspect','package_hash'=>$p['package_hash'],'shop_key'=>'personalized_pod'];
        self::assertWPError((new CommercialResearchPanel())->process($post));
        $post=$a+['operation'=>'package_prepare','evidence_json'=>'[]','price'=>'NaN','title'=>'x','description'=>'x','tags'=>'x','currency'=>'USD','shop_key'=>'personalized_pod'];
        self::assertWPError((new CommercialResearchPanel())->process($post));
        $post=$a+['operation'=>'package_decide','package_hash'=>$p['package_hash'],'decision'=>'APPROVE','note'=>'No confirmation','shop_key'=>'personalized_pod'];
        self::assertWPError((new CommercialResearchPanel())->process($post));
        $post['shop_key']='digital';self::assertWPError((new CommercialResearchPanel())->process($post));
        $post['shop_key']='personalized_pod';wp_get_current_user()->remove_cap('manage_digiforge_pod');self::assertWPError((new CommercialResearchPanel())->process($post));
        wp_set_current_user(0);self::assertWPError((new CommercialResearchPanel())->process($post));
    }
    public function testActualPostHandlerRejectsMissingNonceBeforeAnyOperation(): void
    {
        $handler=static fn()=>static function(){throw new RuntimeException('day4_nonce_blocked');};
        add_filter('wp_die_handler',$handler);$saved=$_REQUEST;$_REQUEST=[];
        try{$this->expectException(RuntimeException::class);$this->expectExceptionMessage('day4_nonce_blocked');
            (new CommercialResearchPanel())->submit();
        }finally{$_REQUEST=$saved;remove_filter('wp_die_handler',$handler);}
    }
}
