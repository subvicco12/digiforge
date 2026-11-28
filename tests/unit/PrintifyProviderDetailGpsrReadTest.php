<?php
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\{PrintifyApiRequest,PrintifyApiReadEvidence,PrintifyCatalogReadContract,PrintifyResearchMapper};
require_once __DIR__.'/../../includes/POD/PrintifyApiRequest.php';
require_once __DIR__.'/../../includes/POD/PrintifyApiReadEvidence.php';
require_once __DIR__.'/../../includes/POD/PrintifyCatalogReadContract.php';
require_once __DIR__.'/../../includes/POD/PrintifyResearchMapper.php';
final class PrintifyProviderDetailGpsrReadTest extends TestCase {
 private function selection():array{return ['product_id'=>'product-1','blueprint_id'=>5,'provider_id'=>410,'variant_ids'=>[12]];}
 private function provider():array{return ['id'=>410,'title'=>'Printful','decoration_methods'=>['dtg','embroidery'],'production_time'=>['average'=>1.3,'unit'=>'business_days']];}
 public function testOfficialProviderExampleAndUnknownTime():void{
  $r=['kind'=>'provider','blueprint_id'=>5,'provider_id'=>410];
  self::assertSame('/v1/catalog/blueprints/5/print_providers/410.json',PrintifyCatalogReadContract::endpoint($r));
  self::assertSame($this->provider(),PrintifyCatalogReadContract::select($this->provider(),$r));
  $data=$this->provider();$data['production_time']=null;self::assertNull(PrintifyCatalogReadContract::select($data,$r)['production_time']);
 }
 public function testProviderReceiptMapperPreservesUnknownTime():void{
  $data=$this->provider();$data['production_time']=null;$r=['kind'=>'provider','blueprint_id'=>5,'provider_id'=>410];
  $receipt=['request'=>$r,'endpoint_path'=>PrintifyCatalogReadContract::endpoint($r),'observed_at'=>'2026-11-08T00:00:00Z','response_sha256'=>str_repeat('a',64),'sha256'=>str_repeat('b',64),'grant_sha256'=>str_repeat('c',64),'request_sha256'=>str_repeat('d',64),'integration_id'=>7,'environment'=>'test','scope'=>['product_program'=>'PERSONALIZED_POD'],'data'=>$data];
  $out=PrintifyResearchMapper::map($receipt);self::assertNull($out['providers'][0]['Production Time']);self::assertSame('410',$out['providers'][0]['Provider ID']);self::assertSame('["dtg","embroidery"]',$out['providers'][0]['Decoration Methods']);
 }
 public function testInvalidProviderFixturesFailClosed():void{
  $base=$this->provider();
  foreach([array_replace($base,['id'=>411]),array_replace($base,['production_time'=>['average'=>'1.3','unit'=>'business_days']]),array_replace($base,['decoration_methods'=>[['dtg']]]),$base+['url'=>'https://evil.test']] as $data){try{PrintifyCatalogReadContract::select($data,['kind'=>'provider','blueprint_id'=>5,'provider_id'=>410]);self::fail('Accepted malformed provider');}catch(\InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testOfficialGpsrShapeIsBoundToExactRequest():void{
  $input=$this->selection();$request=PrintifyApiRequest::prepare('PRODUCT_GPSR_READ','42',$input);
  self::assertSame('/v1/shops/42/products/product-1/gpsr.json',$request['endpoint']);self::assertSame($input,$request['input']);self::assertSame('GET',$request['method']);
  // Adapted official example: documented GPSR endpoint returns title/text rows, without identities.
  $rows=[['title'=>'GPSR information','text'=>'John Doe, test@example.com, 123 Main St, New York, NY, 10001, US'],['title'=>'Care instructions','text'=>'Machine wash: warm (max 40C or 105F)']];
  $out=PrintifyApiReadEvidence::project('PRODUCT_GPSR_READ',$rows,'42',$input);
  self::assertSame($rows,$out['gpsr']);self::assertSame('42',$out['provider_shop_id']);self::assertSame('product-1',$out['product_id']);self::assertNull($out['supplier_certified']);
 }
 public function testGpsrRejectsUnsupportedFieldsTypesUrlsAndMissingSelection():void{
  foreach([[['title'=>'GPSR','text'=>[]]],[['title'=>'GPSR','text'=>'javascript:alert(1)']],[['title'=>'GPSR','text'=>'https://127.0.0.1']],[['title'=>'GPSR','text'=>'ok','product_id'=>'other']],[['title'=>'GPSR','text'=>"bad\ntext"]]] as $data){try{PrintifyApiReadEvidence::project('PRODUCT_GPSR_READ',$data,'42',$this->selection());self::fail('Accepted malformed GPSR');}catch(\InvalidArgumentException){self::assertTrue(true);}}
  $this->expectException(\InvalidArgumentException::class);PrintifyApiRequest::prepare('PRODUCT_GPSR_READ','42',['product_id'=>'product-1']);
 }
}
