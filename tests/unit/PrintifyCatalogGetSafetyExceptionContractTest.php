<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyCatalogGetSafetyExceptionContract as ExceptionContract;
require_once __DIR__.'/../../includes/POD/PrintifyCatalogReadContract.php';
if(is_file(__DIR__.'/../../includes/POD/PrintifyCatalogGetSafetyExceptionContract.php'))require_once __DIR__.'/../../includes/POD/PrintifyCatalogGetSafetyExceptionContract.php';
final class PrintifyCatalogGetSafetyExceptionContractTest extends TestCase {
 private function grant():array{return ['capability'=>'printify.catalog.read','integration_id'=>1,'account_id'=>2,'business_id'=>'b','store_id'=>'s','product_program'=>'PERSONALIZED_POD','expires_at'=>'2026-10-12T12:30:00Z','max_requests'=>2,'requests'=>[['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202]]];}
 private function input():array{return ['capability'=>'printify.catalog.read','expires_at'=>'2026-10-12T12:20:00Z','acknowledge_catalog_get_only'=>true,'acknowledge_no_mutations'=>true,'acknowledge_locks_remain_on'=>true];}
 public function testSeparateExplicitApprovalCannotOutliveGrant():void{self::assertTrue(class_exists(ExceptionContract::class),'Separate catalog-GET safety exception contract must exist.');$v=ExceptionContract::normalize($this->input(),$this->grant(),strtotime('2026-10-12T12:00:00Z'));self::assertSame($this->input(),$v);$v['expires_at']='2026-10-12T12:31:00Z';$this->expectException(InvalidArgumentException::class);ExceptionContract::normalize($v,$this->grant(),strtotime('2026-10-12T12:00:00Z'));}
 public function testWrongCapabilityMissingAcknowledgementAndExtraOperationsFailClosed():void{self::assertTrue(class_exists(ExceptionContract::class));$cases=[];foreach(['printify','printify.order.submit','printify.catalog.write']as $cap){$v=$this->input();$v['capability']=$cap;$cases[]=$v;}foreach(['acknowledge_catalog_get_only','acknowledge_no_mutations','acknowledge_locks_remain_on']as $k){$v=$this->input();$v[$k]=false;$cases[]=$v;}$v=$this->input();$v['method']='POST';$cases[]=$v;foreach($cases as $v){$denied=false;try{ExceptionContract::normalize($v,$this->grant(),strtotime('2026-10-12T12:00:00Z'));}catch(InvalidArgumentException){$denied=true;}self::assertTrue($denied);}}
}
