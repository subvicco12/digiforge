<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyResearchMapper;
use DigiForge\POD\PrintifyResearchBatch;
if(is_file(__DIR__.'/../../includes/POD/PrintifyResearchMapper.php'))require_once __DIR__.'/../../includes/POD/PrintifyResearchMapper.php';
if(is_file(__DIR__.'/../../includes/POD/PrintifyResearchBatch.php'))require_once __DIR__.'/../../includes/POD/PrintifyResearchBatch.php';
final class PrintifyResearchMappingTest extends TestCase
{
    private function receipt(string $kind,array $data): array
    {
        return ['request'=>['kind'=>$kind,'blueprint_id'=>5,'provider_id'=>50],'endpoint_path'=>'/v1/catalog/blueprints/5/print_providers/50/variants.json','observed_at'=>'2026-11-03T14:00:00Z','response_sha256'=>str_repeat('a',64),'sha256'=>str_repeat('b',64),'grant_sha256'=>str_repeat('c',64),'request_sha256'=>str_repeat('d',64),'integration_id'=>7,'environment'=>'test','scope'=>['product_program'=>'PERSONALIZED_POD'],'data'=>$data];
    }
    public function testVariantPlaceholderIsNotCertifiedPhysicalGeometryOrKnownCost(): void
    {
        self::assertTrue(class_exists(PrintifyResearchMapper::class));
        $rows=PrintifyResearchMapper::map($this->receipt('variants',['variants'=>[['id'=>12,'title'=>'S','placeholders'=>[['position'=>'front','width'=>3000,'height'=>4000]]]] ]));
        self::assertNull($rows['variants'][0]['Production Cost']);self::assertNull($rows['variants'][0]['Tax Amount']);
        self::assertSame('3000',$rows['print_areas'][0]['Width PX']);self::assertNull($rows['print_areas'][0]['DPI']);
        self::assertSame('PIXEL_DIMENSIONS_ONLY',$rows['print_areas'][0]['Geometry Status']);
        self::assertNotEmpty($rows['gaps']);
    }
    public function testProviderDiscoveryRetainsIdentityAndLocation(): void
    {
        $e=$this->receipt('providers',[['id'=>9,'title'=>'Provider A','location'=>['country'=>'US']]]);unset($e['request']['provider_id']);$e['endpoint_path']='/v1/catalog/blueprints/5/print_providers.json';
        $mapped=PrintifyResearchMapper::map($e);self::assertSame('9',$mapped['providers'][0]['Provider ID']);self::assertStringContainsString('US',$mapped['providers'][0]['Location']);
    }
    public function testBlueprintDoesNotInventSizeGuide(): void
    {
        $e=$this->receipt('blueprint',['id'=>5,'title'=>'Shirt']);unset($e['request']['provider_id']);$e['endpoint_path']='/v1/catalog/blueprints/5.json';
        $mapped=PrintifyResearchMapper::map($e);self::assertSame([],$mapped['size_guides']);self::assertSame('SIZE_GUIDE_MISSING',$mapped['gaps'][0]['Gap']);
    }
    public function testShippingIndexIsNotRateEvidence(): void
    {
        $e=$this->receipt('shipping',['data'=>[['type'=>'shipping_method','id'=>'1','attributes'=>['name'=>'standard']]]]);$e['endpoint_path']='/v2/catalog/blueprints/5/print_providers/50/shipping.json';
        $mapped=PrintifyResearchMapper::map($e);self::assertNull($mapped['shipping'][0]['First Item Amount']);self::assertNull($mapped['shipping'][0]['Country / Zone']);
    }
    public function testForeignEndpointCannotBecomeEvidence(): void
    {
        $e=$this->receipt('variants',['variants'=>[]]);$e['endpoint_path']='https://evil.test/orders';$this->expectException(\InvalidArgumentException::class);PrintifyResearchMapper::map($e);
    }
    public function testExactSizeGuideAndShippingScopesRejectInjectedUrlsAndMutations(): void
    {
        self::assertSame('/v1/catalog/blueprints/5/size_guide.json',PrintifyResearchMapper::endpoint(['kind'=>'size_guide','blueprint_id'=>5]));
        foreach([['kind'=>'orders','blueprint_id'=>5],['kind'=>'providers','blueprint_id'=>5,'url'=>'https://evil.test'],['kind'=>'shipping_method','blueprint_id'=>5,'provider_id'=>50,'method'=>'../orders']] as $r){
            $denied=false;try{PrintifyResearchMapper::endpoint($r);}catch(\InvalidArgumentException){$denied=true;}self::assertTrue($denied);
        }
    }
    public function testSizeGuidePreservesRangeOrderAndUnknownBound(): void
    {
        $e=$this->receipt('size_guide',['sizes'=>['S','M'],'types'=>[['name'=>'Width','units'=>'in','ranges'=>[['from'=>'18','to'=>null],['from'=>'20','to'=>'22']]]]]);unset($e['request']['provider_id']);$e['endpoint_path']='/v1/catalog/blueprints/5/size_guide.json';
        $rows=PrintifyResearchMapper::map($e)['size_guides'];self::assertSame('S',$rows[0]['Size / Variant']);self::assertNull($rows[0]['Range To']);self::assertSame('in',$rows[1]['Unit']);self::assertSame('22',$rows[1]['Range To']);
    }
    public function testCredentialLikePayloadKeysAreRejected(): void
    {
        $e=$this->receipt('variants',['variants'=>[['id'=>1,'access_token'=>'must-not-leak']]]);$this->expectException(\InvalidArgumentException::class);PrintifyResearchMapper::map($e);
    }
    public function testBatchPlanNeverRaisesTenRequestGrantCeiling(): void
    {
        self::assertTrue(class_exists(PrintifyResearchBatch::class));
        $plan=PrintifyResearchBatch::plan('personalized_pod',str_repeat('e',64),[5,6,5]);
        self::assertSame([5,6],$plan['blueprint_ids']);self::assertSame(10,$plan['max_requests_per_grant']);self::assertFalse($plan['external_execution_authorized']);
        $this->expectException(\InvalidArgumentException::class);PrintifyResearchBatch::plan('digital',str_repeat('e',64),[5]);
    }
}
