<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\{RasterArtworkCodec,ProductMockupRenderer};
require_once __DIR__.'/../../includes/POD/RasterArtworkCodec.php';
if(is_file(__DIR__.'/../../includes/POD/ProductMockupRenderer.php'))require_once __DIR__.'/../../includes/POD/ProductMockupRenderer.php';
require_once __DIR__.'/../../includes/POD/ProductMockupRegistry.php';
final class ProductMockupRendererTest extends TestCase
{
 public function testUvProjectionPreservesOccludedBaseAndAppliesLightingDeterministically():void {
  self::assertTrue(class_exists(ProductMockupRenderer::class),'Reviewed UV mockup renderer is missing');
  $layers=$this->layers();$art=RasterArtworkCodec::encode(2,2,str_repeat("\xff\x80\x40\xff",4));
  $output=ProductMockupRenderer::compose($layers,$art);self::assertSame($output,ProductMockupRenderer::compose($layers,$art));$pixels=RasterArtworkCodec::decode($output);
  self::assertSame("\x20\x40\x60\xff",substr($pixels['rgba'],0,4));self::assertSame("\x80\x40\x20\xff",substr($pixels['rgba'],(3*8+3)*4,4));
 }
 public function testPremultipliedInterpolationDoesNotCreateHiddenColorHalos():void {
  self::assertTrue(class_exists(ProductMockupRenderer::class),'Reviewed UV mockup renderer is missing');$layers=$this->layers();$u=RasterArtworkCodec::decode($layers['u']);for($i=0;$i<strlen($u['rgba']);$i+=4)$u['rgba']=substr_replace($u['rgba'],"\x80\0\0\xff",$i,4);$layers['u']=RasterArtworkCodec::encode(8,8,$u['rgba']);$layers['lighting']=RasterArtworkCodec::encode(8,8,str_repeat("\xff\xff\xff\xff",64));$art=RasterArtworkCodec::encode(2,2,"\xff\0\0\0\0\0\xff\xff\xff\0\0\0\0\0\xff\xff");$png=ProductMockupRenderer::compose($layers,$art);$p=RasterArtworkCodec::decode($png);$pixel=substr($p['rgba'],(3*8+3)*4,4);self::assertLessThanOrEqual(17,ord($pixel[0]));self::assertGreaterThan(170,ord($pixel[2]));
 }
 public function testMalformedMapsAndUnboundedCanvasesFailClosed():void {
  self::assertTrue(class_exists(ProductMockupRenderer::class),'Reviewed UV mockup renderer is missing');$layers=$this->layers();$layers['u']=RasterArtworkCodec::encode(8,8,str_repeat("\0\0\x01\xff",64));$this->expectException(InvalidArgumentException::class);ProductMockupRenderer::compose($layers,RasterArtworkCodec::encode(2,2,str_repeat("\xff",16)));
 }
 public function testDimensionDriftAndEmptyCoverageFailClosed():void {
  self::assertTrue(class_exists(ProductMockupRenderer::class),'Reviewed UV mockup renderer is missing');$layers=$this->layers();$layers['coverage']=RasterArtworkCodec::encode(8,8,str_repeat("\0\0\0\xff",64));$this->expectException(InvalidArgumentException::class);ProductMockupRenderer::compose($layers,RasterArtworkCodec::encode(2,2,str_repeat("\xff",16)));
 }
 public function testSyntheticEvidenceIsLimitedToLocalOfflineOrders():void {
  foreach(['production','staging','development','unknown']as $site)self::assertFalse(DigiForge\POD\ProductMockupRegistry::syntheticEnvironmentAllowed($site,'sandbox'));
  self::assertTrue(DigiForge\POD\ProductMockupRegistry::syntheticEnvironmentAllowed('local','sandbox'));self::assertTrue(DigiForge\POD\ProductMockupRegistry::syntheticEnvironmentAllowed('local','test'));self::assertFalse(DigiForge\POD\ProductMockupRegistry::syntheticEnvironmentAllowed('local','production'));
 }
 public function testProjectedLayerDimensionMismatchIsRejected():void {
  $layers=$this->layers();$layers['lighting']=RasterArtworkCodec::encode(4,4,str_repeat("\xff",64));$this->expectException(InvalidArgumentException::class);ProductMockupRenderer::validateLayers($layers);
 }
 public function testProjectedCanvasLimitIsEnforcedBeforeComposition():void {
  $layers=$this->layers();$layers['base']=RasterArtworkCodec::encode(513,512,str_repeat("\xff",513*512*4));$this->expectException(InvalidArgumentException::class);ProductMockupRenderer::validateLayers($layers);
 }
 public function testNonlinearUvCoordinatesSelectTheSuppliedArtworkLocation():void {
  $layers=$this->layers();$u=RasterArtworkCodec::decode($layers['u']);$v=RasterArtworkCodec::decode($layers['v']);$at=(3*8+3)*4;$u['rgba']=substr_replace($u['rgba'],"\xff\xff\0\xff",$at,4);$v['rgba']=substr_replace($v['rgba'],"\0\0\0\xff",$at,4);$layers['u']=RasterArtworkCodec::encode(8,8,$u['rgba']);$layers['v']=RasterArtworkCodec::encode(8,8,$v['rgba']);$art=RasterArtworkCodec::encode(2,2,"\xff\0\0\xff\0\xff\0\xff\0\0\xff\xff\xff\xff\xff\xff");$pixels=RasterArtworkCodec::decode(ProductMockupRenderer::compose($layers,$art));self::assertSame("\0\x80\0\xff",substr($pixels['rgba'],$at,4));
 }
 private function layers():array {
  $base=str_repeat("\x20\x40\x60\xff",64);$u=$v=$coverage='';for($y=0;$y<8;$y++)for($x=0;$x<8;$x++){$u.=pack('n',intdiv($x*65535,7))."\0\xff";$v.=pack('n',intdiv($y*65535,7))."\0\xff";$a=$x>0&&$x<7&&$y>0&&$y<7?255:0;$coverage.=str_repeat(chr($a),3)."\xff";}
  return ['base'=>RasterArtworkCodec::encode(8,8,$base),'u'=>RasterArtworkCodec::encode(8,8,$u),'v'=>RasterArtworkCodec::encode(8,8,$v),'coverage'=>RasterArtworkCodec::encode(8,8,$coverage),'lighting'=>RasterArtworkCodec::encode(8,8,str_repeat("\x80\x80\x80\xff",64))];
 }
}
