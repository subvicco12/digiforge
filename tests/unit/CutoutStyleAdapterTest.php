<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\{CutoutStyleAdapter,RasterArtworkCodec,RepeatLayout};
foreach(['RasterArtworkCodec','CutoutStyleAdapter','RepeatLayout']as $class)require_once __DIR__.'/../../includes/POD/'.$class.'.php';
final class CutoutStyleAdapterTest extends TestCase
{
 private function images():array {
  $source=RasterArtworkCodec::encode(9,9,str_repeat("\xff\x20\x10\xff",81));$rgba=str_repeat("\0",324);$rgba[(4*9+4)*4+3]="\xff";return [$source,RasterArtworkCodec::encode(9,9,$rgba)];
 }
 public static function styles():array {return [['CS01',[]],['CS02',['radius'=>1]],['CS03',['radius'=>1,'color'=>'#00ff00']],['CS04',['radius'=>1,'dx'=>1,'dy'=>0,'opacity'=>128]],['CS05',['border'=>1,'color'=>'#0000ff']],['CS06',[]]];}
 /** @dataProvider styles */
 public function testAllStylesUseActualReviewedAlphaAndReproducePixels(string $style,array $options):void {
  [$source,$mask]=$this->images();$result=CutoutStyleAdapter::apply($source,$mask,$style,$options);self::assertSame($result,CutoutStyleAdapter::apply($source,$mask,$style,$options));$pixels=RasterArtworkCodec::decode($result);self::assertSame(9,$pixels['width_px']);self::assertSame(0,ord($pixels['rgba'][3]));self::assertSame(255,ord($pixels['rgba'][(4*9+4)*4+3]));if($style==='CS06')self::assertSame($pixels['rgba'][(4*9+4)*4],$pixels['rgba'][(4*9+4)*4+1]);
 }
 public function testMaskDimensionsCannotBeGuessedOrResized():void {
  [$source]=$this->images();$this->expectException(InvalidArgumentException::class);CutoutStyleAdapter::apply($source,RasterArtworkCodec::encode(1,1,"\xff\xff\xff\xff"),'CS01',[]);
 }
 public function testOpaqueMaskDoesNotPretendToRemoveBackground():void {
  [$source]=$this->images();$this->expectException(InvalidArgumentException::class);CutoutStyleAdapter::apply($source,$source,'CS01',[]);
 }
 public function testStylingCannotEscapePadding():void {
  [$source,$mask]=$this->images();$this->expectException(InvalidArgumentException::class);CutoutStyleAdapter::apply($source,$mask,'CS02',['radius'=>4]);
 }
 public function testWorkLimitAndUnknownControlsFailClosed():void {
  [$source,$mask]=$this->images();$this->expectException(InvalidArgumentException::class);CutoutStyleAdapter::apply($source,$mask,'CS01',['invent_background'=>true]);
 }
 public function testLosslessRotationAndRepeatSequencePreserveActualPixels():void {
  $original=RasterArtworkCodec::encode(2,1,"\xff\0\0\xff\0\xff\0\xff");$rotated=CutoutStyleAdapter::rotate($original,90);self::assertSame(1,RasterArtworkCodec::decode($rotated)['width_px']);self::assertSame($original,CutoutStyleAdapter::rotate($rotated,270));$tiles=RepeatLayout::expand(['type'=>'image','field'=>'face_1','x'=>1,'y'=>1,'width'=>2,'height'=>2,'repeat'=>['columns'=>2,'rows'=>2,'gap_x'=>1,'gap_y'=>1,'rotations'=>[0,90,180]]],8,8);self::assertSame([0,90,180,0],array_column($tiles,'rotation'));
 }
 public function testUnsupportedAngleIsNotSilentlyApproximated():void {
  [$source]=$this->images();$this->expectException(InvalidArgumentException::class);CutoutStyleAdapter::rotate($source,12);
 }
}
