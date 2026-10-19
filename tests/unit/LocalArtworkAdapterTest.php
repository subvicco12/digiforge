<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\RasterArtworkCodec;
use DigiForge\POD\BitmapFontAdapter;
use DigiForge\POD\RasterPdfAdapter;
foreach(['RasterArtworkCodec','BitmapFontAdapter','RasterPdfAdapter'] as $adapter){$file=__DIR__.'/../../includes/POD/'.$adapter.'.php';if(is_file($file))require_once $file;}
final class LocalArtworkAdapterTest extends TestCase
{
    public function testRasterRoundTripDecodesPixelsRatherThanOnlyHeaders():void
    {
        self::assertTrue(class_exists(RasterArtworkCodec::class), 'Bounded raster codec is missing.');
        $rgba="\xff\x00\x00\xff\x00\xff\x00\x80";
        $png=RasterArtworkCodec::encode(2,1,$rgba);$decoded=RasterArtworkCodec::decode($png);
        self::assertSame($rgba,$decoded['rgba']);self::assertSame(2,$decoded['width_px']);
        self::assertSame($png,RasterArtworkCodec::encode(2,1,$decoded['rgba']));
    }
    public function testCorruptIdatCrcCannotPassRasterQa():void
    {
        self::assertTrue(class_exists(RasterArtworkCodec::class),'Bounded raster codec is missing.');
        $bad=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aR9sAAAAASUVORK5CYII=');
        $this->expectException(InvalidArgumentException::class);RasterArtworkCodec::decode($bad);
    }
    public function testTruncatedOrOversizedRasterFailsClosed():void
    {
        self::assertTrue(class_exists(RasterArtworkCodec::class),'Bounded raster codec is missing.');
        $this->expectException(InvalidArgumentException::class);RasterArtworkCodec::encode(10001,10001,'');
    }
    private function png(string $raw,int $filter=0):string
    {
        $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',2,1,8,6,0,0,0)).$chunk('IDAT',gzcompress(chr($filter).$raw)).$chunk('IEND','');
    }
    public function testPngSubAndPaethFiltersReconstructActualPixels():void
    {
        $expected="\xff\x00\x00\xff\x00\xff\x00\xff";$filtered="\xff\x00\x00\xff\x01\xff\x00\x00";
        foreach([1,4]as $filter)self::assertSame($expected,RasterArtworkCodec::decode($this->png($filtered,$filter))['rgba']);
    }
    public function testCompressedExpansionBeyondDeclaredPixelsIsRejected():void
    {
        $this->expectException(InvalidArgumentException::class);RasterArtworkCodec::decode($this->png(str_repeat("\0",10000)));
    }
    public function testTruncatedPixelsAreRejectedEvenWithValidChunkCrc():void
    {
        $this->expectException(InvalidArgumentException::class);RasterArtworkCodec::decode($this->png("\0\0"));
    }
    public function testFontUsesActualVersionedGlyphsAndRefusesSubstitution():void
    {
        self::assertTrue(class_exists(BitmapFontAdapter::class),'Versioned font adapter is missing.');
        $font=['id'=>'offline-fixture','version'=>1,'width'=>3,'height'=>3,'glyphs'=>['A'=>['010','101','111'],' '=>['000','000','000']]];
        $svg=BitmapFontAdapter::rectangles($font,'A',0,0,2,'#000000');
        self::assertStringContainsString('x="2" y="0" width="2" height="2"',$svg);
        self::assertSame($svg,BitmapFontAdapter::rectangles($font,'A',0,0,2,'#000000'));
        $this->expectException(InvalidArgumentException::class);BitmapFontAdapter::rectangles($font,'B',0,0,2,'#000000');
    }
    public function testPdfIsSelfContainedAndQaRejectsAlteredDocument():void
    {
        self::assertTrue(class_exists(RasterPdfAdapter::class),'PDF adapter is missing.');
        $rgb="\xff\x00\x00\x00\xff\x00";$pdf=RasterPdfAdapter::encode(2,1,$rgb);
        $qa=RasterPdfAdapter::inspect($pdf);self::assertSame(2,$qa['width_px']);self::assertSame(hash('sha256',$rgb),$qa['pixel_sha256']);
        self::assertSame($pdf,RasterPdfAdapter::encode(2,1,$rgb));
        $this->expectException(InvalidArgumentException::class);RasterPdfAdapter::inspect($pdf."\n/JavaScript");
    }
}
