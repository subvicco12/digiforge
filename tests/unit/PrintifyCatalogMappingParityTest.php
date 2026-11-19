<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\PrintifyCatalogReadContract as Contract;
use DigiForge\POD\PrintifyResearchMapper as Mapper;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyCatalogReadContract.php';
require_once __DIR__.'/../../includes/POD/PrintifyResearchMapper.php';

final class PrintifyCatalogMappingParityTest extends TestCase
{
    private function map(array $request,array $data): array
    {
        self::assertSame(Contract::endpoint($request),Mapper::endpoint($request));
        return Mapper::map(['request'=>$request,'endpoint_path'=>Contract::endpoint($request),'data'=>Contract::select($data,$request),'observed_at'=>'2026-11-03T14:00:00Z','response_sha256'=>str_repeat('a',64),'sha256'=>str_repeat('b',64),'grant_sha256'=>str_repeat('c',64),'request_sha256'=>str_repeat('d',64),'integration_id'=>7,'environment'=>'test','scope'=>['product_program'=>'PERSONALIZED_POD']]);
    }
    public function testBlueprintProviderAndVariantContractsComposeWithMapper(): void
    {
        $rows=$this->map(['kind'=>'blueprint','blueprint_id'=>5],['id'=>5,'title'=>'Source blueprint']);self::assertSame('5',$rows['blueprints'][0]['Blueprint ID']);
        $rows=$this->map(['kind'=>'providers','blueprint_id'=>5],[['id'=>9,'title'=>'Source provider']]);self::assertSame('9',$rows['providers'][0]['Provider ID']);
        foreach(['variants','variant'] as $kind){
            $request=['kind'=>$kind,'blueprint_id'=>5,'provider_id'=>9];if($kind==='variant')$request['variant_id']=12;
            $rows=$this->map($request,['variants'=>[['id'=>12,'placeholders'=>[['position'=>'front','width'=>3000,'height'=>4000]]]]]);
            self::assertSame('12',$rows['variants'][0]['Variant ID']);self::assertNull($rows['variants'][0]['Production Cost']);self::assertNull($rows['variants'][0]['Tax Amount']);self::assertSame('FALSE',$rows['print_areas'][0]['Production Certified']);
        }
    }
    public function testSizeGuidePreservesMissingUnitsAndBoundsAsUnknown(): void
    {
        $rows=$this->map(['kind'=>'size_guide','blueprint_id'=>5],['sizes'=>['S'],'types'=>[['name'=>'Width','units'=>null,'ranges'=>[['from'=>null,'to'=>'']]]]]);
        foreach(['Unit','Value','Range To'] as $field)self::assertNull($rows['size_guides'][0][$field]);
        self::assertSame('UNREVIEWED',$rows['size_guides'][0]['_approval_status']);
    }
    public function testShippingIndexDoesNotInventDestinationRates(): void
    {
        $rows=$this->map(['kind'=>'shipping','blueprint_id'=>5,'provider_id'=>9],['data'=>[['id'=>'1','type'=>'shipping_method','attributes'=>['name'=>'standard']]]]);
        foreach(['Country / Zone','First Item Amount','Currency'] as $field)self::assertNull($rows['shipping'][0][$field]);
        self::assertSame('DESTINATION_RATES_MISSING',$rows['gaps'][0]['Gap']);
    }
    public function testEmptyDestinationQuotesProduceAnExplicitEvidenceGap(): void
    {
        $rows=$this->map(['kind'=>'shipping_method','blueprint_id'=>5,'provider_id'=>9,'method'=>'standard'],['data'=>[]]);
        self::assertSame([],$rows['shipping']);self::assertSame('DESTINATION_QUOTES_EMPTY',$rows['gaps'][0]['Gap']);self::assertSame('OPEN',$rows['gaps'][0]['Status']);
    }
    public function testDestinationQuoteRetainsRawAmountsWithoutTaxOrUnitsCertification(): void
    {
        $data=['data'=>[['id'=>'12','type'=>'variant_shipping_standard_us','attributes'=>['shippingType'=>'standard','country'=>['code'=>'US'],'variantId'=>12,'shippingPlanId'=>'source-plan','handlingTime'=>['from'=>2,'to'=>4],'shippingCost'=>['firstItem'=>['amount'=>499,'currency'=>'USD'],'additionalItems'=>['amount'=>199,'currency'=>'USD']]]]]];
        $rows=$this->map(['kind'=>'shipping_method','blueprint_id'=>5,'provider_id'=>9,'method'=>'standard'],$data);
        self::assertSame('499',$rows['shipping'][0]['First Item Amount']);self::assertSame('USD',$rows['shipping'][0]['Currency']);self::assertNull($rows['shipping'][0]['Tax Amount']);self::assertSame('RAW_PROVIDER_AMOUNT_UNITS_UNVERIFIED',$rows['shipping'][0]['Rate Units']);
    }
}
