<?php
declare(strict_types=1);
use DigiForge\POD\ProductionTemplateContract as Contract;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/ProductionTemplateContract.php';
final class ProductionTemplateGeometryEvidenceTest extends TestCase {
 private function input():array {return ['template_id'=>'geometry-fixture','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>1,'provider_id'=>2,'variant_ids'=>[3],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'PHOTO_TEXT','print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1200,'height_px'=>1600]]];}
 public function testBleedSafeZoneAndPanelChangesProduceMaterialDrift():void {
  foreach(['bleed_metadata','safe_zone','panel_geometry'] as $field){$a=$this->input();$a['print_areas'][0][$field]=['x'=>10,'y'=>20,'width'=>100,'height'=>200];$b=$a;$b['print_areas'][0][$field]['x']=11;$first=Contract::normalize($a);$second=Contract::normalize($b);self::assertEquals($a['print_areas'][0][$field],$first['print_areas'][0][$field]);self::assertNotSame(Contract::materialFingerprint($first),Contract::materialFingerprint($second));}
 }
 public function testProviderMetadataObjectOrderDoesNotManufactureDrift():void {
  $a=$this->input();$a['print_areas'][0]['panels']=[['x'=>1,'y'=>2],['x'=>3,'y'=>4]];$b=$a;$b['print_areas'][0]['panels']=[['y'=>2,'x'=>1],['y'=>4,'x'=>3]];self::assertSame(Contract::normalize($a)['fingerprint'],Contract::normalize($b)['fingerprint']);$b['print_areas'][0]['panels']=array_reverse($b['print_areas'][0]['panels']);self::assertNotSame(Contract::normalize($a)['fingerprint'],Contract::normalize($b)['fingerprint']);
 }
 public function testNonFiniteGeometryEvidenceFailsClosed():void {$a=$this->input();$a['print_areas'][0]['bleed']=['width'=>INF];$this->expectException(InvalidArgumentException::class);Contract::normalize($a);}
 public function testLegacyTemplateWithoutSupplementalGeometryRetainsExactShape():void {self::assertSame($this->input()['print_areas'],Contract::normalize($this->input())['print_areas']);}
}
