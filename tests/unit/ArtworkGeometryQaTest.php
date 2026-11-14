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

 private function bleedArea():array {$area=$this->area();$area['qa_geometry']['bleed']=['trim_box'=>['x'=>3,'y'=>4,'width'=>94,'height'=>72],'margins_px'=>['left'=>3,'right'=>3,'top'=>4,'bottom'=>4]];return $area;}
 public function testExplicitBleedGeometryPreservesProviderMarginsWithoutCertification():void {$policy=ArtworkGeometryQa::policy($this->bleedArea());self::assertSame('VERIFIED_LOCAL_BLEED_GEOMETRY',$policy['bleed_state']);self::assertSame(94,$policy['bleed']['trim_box']['width']);self::assertFalse($policy['supplier_certified']);}
 public function testMissingBleedEvidenceCannotBeInferredFromSafeZone():void {self::assertSame('MISSING_BLEED_GEOMETRY',ArtworkGeometryQa::policy($this->area())['bleed_state']);}
 public static function badBleed():array {return [['left',2],['right',-1],['top','4'],['bottom',null]];}
 /** @dataProvider badBleed */
 public function testBleedMarginsMustExactlyReconcileTrimAndFullCanvas(string $side,mixed $value):void {$area=$this->bleedArea();$area['qa_geometry']['bleed']['margins_px'][$side]=$value;$this->expectException(InvalidArgumentException::class);ArtworkGeometryQa::policy($area);}
 public function testSafeZoneCannotExtendIntoDeclaredBleed():void {$area=$this->bleedArea();$area['qa_geometry']['safe_zone']['x']=2;$this->expectException(InvalidArgumentException::class);ArtworkGeometryQa::policy($area);}

}
