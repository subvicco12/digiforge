<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\RepeatLayout;
if(is_file(__DIR__.'/../../includes/POD/RepeatLayout.php'))require_once __DIR__.'/../../includes/POD/RepeatLayout.php';
final class RepeatLayoutTest extends TestCase
{
 public function testExactIntegerGridIncludesGapsAndPreservesSourceSlot():void {
  self::assertTrue(class_exists(RepeatLayout::class),'Bounded repeat planner missing.');
  $slot=['type'=>'image','field'=>'photo','x'=>1,'y'=>2,'width'=>2,'height'=>3,'repeat'=>['columns'=>2,'rows'=>2,'gap_x'=>1,'gap_y'=>0]];
  $tiles=RepeatLayout::expand($slot,8,9);self::assertSame([[1,2],[4,2],[1,5],[4,5]],array_map(static fn($s)=>[$s['x'],$s['y']],$tiles));self::assertSame('photo',$tiles[3]['field']);self::assertArrayNotHasKey('repeat',$tiles[0]);
 }
 public static function invalidLayouts():array {return [[['columns'=>0,'rows'=>1,'gap_x'=>0,'gap_y'=>0]],[['columns'=>17,'rows'=>16,'gap_x'=>0,'gap_y'=>0]],[['columns'=>2,'rows'=>1,'gap_x'=>20,'gap_y'=>0]],[['columns'=>'2','rows'=>1,'gap_x'=>0,'gap_y'=>0]],[['columns'=>1,'rows'=>1,'gap_x'=>0,'gap_y'=>0,'rotate'=>true]]];}
 /** @dataProvider invalidLayouts */
 public function testInvalidAndExcessiveLayoutsFailClosed(array $repeat):void {
  self::assertTrue(class_exists(RepeatLayout::class));$this->expectException(InvalidArgumentException::class);RepeatLayout::expand(['type'=>'image','field'=>'photo','x'=>0,'y'=>0,'width'=>2,'height'=>2,'repeat'=>$repeat],20,20);
 }
}
