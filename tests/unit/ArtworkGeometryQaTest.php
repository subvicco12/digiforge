<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\ArtworkGeometryQa;
if(is_file(__DIR__.'/../../includes/POD/ArtworkGeometryQa.php'))require_once __DIR__.'/../../includes/POD/ArtworkGeometryQa.php';
final class ArtworkGeometryQaTest extends TestCase
{
 private function area():array{return ['position'=>'front','width_px'=>100,'height_px'=>80,'dpi'=>300,'qa_geometry'=>['safe_zone'=>['x'=>5,'y'=>5,'width'=>90,'height'=>70],'seam_exclusions'=>[['x'=>45,'y'=>0,'width'=>10,'height'=>80]],'min_image_dpi'=>200]];}
 public function testMissingSupplierGeometryIsNeverAnInventedPass():void {self::assertTrue(class_exists(ArtworkGeometryQa::class));self::assertSame('MISSING_GEOMETRY',ArtworkGeometryQa::policy(['width_px'=>100,'height_px'=>80])['state']);}
 public function testExactReviewedGeometryAllowsSafePlacementAndActualResolution():void {self::assertTrue(class_exists(ArtworkGeometryQa::class));$policy=ArtworkGeometryQa::policy($this->area());self::assertSame('VERIFIED_LOCAL_GEOMETRY',$policy['state']);ArtworkGeometryQa::placement($policy,['x'=>5,'y'=>5,'width'=>30,'height'=>30],['width_px'=>20,'height_px'=>20]);self::assertFalse($policy['supplier_certified']);}
 public static function unsafeCases():array{return [[['x'=>4,'y'=>5,'width'=>30,'height'=>30],['width_px'=>100,'height_px'=>100]],[['x'=>40,'y'=>5,'width'=>20,'height'=>20],['width_px'=>100,'height_px'=>100]],[['x'=>5,'y'=>5,'width'=>30,'height'=>30],['width_px'=>19,'height_px'=>19]]];}
 /** @dataProvider unsafeCases */
 public function testSafeZoneSeamAndResolutionViolationsFailClosed(array $bounds,array $image):void {self::assertTrue(class_exists(ArtworkGeometryQa::class));$this->expectException(InvalidArgumentException::class);ArtworkGeometryQa::placement(ArtworkGeometryQa::policy($this->area()),$bounds,$image);}
 public function testMalformedPolicyDoesNotFallBackToUnknownGeometry():void {self::assertTrue(class_exists(ArtworkGeometryQa::class));$area=$this->area();$area['qa_geometry']['safe_zone']['width']=101;$this->expectException(InvalidArgumentException::class);ArtworkGeometryQa::policy($area);}
 public static function fittedSources():array{return [[[ 'width_px'=>30,'height_px'=>15]],[[ 'width_px'=>15,'height_px'=>30]]];}
 /** @dataProvider fittedSources */
 public function testContainPlacementUsesActualImageScaleNotLetterboxSize(array $image):void {self::assertTrue(class_exists(ArtworkGeometryQa::class));$policy=ArtworkGeometryQa::policy($this->area());$policy['min_image_dpi']=300;ArtworkGeometryQa::placement($policy,['x'=>5,'y'=>5,'width'=>30,'height'=>30],$image);self::assertFalse($policy['supplier_certified']);}

}
