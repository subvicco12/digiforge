<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyCatalogReadContract as Contract;
if(is_file(__DIR__.'/../../includes/POD/PrintifyCatalogReadContract.php'))require_once __DIR__.'/../../includes/POD/PrintifyCatalogReadContract.php';
final class PrintifyCatalogReadContractTest extends TestCase {
 private function grant():array {return ['capability'=>'printify.catalog.read','integration_id'=>1,'account_id'=>2,'business_id'=>'b','store_id'=>'s','product_program'=>'PERSONALIZED_POD','expires_at'=>'2026-10-12T12:30:00Z','max_requests'=>2,'requests'=>[['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202]]];}
 public function testExactGetScopeAndExpiryAreBounded():void {
  self::assertTrue(class_exists(Contract::class),'The narrowly scoped catalog read contract must exist.');
  $g=Contract::normalize($this->grant(),strtotime('2026-10-12T12:00:00Z'));self::assertSame('/v1/catalog/blueprints/101/print_providers/2/variants.json',Contract::endpoint($g['requests'][0]));self::assertSame('printify.catalog.read',$g['capability']);
 }
 public function testUnsupportedOperationsUnknownKeysAndRelativeTimeFailClosed():void {
  self::assertTrue(class_exists(Contract::class));$now=strtotime('2026-10-12T12:00:00Z');
  $cases=[];$x=$this->grant();$x['requests'][0]['kind']='order';$cases[]=$x;$x=$this->grant();$x['requests'][0]['url']='https://evil.test';$cases[]=$x;$x=$this->grant();$x['expires_at']='+1 hour';$cases[]=$x;$x=$this->grant();$x['expires_at']='2026-10-12T12:30:00';$cases[]=$x;$x=$this->grant();$x['max_requests']=11;$cases[]=$x;$x=$this->grant();$x['expires_at']='2026-10-13T12:00:00Z';$cases[]=$x;
  foreach($cases as $case){$denied=false;try{Contract::normalize($case,$now);}catch(InvalidArgumentException){$denied=true;}self::assertTrue($denied);}
 }
 public function testVariantResponseReturnsOnlyTheExplicitVariant():void {
  self::assertTrue(class_exists(Contract::class));$request=$this->grant()['requests'][0];self::assertSame(['id'=>202,'title'=>'synthetic'],Contract::select(['variants'=>[['id'=>203],['id'=>202,'title'=>'synthetic']]],$request));$this->expectException(InvalidArgumentException::class);Contract::select(['variants'=>[['id'=>203]]],$request);
 }
}
