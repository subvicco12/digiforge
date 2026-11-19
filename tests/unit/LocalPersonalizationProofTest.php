<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\LocalPersonalizationProof;
use DigiForge\Research\DesignFactoryContract;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/Research/OpportunityDesignContract.php';
require_once __DIR__.'/../../includes/Research/CommercialResearchContract.php';
require_once __DIR__.'/../../includes/Research/DesignFactoryContract.php';
if (is_file(__DIR__.'/../../includes/POD/LocalPersonalizationProof.php')) require_once __DIR__.'/../../includes/POD/LocalPersonalizationProof.php';
final class LocalPersonalizationProofTest extends TestCase
{
    public static function spec(string $profile='fl03_mug_11oz'): array
    {
        $f=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);
        $f['research']['product_family']=DesignFactoryContract::PROFILES[$profile]['family'];
        $context=['research'=>$f['research'],'concept'=>$f['response']['concepts'][0],'selected_concept'=>1,'commercial_hash'=>str_repeat('a',64),'briefs'=>$f['response']['concepts']];
        return DesignFactoryContract::specification($context,$profile,['layout_notes'=>'Original licensed vector with native text reserved','typography'=>'Human-reviewed native typography','font_license_reference'=>'License evidence local record','palette'=>['#123456'],'native_regions'=>[['name'=>'names','kind'=>'text','x'=>200,'y'=>400,'width'=>1800,'height'=>300]],'asset_requirements'=>['Keep text editable and separate']]);
    }
    public static function artwork(array $spec): string
    {
        $w=$spec['geometry']['width_px'];$h=$spec['geometry']['height_px'];
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"><circle cx="100" cy="100" r="40" fill="#123456"/></svg>';
    }
    /** @dataProvider profiles */
    public function testProofUsesExactCanvasArtworkAndEscapedSample(string $profile): void
    {
        self::assertTrue(class_exists(LocalPersonalizationProof::class),'Local text proof renderer is missing');
        $s=self::spec($profile);$svg=self::artwork($s);$p=LocalPersonalizationProof::render($s,$svg,['names'=>'A & <B>']);
        $doc=new \DOMDocument();self::assertTrue($doc->loadXML($p['svg'],LIBXML_NONET));
        self::assertSame('A & <B>',$doc->getElementsByTagName('text')->item(0)->textContent);
        self::assertCount(1,$doc->getElementsByTagName('circle'));
        self::assertStringContainsString('A &amp; &lt;B&gt;',$p['svg']);
        self::assertSame(hash('sha256',$svg),$p['receipt']['artwork_sha256']);
        self::assertSame(hash('sha256',$p['svg']),$p['receipt']['proof_sha256']);
        self::assertSame($s['geometry']['width_px'],$p['receipt']['width_px']);
        self::assertFalse($p['receipt']['supplier_certified']);self::assertFalse($p['receipt']['external_execution_authorized']);
        self::assertContains('PROVIDER_FONT_METRICS_UNVERIFIED',$p['receipt']['remaining_gates']);
        self::assertStringNotContainsString('A & <B>',json_encode($p['receipt']));
        self::assertSame($p,LocalPersonalizationProof::render($s,$svg,['names'=>'A & <B>']));
        self::assertNotSame($p['receipt']['proof_sha256'],LocalPersonalizationProof::render($s,$svg,['names'=>'Other'])['receipt']['proof_sha256']);
    }
    public static function profiles(): array {return [['fl03_mug_11oz'],['fl04_poster_8x10']];}
    /** @dataProvider invalidSamples */
    public function testInvalidOrOverflowingSamplesDeny(array $samples): void
    {
        self::assertTrue(class_exists(LocalPersonalizationProof::class),'Local text proof renderer is missing');
        $s=self::spec();$this->expectException(\InvalidArgumentException::class);LocalPersonalizationProof::render($s,self::artwork($s),$samples);
    }
    public static function invalidSamples(): array {return [[[]],[['foreign'=>'Name']],[['names'=>'']],[['names'=>str_repeat('W',100)]],[['names'=>"A\nB"]],[['names'=>"A\x01B"]],[['names'=>"\xff"]],[['names'=>false]],[['names'=>['nested']]]];}
    public function testImagesCannotBePresentedAsTextProofs(): void
    {
        self::assertTrue(class_exists(LocalPersonalizationProof::class),'Local text proof renderer is missing');
        $s=self::spec();$s['layout']['native_regions'][0]['kind']='image';$this->expectException(\InvalidArgumentException::class);LocalPersonalizationProof::render($s,self::artwork($s),['names'=>'fake photo']);
    }
    public function testWrongGeometryAndUnsafeArtworkAreDenied(): void
    {
        self::assertTrue(class_exists(LocalPersonalizationProof::class),'Local text proof renderer is missing');
        $s=self::spec();$this->expectException(\InvalidArgumentException::class);LocalPersonalizationProof::render($s,'<svg><script/></svg>',['names'=>'Name']);
    }
    public function testWorkflowExposesApprovedProofAdapter(): void
    {
        require_once __DIR__.'/../../includes/POD/Shop2ProductWorkflow.php';
        self::assertTrue(method_exists(\DigiForge\POD\Shop2ProductWorkflow::class,'proof'),'Approved artwork proof adapter missing');
    }
}
