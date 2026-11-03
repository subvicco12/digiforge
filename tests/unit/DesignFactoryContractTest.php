<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\Research\DesignFactoryContract as Contract;
$path=__DIR__.'/../../includes/Research/DesignFactoryContract.php';if(is_file($path))require_once $path;
require_once __DIR__.'/../../includes/Research/OpportunityDesignContract.php';
require_once __DIR__.'/../../includes/Research/CommercialResearchContract.php';
final class DesignFactoryContractTest extends TestCase
{
 protected function setUp():void {self::assertTrue(class_exists(Contract::class),'Design specification and asset contract required');}
 public static function choices():array {return ['layout_notes'=>'Original ring outside reserved native name region','typography'=>'Licensed serif native text, generous line spacing','font_license_reference'=>'Human-reviewed font license record','palette'=>['#123456','#ffffff'],'native_regions'=>[['name'=>'names','kind'=>'text','x'=>200,'y'=>400,'width'=>1800,'height'=>300]],'asset_requirements'=>['Original vector paths; names remain native']];}
 private function context():array {$f=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);return ['research'=>$f['research'],'concept'=>$f['response']['concepts'][0],'selected_concept'=>1,'commercial_hash'=>str_repeat('a',64),'briefs'=>$f['response']['concepts']];}
 public function testSpecificationBindsConceptAndUnknownSupplierMargins():void {$s=Contract::specification($this->context(),'fl03_mug_11oz',self::choices());self::assertSame(2475,$s['geometry']['width_px']);self::assertSame(1155,$s['geometry']['height_px']);self::assertNull($s['geometry']['supplier_bleed_px']);self::assertFalse($s['geometry']['supplier_certified']);self::assertSame('Two interlocking constellation rings',$s['concept']['visual_direction']);self::assertStringContainsString('NOT finished artwork',Contract::prompt($s));}
 public function testPosterProfileIsFamilyBound():void {$c=$this->context();$c['research']['product_family']='poster';$s=Contract::specification($c,'fl04_poster_8x10',self::choices());self::assertSame(2418,$s['geometry']['width_px']);self::assertSame(3018,$s['geometry']['height_px']);}
 public function testCannotUsePosterGeometryForMug():void {$this->expectException(\InvalidArgumentException::class);Contract::specification($this->context(),'fl04_poster_8x10',self::choices());}
 public function testNativeRegionsMustFitCanvas():void {$c=self::choices();$c['native_regions'][0]['width']=10000;$this->expectException(\InvalidArgumentException::class);Contract::specification($this->context(),'fl03_mug_11oz',$c);}
 public function testSafeSvgRetainsEditableSourceAndNativeLayers():void {$s=Contract::specification($this->context(),'fl03_mug_11oz',self::choices());$svg=file_get_contents(__DIR__.'/../fixtures/design-factory/mug-original.svg');self::assertSame($svg,Contract::svg($svg,$s));}
 /** @dataProvider unsafeSvg */
 public function testUnsafeOrUnsupportedSvgDenied(string $svg):void {$s=Contract::specification($this->context(),'fl03_mug_11oz',self::choices());$this->expectException(\InvalidArgumentException::class);Contract::svg($svg,$s);}
 public static function unsafeSvg():array {return [[''],['<svg/>'],['<svg xmlns="http://www.w3.org/2000/svg" width="2475" height="1155" viewBox="0 0 2475 1155"/>'],['<svg xmlns="http://www.w3.org/2000/svg" width="2475" height="1155" viewBox="0 0 2475 1155"><script>alert(1)</script></svg>'],['<svg xmlns="http://www.w3.org/2000/svg" width="2475" height="1155" viewBox="0 0 2475 1155"><image href="https://example.org/a.png"/></svg>'],[str_repeat('x',60001)]];}
 public static function png(int $w=2475,int $h=1155,int $ppm=11811):string {$chunk=static fn($t,$d)=>pack('N',strlen($d)).$t.$d.hash('crc32b',$t.$d,true);return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$w,$h,8,2,0,0,0)).$chunk('pHYs',pack('NNC',$ppm,$ppm,1)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\xff",$w*3),$h))).$chunk('IEND','');}
 public function testActualPngBytesAnd300DpiVerified():void {$s=Contract::specification($this->context(),'fl03_mug_11oz',self::choices());$bytes=self::png();$r=Contract::raster($bytes,$s);self::assertSame(hash('sha256',$bytes),$r['sha256']);self::assertSame('image/png',$r['mime_type']);self::assertFalse($r['editable']);}
 public function testCorruptPngAndHeaderOnlyImageRejected():void {$s=Contract::specification($this->context(),'fl03_mug_11oz',self::choices());$this->expectException(\InvalidArgumentException::class);Contract::raster(substr(self::png(),0,-10),$s);}
 public function testWrongRasterDimensionsRejected():void {$s=Contract::specification($this->context(),'fl03_mug_11oz',self::choices());$this->expectException(\InvalidArgumentException::class);Contract::raster(self::png(1,1),$s);}
 public function testWrongRasterDpiRejected():void {$s=Contract::specification($this->context(),'fl03_mug_11oz',self::choices());$this->expectException(\InvalidArgumentException::class);Contract::raster(self::png(2475,1155,2835),$s);}
 /** @dataProvider malformedColorChunks */
 public function testMalformedPngColorMetadataRejected(array $chunks):void {$s=Contract::specification($this->context(),'fl03_mug_11oz',self::choices());$extra='';foreach($chunks as [$type,$data])$extra.=pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);$png=self::png();$png=substr($png,0,33).$extra.substr($png,33);$this->expectException(\InvalidArgumentException::class);Contract::raster($png,$s);}
 public static function malformedColorChunks():array {return [[ [['sRGB',chr(255)]] ],[ [['sRGB',chr(0)],['sRGB',chr(0)]] ],[ [['gAMA',pack('N',0)]] ],[ [['gAMA',pack('N',45455)],['gAMA',pack('N',45455)]] ],[ [['cHRM',pack('N8',200000,0,0,0,0,0,0,0)]] ]];}
}
