<?php
declare(strict_types=1);

use DigiForge\POD\ArtworkGeometryQa;
use DigiForge\POD\LocalArtworkAdapter;
use DigiForge\POD\RasterArtworkCodec;
use DigiForge\POD\RasterPdfAdapter;
use PHPUnit\Framework\TestCase;

foreach (['ArtworkGeometryQa', 'RasterArtworkCodec', 'RasterPdfAdapter', 'DeterministicPersonalizationRenderer', 'LocalArtworkAdapter'] as $class) {
    require_once __DIR__.'/../../includes/POD/'.$class.'.php';
}

/** Existing reviewed synthetic fixture bounds; no supplier certificate or inferred thresholds. */
final class PrintResolutionAcceptanceTest extends TestCase
{
    private function policy(): array
    {
        // Exactly the existing ArtworkGeometryQaTest approved geometry/minimum.
        return ArtworkGeometryQa::policy([
            'position'=>'front', 'width_px'=>100, 'height_px'=>80, 'dpi'=>300,
            'qa_geometry'=>[
                'safe_zone'=>['x'=>5, 'y'=>5, 'width'=>90, 'height'=>70],
                'seam_exclusions'=>[['x'=>45, 'y'=>0, 'width'=>10, 'height'=>80]],
                'min_image_dpi'=>200,
            ],
        ]);
    }

    private function source(int $width): array
    {
        $bytes = RasterArtworkCodec::encode($width, 10, str_repeat("\xff\x00\x00\xff", $width * 10));
        return ['bytes'=>$bytes] + RasterArtworkCodec::decode($bytes);
    }

    public function testDecodedSourceAtReviewedMinimumProducesExactContainedPngAndPhysicalPdf(): void
    {
        $policy = $this->policy();
        $source = $this->source(20);
        $slot = ['x'=>5, 'y'=>5, 'width'=>30, 'height'=>30];
        ArtworkGeometryQa::placement($policy, $slot, $source);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="80" viewBox="0 0 100 80">'
            .'<rect x="0" y="0" width="100" height="80" fill="#ffffff"/>'
            .'<image x="5" y="5" width="30" height="30" preserveAspectRatio="xMidYMid meet" href="data:image/png;base64,'
            .base64_encode($source['bytes']).'"/></svg>';
        $png = LocalArtworkAdapter::convert($svg, 'png', $policy['dpi']);
        $pdf = LocalArtworkAdapter::convert($svg, 'pdf', $policy['dpi']);
        self::assertIsArray($png);
        self::assertIsArray($pdf);
        $raster = RasterArtworkCodec::decode($png['bytes']);
        $physical = RasterPdfAdapter::pixels($pdf['bytes']);
        self::assertSame($raster['rgba'], $physical['rgba']);
        self::assertSame(300, $physical['dpi']);
        self::assertStringContainsString('/MediaBox [0 0 24.000000 19.200000]', $pdf['bytes']);
        // Contain fit paints30×15, not30×30: both actual image axes are exactly200DPI.
        $white = "\xff\xff\xff\xff";
        $red = "\xff\x00\x00\xff";
        $expectedPixels = str_repeat($white, 100 * 80);
        $expectedRow = str_repeat($white, 5).str_repeat($red, 30).str_repeat($white, 65);
        for ($y = 12; $y < 27; ++$y) {
            $expectedPixels = substr_replace($expectedPixels, $expectedRow, $y * 100 * 4, 100 * 4);
        }
        self::assertSame($expectedPixels, $raster['rgba'], 'Actual adapter stretched, displaced or changed the contained source pixels.');
        self::assertSame(450, substr_count($raster['rgba'], $red));
        self::assertSame(200, intdiv($source['width_px'] * $policy['dpi'], 30));
        self::assertSame(200, intdiv($source['height_px'] * $policy['dpi'], 15));
        self::assertFalse($policy['supplier_certified']);
    }

    public function testDecodedSourceOnePixelBelowReviewedContainMinimumIsRejected(): void
    {
        $policy = $this->policy();
        $source = $this->source(19);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Actual source resolution is below reviewed placement minimum.');
        ArtworkGeometryQa::placement($policy, ['x'=>5, 'y'=>5, 'width'=>30, 'height'=>30], $source);
    }
}
