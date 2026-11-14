<?php
declare(strict_types=1);
use DigiForge\Portal\ApprovedAcceptancePolicy as Policy;
final class ApprovedPolicyAdversarialTest extends WP_UnitTestCase
{
 private function definition():array {
  $r=['kind'=>'ORDER','id'=>'order1','shop_key'=>'personalized_pod','product_id'=>'p1','product_version'=>'v1','supplier_id'=>'s1','occurred_at'=>'2026-01-02T00:00:00Z','source_reference'=>'local://synthetic/cohort','visit_id'=>null];
  $unit=$r;unset($unit['visit_id']);$unit=array_replace($unit,['kind'=>'UNIT','id'=>'unit1','order_id'=>'order1','on_time'=>false,'exception'=>false,'defect'=>'CLEARED']);
  return ['policy'=>Policy::rules('BP-portal-17-product-performance'),'environment'=>'sandbox','subject'=>['scope'=>'PRODUCT_VERSION','product_id'=>'p1','product_version'=>'v1','supplier_id'=>null],'period_start'=>'2026-01-01T00:00:00Z','period_end'=>'2026-02-01T00:00:00Z','source_records'=>[['shop_key'=>'personalized_pod','reference'=>'local://synthetic/cohort','sha256'=>hash('sha256','synthetic-not-authentic')]],'observations'=>['coverage'=>array_fill_keys(['visits','orders','units','defects'],true),'records'=>[$r,$unit]]];
 }
 public static function nullableDimensions():array {return [['on_time'],['exception']];}
 /** @dataProvider nullableDimensions */
 public function testNullAndFalseDuplicateFactsConflictRegardlessOfRowOrder(string $field):void {
  foreach([false,true]as $reverse){$d=$this->definition();$other=$d['observations']['records'][1];$other[$field]=null;$d['observations']['records'][]=$other;if($reverse)$d['observations']['records']=array_reverse($d['observations']['records']);self::assertFalse(Policy::valid('personalized_pod','BP-portal-17-product-performance',$d),'Unknown and known false are conflicting facts, not equivalent duplicates.');}
 }
 public function testIdenticalFactsWithDifferentAssociativeKeyOrderStillDeduplicate():void {$d=$this->definition();$d['observations']['records'][]=array_reverse($d['observations']['records'][1],true);$o=Policy::evaluate('personalized_pod','BP-portal-17-product-performance',$d);self::assertSame(1,$o['metrics']['fulfilled_units']['value']);self::assertSame(0.0,$o['metrics']['defect_percent']['value']);}
 public function testIncompleteDimensionNumeratorsStayNullInsteadOfFalseZero():void {
  $d=$this->definition();$d['observations']['records'][1]['on_time']=null;$d['observations']['records'][1]['exception']=null;$o=Policy::evaluate('personalized_pod','BP-portal-17-product-performance',$d);foreach(['timeliness'=>'on_time_units','exceptions'=>'exception_units']as $metric=>$field){self::assertSame('UNKNOWN',$o['metrics'][$metric]['state']);self::assertNull($o['metrics'][$metric][$field]);self::assertSame(1,$o['metrics'][$metric]['eligible_units']);}
 }
 public function testEntirelyMissingPopulationCannotClaimCalculatedState():void {
  $d=$this->definition();$d['observations']['coverage']=array_fill_keys(['visits','orders','units','defects'],false);$d['observations']['records']=[];$o=Policy::evaluate('personalized_pod','BP-portal-17-product-performance',$d);self::assertSame('UNKNOWN',$o['state']);self::assertSame('UNKNOWN',$o['value_state']);self::assertFalse($o['external_execution_authorized']);
 }
}
