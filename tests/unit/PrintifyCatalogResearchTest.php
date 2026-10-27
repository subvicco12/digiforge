<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/POD/PrintifyCatalogReadContract.php';
require_once __DIR__.'/../../includes/POD/PrintifyCatalogPreview.php';
require_once __DIR__.'/../../includes/POD/PrintifyCatalogResearch.php';
use DigiForge\POD\PrintifyCatalogResearch as Research;
use PHPUnit\Framework\TestCase;
final class PrintifyCatalogResearchTest extends TestCase {
 public function testEightExactGetScopesWithoutNavigationOrMutation():void {
  $plan=Research::requests();self::assertCount(8,$plan);self::assertSame([410,410,217,217,61,61],array_column(array_slice($plan,2),'provider_id'));
  foreach($plan as $r)self::assertStringStartsWith('/v',DigiForge\POD\PrintifyCatalogReadContract::endpoint($r));
  $report=Research::compare([]);self::assertFalse($report['supplier_certified']);foreach($report['providers']as $p){self::assertSame('UNKNOWN',$p['variants_status']);self::assertSame('UNKNOWN',$p['dpi']);}
 }
 public function testDuplicateAndUnsafeScopesDeny():void {
  foreach([[410,410],['410'],[0],[1,2,3,4]]as $providers){try{Research::requests(49,$providers);self::fail('Invalid explicit scope accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testUnsupportedOrPromotedReceiptCannotBecomeResearchEvidence():void {
  $this->expectException(InvalidArgumentException::class);Research::compare([['state'=>'VERIFIED']]);
 }
 public function testValidShippingDiscoveryRemainsNotARateOrCertification():void {
  $request=['kind'=>'shipping','blueprint_id'=>49,'provider_id'=>410];$path=DigiForge\POD\PrintifyCatalogReadContract::endpoint($request);
  $e=['grant_sha256'=>hash('sha256','synthetic grant'),'account_id'=>1,'integration_id'=>2,'environment'=>'test','site_sha256'=>hash('sha256','synthetic installation'),'scope'=>['shop'=>'personalized_pod'],'request'=>$request,'state'=>'DRAFT_UNVERIFIED','supplier_certified'=>false,'mutation_authorized'=>false,'response_sha256'=>hash('sha256','synthetic fixture'),'observed_at'=>'2026-10-27T00:00:00Z','endpoint_path'=>$path,'data'=>['data'=>[['id'=>'1','type'=>'shipping_method','attributes'=>['name'=>'standard']]]]];
  $report=Research::compare([$e]);self::assertSame('UNKNOWN_UNTIL_EXPLICIT_METHOD_READ',$report['providers'][0]['destination_rates']);self::assertSame('UNKNOWN',$report['providers'][0]['commercial_rights']);
  $this->expectException(InvalidArgumentException::class);Research::compare([$e,$e]);
 }
 public function testPartialPaginationCannotBeAcceptedAsCompleteCatalogEvidence():void {
  $this->expectException(InvalidArgumentException::class);DigiForge\POD\PrintifyCatalogReadContract::select(['variants'=>[['id'=>123]],'next_page_url'=>'https://outside.invalid/page'],['kind'=>'variants','blueprint_id'=>49,'provider_id'=>410]);
 }
 public function testRectanglesStaySeparateFromDpiAndSafeZonesAndForeignScopesDeny():void {
  $request=['kind'=>'variants','blueprint_id'=>49,'provider_id'=>410];$e=['grant_sha256'=>hash('sha256','synthetic grant'),'account_id'=>1,'integration_id'=>2,'environment'=>'test','site_sha256'=>hash('sha256','synthetic installation'),'scope'=>['shop'=>'personalized_pod'],'request'=>$request,'state'=>'DRAFT_UNVERIFIED','supplier_certified'=>false,'mutation_authorized'=>false,'response_sha256'=>hash('sha256','synthetic variants'),'observed_at'=>'2026-10-27T00:00:00Z','endpoint_path'=>DigiForge\POD\PrintifyCatalogReadContract::endpoint($request),'data'=>['id'=>410,'variants'=>[['id'=>123,'decoration_methods'=>['dtg'],'placeholders'=>[['position'=>'front','decoration_method'=>'dtg','width'=>100,'height'=>200]]]]]];
  $p=Research::compare([$e])['providers'][0];self::assertSame(100,$p['catalog_rectangles'][0]['placeholders'][0]['width']);self::assertSame('CATALOG_RECTANGLES_ONLY',$p['catalog_rectangles'][0]['status']);self::assertSame('UNKNOWN',$p['safe_zone']);self::assertSame('UNKNOWN',$p['dpi']);
  $foreign=$e;$foreign['request']['provider_id']=217;$foreign['endpoint_path']=DigiForge\POD\PrintifyCatalogReadContract::endpoint($foreign['request']);$foreign['data']['id']=217;$foreign['grant_sha256']=hash('sha256','foreign grant');$this->expectException(InvalidArgumentException::class);Research::compare([$e,$foreign]);
 }

 public function testNonProductionResearchDeniesBeforeStorageOrCredentialAccess():void {
  $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../printify-research-environment-smoke.php');$output=[];$exit=0;exec($command,$output,$exit);self::assertSame(0,$exit,implode("\n",$output));self::assertStringContainsString('Non-production research denied',implode("\n",$output));
 }

}
