<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\{PhotoCrop,RasterArtworkCodec};
require_once __DIR__.'/../../includes/POD/RasterArtworkCodec.php';
if(is_file(__DIR__.'/../../includes/POD/PhotoCrop.php'))require_once __DIR__.'/../../includes/POD/PhotoCrop.php';
final class PhotoCropTest extends TestCase {
 public function testExactCropPreservesPixelsAndBindsGeometry():void {self::assertTrue(class_exists(PhotoCrop::class),'Explicit photo crop missing');$rgba='';for($i=0;$i<16;$i++)$rgba.=chr($i)."\x20\x30\xff";$png=RasterArtworkCodec::encode(4,4,$rgba);$r=PhotoCrop::apply($png,['x'=>1,'y'=>1,'width'=>2,'height'=>2]);$d=RasterArtworkCodec::decode($r['png']);self::assertSame(substr($rgba,20,8).substr($rgba,36,8),$d['rgba']);self::assertSame(2,$d['width_px']);self::assertSame(hash('sha256',$png),$r['provenance']['source_sha256']);self::assertSame($r,PhotoCrop::apply($png,['x'=>1,'y'=>1,'width'=>2,'height'=>2]));self::assertFalse($r['provenance']['resampled']);}
 /** @dataProvider invalid */
 public function testOutOfBoundsAmbiguousOrEmptyCropDenies(array $rect):void {self::assertTrue(class_exists(PhotoCrop::class));$this->expectException(\InvalidArgumentException::class);PhotoCrop::apply(RasterArtworkCodec::encode(4,4,str_repeat("\xff",64)),$rect);}
 public static function invalid():array{return [[['x'=>-1,'y'=>0,'width'=>2,'height'=>2]],[['x'=>0,'y'=>0,'width'=>0,'height'=>2]],[['x'=>3,'y'=>0,'width'=>2,'height'=>2]],[['x'=>0.5,'y'=>0,'width'=>2,'height'=>2]],[['x'=>0,'y'=>0,'width'=>2,'height'=>2,'fit'=>'stretch']],[[]]];}
}
