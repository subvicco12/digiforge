<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyReconciliationWorkflow;
require_once __DIR__.'/../../includes/POD/PrintifyReconciliationWorkflow.php';
final class PrintifyReconciliationCoverageTest extends TestCase{
 private function page(int $page,int $last,int $per,array $ids):array{return ['operation'=>'ORDERS_READ','data'=>['current_page'=>$page,'last_page'=>$last,'per_page'=>$per,'data'=>array_map(static fn($id)=>['id'=>'o'.$id],$ids)]];}
 public function testUniformCompletePagesAreRequired():void{self::assertTrue(method_exists(PrintifyReconciliationWorkflow::class,'pageCoverage'),'Complete coverage validator missing');self::assertTrue(PrintifyReconciliationWorkflow::pageCoverage([$this->page(1,2,10,range(1,10)),$this->page(2,2,10,[11])]));}
 public function testMixedLimitsCannotHideOrdersBetweenPages():void{self::assertFalse(PrintifyReconciliationWorkflow::pageCoverage([$this->page(1,2,6,range(1,6)),$this->page(2,2,10,[11])]));}
 public function testPartialAndDuplicatePagesFailClosed():void{self::assertFalse(PrintifyReconciliationWorkflow::pageCoverage([$this->page(1,2,10,[1]),$this->page(2,2,10,[11])]));self::assertFalse(PrintifyReconciliationWorkflow::pageCoverage([$this->page(1,2,10,range(1,10)),$this->page(2,2,10,[10])]));}
 public function testMissingCoverageAndEmptyFirstPage():void{self::assertFalse(PrintifyReconciliationWorkflow::pageCoverage([$this->page(2,2,10,[11])]));self::assertTrue(PrintifyReconciliationWorkflow::pageCoverage([$this->page(1,1,10,[])]));}
}
